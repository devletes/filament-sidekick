<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Contracts\ActionHandler;
use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Contracts\ChatTool;
use Devletes\Sidekick\Contracts\ProposableAction;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Devletes\Sidekick\Tools\Navigate;
use Devletes\Sidekick\Tools\PresentActions;
use Illuminate\Support\Str;

/** Single source of truth for tools and actions; class lists are recomputed per call because config is profile-swapped mid-process. */
class SidekickManager
{
    /** @var array<int, class-string<ChatTool>> */
    protected array $runtimeTools = [];

    /** @var array<int, class-string<ActionHandler>> */
    protected array $runtimeActions = [];

    /** @var array<string, array<int, string>> Discovery scan cache, keyed by path. */
    protected array $discovered = [];

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
            ...$this->discoveredClasses('tools', ChatTool::class),
            ...$this->runtimeTools,
        ]));
    }

    /** @return array<int, class-string<ActionHandler>> */
    public function actionClasses(): array
    {
        return array_values(array_unique([
            ...array_values((array) config('sidekick.actions', [])),
            ...$this->discoveredClasses('actions', ActionHandler::class),
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
        $tools = array_map(fn (string $class): ChatTool => app($class), $this->toolClasses());

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
        return array_map(fn (string $class): ActionHandler => app($class), $this->actionClasses());
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
     * Classes under the discovery path; anything not implementing the contract is skipped, so helpers can live alongside.
     *
     * @param  class-string  $contract
     * @return array<int, string>
     */
    protected function discoveredClasses(string $kind, string $contract): array
    {
        if (! config('sidekick.discover.enabled', true)) {
            return [];
        }

        $path = config("sidekick.discover.{$kind}")
            ?? app_path('Sidekick/'.Str::studly($kind));

        $classes = $this->discovered[$path] ??= $this->scan($path);

        return array_values(array_filter(
            $classes,
            fn (string $class): bool => is_subclass_of($class, $contract),
        ));
    }

    /** @return array<int, string> Instantiable classes declared by the PHP files in $path. */
    protected function scan(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $classes = [];

        foreach (glob($path.'/*.php') ?: [] as $file) {
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
        $contents = (string) file_get_contents($file);

        if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace)) {
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
    }
}
