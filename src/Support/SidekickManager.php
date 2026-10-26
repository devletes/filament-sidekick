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

/** Class lists are recomputed per call because config is profile-swapped mid-process. */
class SidekickManager
{
    /** @var array<int, class-string<ChatTool>> */
    protected array $runtimeTools = [];

    /** @var array<int, class-string<ActionHandler>> */
    protected array $runtimeActions = [];

    /** @var array<string, array<int, string>> */
    protected array $discovered = [];

    /** @var array<string, true> */
    protected array $withheld = [];

    /**
     * Works in queue workers, unlike per-panel plugin state.
     *
     * @param  array<int, class-string<ChatTool>>  $classes
     */
    public function tools(array $classes): static
    {
        $this->runtimeTools = array_values(array_unique([...$this->runtimeTools, ...$classes]));

        return $this;
    }

    /**
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
     * Pre-authorization set; filter via ToolRegistry::authorizedFor.
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

    /** Withheld rather than fataling mid-turn; logs once per class. */
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
     * Discovery autoloads what it finds, so roots should only hold Sidekick classes.
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

    /** @return array<int, string> */
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

    protected function classFromFile(string $file): ?string
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 8192);
        fclose($handle);

        if (! preg_match('/^namespace\s+([^;]+);/m', $head, $namespace)) {
            return null;
        }

        return trim($namespace[1]).'\\'.basename($file, '.php');
    }

    public function flush(): void
    {
        $this->runtimeTools = [];
        $this->runtimeActions = [];
        $this->discovered = [];
        $this->withheld = [];
    }
}
