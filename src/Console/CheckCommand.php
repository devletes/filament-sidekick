<?php

namespace Devletes\Sidekick\Console;

use Devletes\Sidekick\Contracts\ActionHandler;
use Devletes\Sidekick\Contracts\ChatTool;
use Devletes\Sidekick\Support\SidekickManager;
use Illuminate\Console\Command;
use ReflectionClass;
use Throwable;

/**
 * Answers two questions about the assistant's capability surface: what is broken right now, and what would
 * break if a given class went away. Declared dependsOn() entries are checked, and so are the file's own
 * imports — so a deleted resource is reported even in tools that never declared anything.
 */
class CheckCommand extends Command
{
    protected $signature = 'sidekick:check
        {--uses= : List the tools and actions that reference this class, instead of checking for breakage}';

    protected $description = 'Report tools and actions with missing dependencies, or find what depends on a class';

    public function handle(SidekickManager $manager): int
    {
        $handlers = $this->handlers($manager);

        if ($handlers === []) {
            $this->components->warn('No tools or actions found. Check sidekick.discover.paths, or register classes in sidekick.tools / sidekick.actions.');

            return self::SUCCESS;
        }

        return $this->option('uses')
            ? $this->reportDependents($handlers, ltrim((string) $this->option('uses'), '\\'))
            : $this->reportBreakage($handlers);
    }

    /**
     * Every registered class, instantiated where possible. Uses the raw class lists rather than the instance
     * accessors, which deliberately withhold anything already broken — the broken ones are the point here.
     *
     * @return array<string, ChatTool|ActionHandler|null>
     */
    protected function handlers(SidekickManager $manager): array
    {
        $handlers = [];

        foreach ([...$manager->toolClasses(), ...$manager->actionClasses()] as $class) {
            try {
                $handlers[$class] = app($class);
            } catch (Throwable) {
                // Keep it in the list — an uninstantiable class is itself a finding.
                $handlers[$class] = null;
            }
        }

        return $handlers;
    }

    /** @param  array<string, ChatTool|ActionHandler|null>  $handlers */
    protected function reportBreakage(array $handlers): int
    {
        $problems = [];

        foreach ($handlers as $class => $handler) {
            if ($handler === null) {
                $problems[$class][] = ['(the class itself)', 'could not be instantiated'];

                continue;
            }

            $declared = SidekickManager::missingDependencies($handler);

            foreach ($declared as $missing) {
                $problems[$class][] = [$missing, 'declared in dependsOn()'];
            }

            // A declared dependency is usually imported too; report it once, under the declaration that gates it.
            foreach (array_diff($this->missingImports($class), $declared) as $missing) {
                $problems[$class][] = [$missing, 'imported but not declared'];
            }
        }

        $checked = count($handlers);

        if ($problems === []) {
            $this->components->info("Checked {$checked} tools and actions. No missing dependencies.");

            return self::SUCCESS;
        }

        // Printed as lines rather than a table: fully qualified class names are long, and a table truncates
        // exactly the part you need to act on.
        $this->newLine();

        $total = 0;

        foreach ($problems as $class => $entries) {
            $this->line("  <options=bold>{$class}</>");

            foreach ($entries as [$missing, $via]) {
                $total++;
                $this->line("    <fg=red>missing</> {$missing} <fg=gray>({$via})</>");
            }

            $this->newLine();
        }

        $this->components->error($total.' missing '.($total === 1 ? 'dependency' : 'dependencies')." across {$checked} tools and actions.");
        $this->components->bulletList([
            'Declared: already withheld from the assistant, so chat still works without it.',
            'Imported: will fatal when the tool runs. Fix or delete the file.',
        ]);

        return self::FAILURE;
    }

    /** @param  array<string, ChatTool|ActionHandler|null>  $handlers */
    protected function reportDependents(array $handlers, string $target): int
    {
        $dependents = [];

        foreach ($handlers as $class => $handler) {
            $declared = $handler !== null && in_array($target, array_map(
                fn (string $dependency): string => ltrim($dependency, '\\'),
                $handler->dependsOn(),
            ), true);

            $imported = in_array($target, $this->imports($class), true);

            if ($declared || $imported) {
                $dependents[$class] = $declared ? 'dependsOn()' : 'import';
            }
        }

        if ($dependents === []) {
            $this->components->info("Nothing depends on {$target}.");

            return self::SUCCESS;
        }

        $count = count($dependents);

        $this->newLine();
        $this->line('  <options=bold>'.$count.' '.($count === 1 ? 'file depends' : 'files depend')." on {$target}</>");
        $this->newLine();

        foreach ($dependents as $class => $via) {
            $this->line("    {$class} <fg=gray>({$via})</>");
        }

        $this->newLine();
        $this->components->warn('Update or remove '.($count === 1 ? 'it' : 'these').' before deleting that class.');

        return self::SUCCESS;
    }

    /**
     * Class-likes the file imports that no longer exist.
     *
     * @return array<int, string>
     */
    protected function missingImports(string $class): array
    {
        return array_values(array_filter(
            $this->imports($class),
            fn (string $import): bool => ! class_exists($import)
                && ! interface_exists($import)
                && ! trait_exists($import)
                && ! enum_exists($import),
        ));
    }

    /**
     * Top-level `use` imports, which is where a tool's real coupling shows up whether or not anyone declared it.
     * Grouped imports are skipped rather than half-parsed, and `use function` / `use const` are not class-likes.
     *
     * @return array<int, string>
     */
    protected function imports(string $class): array
    {
        try {
            $file = (new ReflectionClass($class))->getFileName();
        } catch (Throwable) {
            return [];
        }

        if ($file === false || ! is_file($file)) {
            return [];
        }

        preg_match_all(
            '/^use\s+(?!function\s|const\s)([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*)(?:\s+as\s+\w+)?\s*;/m',
            (string) file_get_contents($file),
            $matches,
        );

        return array_values(array_unique(array_map(
            fn (string $import): string => ltrim($import, '\\'),
            $matches[1] ?? [],
        )));
    }
}
