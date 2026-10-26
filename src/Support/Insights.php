<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Models\Run;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Tenant scoping for the insights widgets is decided here and nowhere else. */
class Insights
{
    /** @var array<string, string>|null */
    protected static ?array $tenantLabelCache = null;

    /** @var array<string, string>|null */
    protected static ?array $userLabelCache = null;

    /** Fails closed: tenancy on but no tenant resolved returns nothing, not everything. */
    public static function runs(): Builder
    {
        $query = Run::query();

        try {
            $panel = Filament::getCurrentPanel();
        } catch (Throwable) {
            $panel = null;
        }

        if (! $panel?->hasTenancy()) {
            return $query;
        }

        $tenant = Filament::getTenant()?->getKey();

        return $tenant === null
            ? $query->whereRaw('1 = 0')
            : $query->where('tenant_id', (string) $tenant);
    }

    /** Excludes denied runs: they cost nothing and would flatter the failure rate. */
    public static function spent(): Builder
    {
        return static::runs()->where('denied', false);
    }

    public static function isTenantScoped(): bool
    {
        try {
            return (bool) Filament::getCurrentPanel()?->hasTenancy();
        } catch (Throwable) {
            return false;
        }
    }

    /** Install-wide (any panel has tenancy), unlike isTenantScoped(); `sidekick.tenancy.multi_tenant` overrides. */
    public static function isMultiTenant(): bool
    {
        $configured = config('sidekick.tenancy.multi_tenant');

        if (is_bool($configured)) {
            return $configured;
        }

        try {
            foreach (Filament::getPanels() as $panel) {
                if ($panel->hasTenancy()) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    public static function spansTenants(): bool
    {
        return static::isMultiTenant() && ! static::isTenantScoped();
    }

    /** Prime with the ids on screen first to batch the lookups. */
    public static function tenantLabel(int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return __('sidekick::messages.insights.no_tenant');
        }

        static::primeTenantLabels([$id]);

        return static::$tenantLabelCache[(string) $id] ?? (string) $id;
    }

    public static function userLabel(int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return '—';
        }

        static::primeUserLabels([$id]);

        return static::$userLabelCache[(string) $id] ?? (string) $id;
    }

    /**
     * @param  iterable<int, mixed>  $ids
     */
    public static function primeTenantLabels(iterable $ids): void
    {
        static::$tenantLabelCache = static::prime(
            static::$tenantLabelCache,
            $ids,
            static fn (array $missing): array => static::tenantLabels($missing),
        );
    }

    /** @param  iterable<int, mixed>  $ids */
    public static function primeUserLabels(iterable $ids): void
    {
        static::$userLabelCache = static::prime(
            static::$userLabelCache,
            $ids,
            static fn (array $missing): array => static::userLabels($missing),
        );
    }

    /**
     * Misses are cached too, so an id with no row isn't re-queried for every row that mentions it.
     *
     * @param  array<string, string>|null  $cache
     * @param  iterable<int, mixed>  $ids
     * @param  callable(array<int, string>): array<string, string>  $resolve
     * @return array<string, string>
     */
    protected static function prime(?array $cache, iterable $ids, callable $resolve): array
    {
        $cache ??= [];

        $missing = collect($ids)
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->reject(fn (string $id): bool => array_key_exists($id, $cache))
            ->values()
            ->all();

        if ($missing === []) {
            return $cache;
        }

        $resolved = $resolve($missing);

        foreach ($missing as $id) {
            $cache[$id] = $resolved[$id] ?? $id;
        }

        return $cache;
    }

    /** For tests that change the underlying data. */
    public static function forgetLabels(): void
    {
        static::$tenantLabelCache = null;
        static::$userLabelCache = null;
    }

    /** Wrapped in a subquery by TenantUsage: Filament's sort tiebreaker breaks GROUP BY under only_full_group_by. */
    public static function perTenantQuery(): Builder
    {
        return static::spent()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw("COALESCE(tenant_id, '') as id, tenant_id")
            ->selectRaw('COUNT(*) as turns, COALESCE(SUM(tokens), 0) as tokens, COUNT(DISTINCT user_id) as people')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed', [Run::STATUS_FAILED])
            ->groupBy('tenant_id');
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    public static function tenantLabels(array $ids): array
    {
        return static::labels(
            static::tenantModel(),
            (string) config('sidekick.insights.tenant_label_attribute', 'name'),
            $ids,
        );
    }

    /** Borrowed from any tenant panel; `insights.tenant_model` wins for runs with a tenant but no tenancy panel. */
    public static function tenantModel(): ?string
    {
        $configured = config('sidekick.insights.tenant_model');

        if ($configured !== null) {
            return $configured;
        }

        try {
            foreach (Filament::getPanels() as $panel) {
                if ($panel->hasTenancy() && ($model = $panel->getTenantModel()) !== null) {
                    return $model;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    public static function userLabels(array $ids): array
    {
        $model = config('sidekick.insights.user_model');

        if ($model === null) {
            try {
                $model = PanelContext::userModel(Filament::getCurrentPanel()?->getAuthGuard());
            } catch (Throwable) {
                return [];
            }
        }

        return static::labels($model, (string) config('sidekick.insights.user_label_attribute', 'name'), $ids);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    protected static function labels(?string $model, string $attribute, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id): bool => $id !== null && $id !== '')));

        if ($model === null || $ids === [] || ! class_exists($model)) {
            return [];
        }

        try {
            // Hydrated, not plucked: the label may be an accessor rather than a real column.
            return $model::query()
                ->whereKey($ids)
                ->get()
                ->mapWithKeys(fn (Model $record): array => [
                    (string) $record->getKey() => static::labelOf($record, $attribute),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    protected static function labelOf(Model $record, string $attribute): string
    {
        $label = rescue(fn () => $record->getAttribute($attribute), null, report: false);

        if (is_scalar($label) && trim((string) $label) !== '') {
            return (string) $label;
        }

        if ($record instanceof HasName) {
            return $record->getFilamentName();
        }

        return (string) $record->getKey();
    }

    public static function daily(int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = static::spent()
            ->where('created_at', '>=', $from)
            ->get(['created_at', 'tokens'])
            ->groupBy(fn (Run $run): string => $run->created_at->toDateString());

        $labels = [];
        $turns = [];
        $tokens = [];

        for ($day = $from->copy(); $day <= now()->startOfDay(); $day->addDay()) {
            $key = $day->toDateString();
            $onDay = $rows->get($key);

            $labels[] = $day->format('j M');
            $turns[] = $onDay?->count() ?? 0;
            $tokens[] = (int) ($onDay?->sum('tokens') ?? 0);
        }

        return ['labels' => $labels, 'turns' => $turns, 'tokens' => $tokens];
    }
}
