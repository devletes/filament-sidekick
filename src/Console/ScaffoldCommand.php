<?php

namespace Devletes\Sidekick\Console;

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/** Generates baseline Sidekick classes from the host's Filament resources; existing files are never overwritten, so re-running is safe. */
#[AsCommand(name: 'sidekick:scaffold')]
class ScaffoldCommand extends Command
{
    protected $signature = 'sidekick:scaffold
        {--actions : Also scaffold a confirmable Create action stub per resource}
        {--dry-run : List what would be generated without writing anything}';

    protected $description = 'Scaffold baseline Sidekick tools (and optionally actions) from your Filament resources';

    public function handle(): int
    {
        $resources = $this->resources();

        if ($resources === []) {
            $this->components->warn('No Filament resources found (or all are ignored via sidekick.scaffold.ignore).');

            return self::SUCCESS;
        }

        $created = 0;

        foreach ($this->plan($resources) as [$path, $contents]) {
            $created += (int) $this->write($path, $contents);
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — nothing was written.');
        } elseif ($created > 0) {
            $this->components->info('Scaffolds land in app/Sidekick and are auto-discovered — review each TODO before shipping.');
            $this->components->bulletList(array_filter([
                "Wire navigation: 'action_resolver' => \\{$this->rootNamespace()}Sidekick\\ResourceResolver::class in config/sidekick.php",
                $this->option('actions')
                    ? 'Generated actions THROW until you finish execute() — intentional, so an unfinished scaffold cannot write.'
                    : 'Re-run with --actions to also scaffold confirmable Create actions.',
                'Re-runs skip existing files; list resources under sidekick.scaffold.ignore to exclude them entirely.',
            ]));
        }

        return self::SUCCESS;
    }

    /**
     * The sidekick.scaffold.only allowlist when set, otherwise every registered panel's resources; the ignore list applies to both.
     *
     * @return array<class-string, string|null> Resource class → panel id (null = default panel).
     */
    protected function resources(): array
    {
        $map = [];

        foreach ((array) config('sidekick.scaffold.only', []) as $key => $value) {
            [$resource, $panel] = is_string($key) ? [$key, $value] : [$value, null];
            $map[$resource] = $panel;
        }

        if ($map === []) {
            foreach (Filament::getPanels() as $panel) {
                foreach ($panel->getResources() as $resource) {
                    $map[$resource] ??= $panel->getId();
                }
            }
        }

        return array_diff_key($map, array_flip((array) config('sidekick.scaffold.ignore', [])));
    }

    /**
     * @param  array<class-string, string|null>  $resources
     * @return array<int, array{0: string, 1: string}> [path, contents] pairs.
     */
    protected function plan(array $resources): array
    {
        $files = [];

        foreach ($resources as $resource => $panelId) {
            $files[] = $this->searchTool($resource, $panelId);

            if ($this->option('actions')) {
                $files[] = $this->createAction($resource, $panelId);
            }
        }

        if (($resolver = $this->resolver($resources)) !== null) {
            $files[] = $resolver;
        }

        return $files;
    }

    /** @return array{0: string, 1: string} */
    protected function searchTool(string $resource, ?string $panelId): array
    {
        $plural = Str::pluralStudly(class_basename($resource::getModel()));
        $attributes = $this->searchableAttributes($resource);

        $contents = $this->stub('scaffold.tool', [
            '{{ namespace }}' => $this->rootNamespace().'Sidekick\Tools',
            '{{ class }}' => 'Search'.$plural,
            '{{ resource }}' => $resource,
            '{{ resourceBasename }}' => class_basename($resource),
            '{{ plural }}' => Str::lower(Str::headline($plural)),
            '{{ attributes }}' => "'".implode("', '", $attributes)."'",
            '{{ title }}' => $resource::getRecordTitleAttribute() ?? $attributes[0],
            '{{ panels }}' => $panelId === null ? "'*'" : "'{$panelId}'",
        ]);

        return [app_path('Sidekick/Tools/Search'.$plural.'.php'), $contents];
    }

