<?php

namespace Devletes\Sidekick\Support;

use Devletes\Sidekick\Models\Run;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Shared query surface for the insights widgets, so tenant scoping is decided in exactly one place. */
class Insights
{
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
