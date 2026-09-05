<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Models\Run;
use Filament\Facades\Filament;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Shared query surface for the insights widgets, so tenant scoping is decided in exactly one place. */
class Insights
{
    /** @var array<string, string>|null */
    protected static ?array $tenantLabelCache = null;

    /** @var array<string, string>|null */
    protected static ?array $userLabelCache = null;

    /**
     * Runs the current viewer is allowed to see.
     *
     * On a tenant panel this is that tenant's runs and nothing else. If tenancy is on but no tenant resolved,
     * the query returns nothing rather than everything — an insights page is the last place a scoping gap
     * should fail open.
     */
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

    /** Runs that actually reached a provider — denied ones cost nothing and would flatter the failure rate. */
    public static function spent(): Builder
    {
        return static::runs()->where('denied', false);
    }

    /** Whether the current panel narrows to one tenant. When it does not, totals are cross-tenant and say so. */
    public static function isTenantScoped(): bool
    {
        try {
            return (bool) Filament::getCurrentPanel()?->hasTenancy();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether this installation serves more than one tenant at all — a different question from whether the
     * *current* panel narrows to one, and the reason both have to be asked.
     *
     * Detected from the panels themselves: any panel with tenancy means the app is multi-tenant. That is
     * right for a single-tenant install (nothing has tenancy) and for a console sitting beside a workspace
     * panel (the workspace does). Set `sidekick.tenancy.multi_tenant` to true or false to decide it yourself.
     */
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

    /**
     * Whether this page's numbers cover more than one tenant, and should therefore be broken down by tenant.
     *
     * True only on a console-style panel of a multi-tenant install. A single-tenant app has nothing to break
     * down, and a tenant panel is already one tenant — both would get a single meaningless row.
     */
    public static function spansTenants(): bool
    {
        return static::isMultiTenant() && ! static::isTenantScoped();
    }

    /** One tenant's display name. Prime with the ids on screen first; a lone id still resolves on its own. */
    public static function tenantLabel(int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return __('sidekick::messages.insights.no_tenant');
        }

        static::primeTenantLabels([$id]);

        return static::$tenantLabelCache[(string) $id] ?? (string) $id;
    }

    /** One user's display name, resolved the same way. */
    public static function userLabel(int|string|null $id): string
    {
        if ($id === null || $id === '') {
            return '—';
        }

        static::primeUserLabels([$id]);

        return static::$userLabelCache[(string) $id] ?? (string) $id;
    }

    /**
     * Resolve a page's worth of ids in one query.
     *
     * Called by the table columns with the ids actually on screen. Resolving from every run ever recorded
     * would be one query too, but an unboundedly large one — the point is to batch the visible rows, not the
     * whole table.
     *
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
     * Look up whatever is not cached yet, and remember the misses too — an id with no matching row would
     * otherwise be re-queried for every row that mentions it.
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

    /** Cleared between requests by the container; exposed for tests that change the underlying data. */
    public static function forgetLabels(): void
    {
        static::$tenantLabelCache = null;
        static::$userLabelCache = null;
    }

    /**
     * The per-tenant aggregate for the current month, as a query. TenantUsage wraps it in a subquery, since
     * Filament's sort tiebreaker cannot be applied on top of a GROUP BY under MySQL's only_full_group_by.
     *
     * `tenant_id as id` gives each row a key; coalescing keeps the tenantless bucket addressable.
     */
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
     * Tenant ids resolved to names, so a console reads as customers rather than integers. Needs
     * `insights.tenant_model` — the package cannot know the host's tenant model, and falls back to the id.
     *
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

    /**
     * The tenant model, discovered rather than configured wherever possible: a tenant panel already declares
     * one, so a console beside it can borrow that instead of making you repeat it. `insights.tenant_model`
     * wins when set, for the case where no panel in this app has tenancy but the runs still carry a tenant.
     */
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
     * User ids resolved to names. Unlike tenants the model is discoverable — it is the panel guard's own —
     * so this needs no configuration beyond the attribute to read.
     *
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
     * One batched lookup of key => label. Always batched: these feed table columns, and resolving per row
     * would turn a page of runs into a page of queries.
     *
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
            // Hydrated rather than plucked: `name` is an attribute, not necessarily a column. Plenty of user
            // models compute it from first_name/last_name, and asking SQL for a column that does not exist
            // fails the whole lookup. Reading it off the model runs accessors and casts as well.
            return $model::query()
                ->whereKey($ids)
                ->get()
                ->mapWithKeys(fn (Model $record): array => [
                    (string) $record->getKey() => static::labelOf($record, $attribute),
                ])
                ->all();
        } catch (Throwable) {
            // A missing attribute should degrade to ids, not blank the page.
            return [];
        }
    }

    /** The configured attribute, then Filament's own name contract, then the key — first one that says something. */
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

    /** Turns and tokens per day for the last $days days, zero-filled so the chart has no gaps. */
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