    /** @return array{0: string, 1: string} */
    protected function createAction(string $resource, ?string $panelId): array
    {
        $model = class_basename($resource::getModel());
        $title = $resource::getRecordTitleAttribute() ?? $this->searchableAttributes($resource)[0];

        $contents = $this->stub('scaffold.action', [
            '{{ namespace }}' => $this->rootNamespace().'Sidekick\Actions',
            '{{ class }}' => 'Create'.$model,
            '{{ resource }}' => $resource,
            '{{ resourceBasename }}' => class_basename($resource),
            '{{ singular }}' => Str::lower(Str::headline($model)),
            '{{ titleAttribute }}' => $title,
            '{{ titleHuman }}' => Str::headline($title),
            '{{ panels }}' => $panelId === null ? "'*'" : "'{$panelId}'",
        ]);

        return [app_path('Sidekick/Actions/Create'.$model.'.php'), $contents];
    }

    /**
     * One resolver covering every scaffolded resource: an index target each, plus a `.record` target when a view/edit page exists.
     *
     * @param  array<class-string, string|null>  $resources
     * @return array{0: string, 1: string}|null
     */
    protected function resolver(array $resources): ?array
    {
        $targets = [];
        $cases = [];

        foreach ($resources as $resource => $panelId) {
            $target = Str::snake(Str::pluralStudly(class_basename($resource::getModel())));
            $panelArg = $panelId === null ? '' : ", panel: '{$panelId}'";
            $panelArg .= $this->tenantArg($panelId);

            if ($resource::hasPage('index')) {
                $targets[] = "            '{$target}',";
                $cases[] = "            '{$target}' => \\{$resource}::getUrl('index'{$panelArg}),";
            }

            $recordPage = $resource::hasPage('view') ? 'view' : ($resource::hasPage('edit') ? 'edit' : null);

            if ($recordPage !== null) {
                $targets[] = "            '{$target}.record',";
                $cases[] = "            '{$target}.record' => \$record === null ? null : \\{$resource}::getUrl('{$recordPage}', ['record' => \$record]{$panelArg}),";
            }
        }

        if ($targets === []) {
            return null;
        }

        $contents = $this->stub('scaffold.resolver', [
            '{{ namespace }}' => $this->rootNamespace().'Sidekick',
            '{{ class }}' => 'ResourceResolver',
            '{{ targets }}' => implode("\n", $targets),
            '{{ cases }}' => implode("\n", $cases),
        ]);

        return [app_path('Sidekick/ResourceResolver.php'), $contents];
    }

    /** A `tenant:` argument for generated getUrl calls when the panel is multi-tenant (PanelContext keeps the tenant live in queued turns). */
    protected function tenantArg(?string $panelId): string
    {
        try {
            $panel = $panelId === null ? null : Filament::getPanel($panelId, isStrict: false);
        } catch (\Throwable) {
            $panel = null;
        }

        return $panel?->hasTenancy()
            ? ', tenant: \Filament\Facades\Filament::getTenant()'
            : '';
    }

    /** @return array<int, string> Never empty — falls back to the title attribute, then `name`. */
    protected function searchableAttributes(string $resource): array
    {
        $attributes = $resource::getGloballySearchableAttributes();

        return $attributes !== []
            ? array_values($attributes)
            : (array_filter([$resource::getRecordTitleAttribute()]) ?: ['name']);
    }

    /** Writes unless the file exists or this is a dry run; reports either way. Returns whether it wrote. */
    protected function write(string $path, string $contents): bool
    {
        $relative = str_replace('\\', '/', Str::after($path, base_path().DIRECTORY_SEPARATOR));

        if (File::exists($path)) {
            $this->components->twoColumnDetail($relative, 'exists — skipped');

            return false;
        }

        if ($this->option('dry-run')) {
            $this->components->twoColumnDetail($relative, 'would create');

            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        $this->components->twoColumnDetail($relative, 'created');

        return true;
    }

    protected function stub(string $name, array $replacements): string
    {
        return strtr((string) file_get_contents(__DIR__."/../../stubs/{$name}.stub"), $replacements);
    }

    /** The host app's root namespace, e.g. `App\`. */
    protected function rootNamespace(): string
    {
        return $this->laravel->getNamespace();
    }
}
