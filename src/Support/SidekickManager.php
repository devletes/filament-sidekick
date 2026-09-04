<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ActionHandler;
use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Contracts\ChatTool;
use Devletes\Sidekick\Contracts\ProposableAction;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Devletes\Sidekick\Tools\Navigate;
use Devletes\Sidekick\Tools\PresentActions;
use Illuminate\Support\Facades\Log;

/** Single source of truth for tools and actions; class lists are recomputed per call because config is profile-swapped mid-process. */
class SidekickManager
{
    /** @var array<int, class-string<ChatTool>> */
    protected array $runtimeTools = [];

    /** @var array<int, class-string<ActionHandler>> */
    protected array $runtimeActions = [];

    /** @var array<string, array<int, string>> Discovery scan cache, keyed by root path. */
    protected array $discovered = [];

    /** @var array<string, true> Classes already logged as withheld, so one broken tool cannot flood the log. */
    protected array $withheld = [];

    /**
     * Register tool classes at runtime (works in queue workers, unlike per-panel plugin state).
     *
     * @param  array<int, class-string<ChatTool>>  $classes
     */
    public function tools(array $classes): static
    {
        $this->runtimeTools = array_values(array_unique([...$this->runtimeTools, ...$classes]));

        return $this;
    }

    /**
     * Register action classes at runtime.
     *
     * @param  array<int, class-string<ActionHandler>>  $classes
     */
    public function actions(array $classes): static
    {
        $this->runtimeActions = array_values(array_unique([...$this->runtimeActions, ...$classes]));

        return $this;
    }

    /** @return array<int, class-string<ChatTool>> */
    public function toolClasses(): array
    {
        return array_values(array_unique([
            ...array_values((array) config('sidekick.tools', [])),
            ...$this->discoveredClasses(ChatTool::class),
            ...$this->runtimeTools,
        ]));
    }

    /** @return array<int, class-string<ActionHandler>> */
    public function actionClasses(): array
    {
        return array_values(array_unique([
            ...array_values((array) config('sidekick.actions', [])),
            ...$this->discoveredClasses(ActionHandler::class),
            ...$this->runtimeActions,
        ]));
    }

    /**
     * Every tool instance that could be offered — the pre-authorization set; filter via ToolRegistry::authorizedFor.
     *
     * @return array<int, ChatTool>
     */
    public function toolInstances(): array
    {
        $tools = [];

        foreach ($this->toolClasses() as $class) {
            /** @var ChatTool $tool */
            $tool = app($class);

            if (! $this->isWithheld($tool)) {
                $tools[] = $tool;
            }
        }

        foreach ($this->actionInstances() as $action) {
            if ($action instanceof ProposableAction) {
                $tools[] = new ActionProposalTool($action);
            }
        }

        return [...$tools, ...$this->builtinTools()];
    }

    /** @return array<int, ActionHandler> */
    public function actionInstances(): array
    {
        $actions = [];

        foreach ($this->actionClasses() as $class) {
            /** @var ActionHandler $action */
            $action = app($class);

            if (! $this->isWithheld($action)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * Classes from dependsOn() that no longer exist. A tool that names what it needs can be withheld cleanly
     * instead of fataling mid-turn, and sidekick:check can report it before anyone chats.
     *
     * @return array<int, class-string>
     */
    public static function missingDependencies(ChatTool|ActionHandler $handler): array
    {
        return array_values(array_filter(
            $handler->dependsOn(),
            fn (string $dependency): bool => ! class_exists($dependency)
                && ! interface_exists($dependency)
                && ! enum_exists($dependency),
        ));
    }

    /** Withhold a handler whose dependencies are gone, logging once per class so the silence is never total. */
    protected function isWithheld(ChatTool|ActionHandler $handler): bool
    {
        $missing = static::missingDependencies($handler);

        if ($missing === []) {
            return false;
        }

        $class = $handler::class;

        if (! isset($this->withheld[$class])) {
            $this->withheld[$class] = true;

            Log::warning("Sidekick withheld [{$class}]: missing ".implode(', ', $missing).'. Run `php artisan sidekick:check`.');
        }

        return true;
    }

    /** The registered handler for an action type, if any. */
    public function actionHandler(string $type): ?ActionHandler
    {
        foreach ($this->actionInstances() as $handler) {
            if ($handler->type() === $type) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Built-ins wake once an ActionResolver with targets is bound; each can be forced off via sidekick.builtin_tools.
     *
     * @return array<int, ChatTool>
     */
    protected function builtinTools(): array
    {
        $tools = [];
        $resolver = app(ActionResolver::class);
        $hasTargets = $resolver->targets() !== [];

        if ($hasTargets && config('sidekick.builtin_tools.navigate', true)) {
            $tools[] = new Navigate($resolver);
        }

        if ($hasTargets && config('sidekick.builtin_tools.present_actions', true)) {
            $tools[] = new PresentActions($resolver);
        }

        return $tools;
    }

    /**
     * Classes under the discovery roots; anything not implementing the contract is skipped, so helpers and
     * sub-namespaces can live alongside. Roots are scanned recursively — organise by domain or by kind freely.
     *
     * @param  class-string  $contract
     * @return array<int, string>
     */
    protected function discoveredClasses(string $contract): array
    {
        if (! config('sidekick.discover.enabled', true)) {
            return [];
        }

        $classes = [];

        foreach ($this->discoveryPaths() as $path) {
            $classes = [...$classes, ...$this->discovered[$path] ??= $this->scan($path)];
        }

        return array_values(array_filter(
            array_unique($classes),
            fn (string $class): bool => is_subclass_of($class, $contract),
        ));
    }

    /**
     * Discovery roots, normalised and deduplicated. Each should be a tree that only holds Sidekick classes —
     * discovery autoloads what it finds, so pointing a root at a shared tree like app/Filament costs real boot time.
     *
     * @return array<int, string>
     */
    protected function discoveryPaths(): array
    {
        $paths = config('sidekick.discover.paths') ?? app_path('Sidekick');

        return array_values(array_unique(array_filter(array_map(
            fn ($path): string => rtrim((string) $path, '/\\'),
            (array) $paths,
        ))));
    }

    /** @return array<int, string> Instantiable classes declared anywhere beneath $path. */
    protected function scan(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        // Filesystem order varies by platform; sorting keeps the registry (and so the system prompt) stable.
        sort($files);

        $classes = [];

        foreach ($files as $file) {
            $class = $this->classFromFile($file);

            if ($class !== null && class_exists($class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** Resolve a file to its FQCN by reading the namespace declaration — no assumptions about the host's root namespace. */
    protected function classFromFile(string $file): ?string
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        // The declaration is always in the opening lines, so never read a whole file to find it.
        $head = (string) fread($handle, 8192);
        fclose($handle);

        if (! preg_match('/^namespace\s+([^;]+);/m', $head, $namespace)) {
            return null;
        }

        return trim($namespace[1]).'\\'.basename($file, '.php');
    }

    /** Reset runtime registrations and discovery caches (tests). */
    public function flush(): void
    {
        $this->runtimeTools = [];
        $this->runtimeActions = [];
        $this->discovered = [];
        $this->withheld = [];
    }
}
