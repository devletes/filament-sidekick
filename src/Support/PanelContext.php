<?php

namespace Devletes\Sidekick\Support;

use Filament\Facades\Filament;
use RuntimeException;
use Throwable;

/** Captures the dispatching request's Filament context (panel + tenant) and restores it inside the queue worker. */
class PanelContext
{
    /** @return array{panel: string|null, tenant: int|string|null, guard: string|null} */
    public static function capture(): array
    {
        try {
            $panel = Filament::getCurrentPanel();

            return [
                'panel' => $panel?->getId(),
                'tenant' => Filament::getTenant()?->getKey(),
                'guard' => $panel?->getAuthGuard(),
            ];
        } catch (Throwable) {
            return ['panel' => null, 'tenant' => null, 'guard' => null];
        }
    }

    /** The user model behind a panel's auth guard; falls back to the default provider. */
    public static function userModel(?string $guard): string
    {
        $provider = $guard ? config("auth.guards.{$guard}.provider") : null;

        return config("auth.providers.{$provider}.model")
            ?? config('auth.providers.users.model');
    }

    /** Always writes both panel and tenant — including clearing them — so a long-lived worker never inherits the previous job's tenant. */
    public static function apply(?string $panelId, int|string|null $tenantKey): void
    {
        // No Filament booted (console/test contexts): nothing to restore or clear.
        if (! app()->bound('filament')) {
            if ($tenantKey !== null) {
                throw new RuntimeException('Cannot restore tenant context: Filament is not available in this process.');
            }

            return;
        }

        $panel = null;

        if ($panelId !== null) {
            try {
                $panel = Filament::getPanel($panelId, isStrict: false);
            } catch (Throwable) {
                $panel = null;
            }

            // Refusing to run beats running a tenant-scoped turn unscoped.
            if (! $panel && $tenantKey !== null) {
                throw new RuntimeException("Cannot restore Filament panel [{$panelId}] for a tenant-scoped chat turn.");
            }
        }

        $tenant = null;

        if ($tenantKey !== null && $panel?->hasTenancy()) {
            $tenant = $panel->getTenantModel()::query()->find($tenantKey);

            if (! $tenant) {
                throw new RuntimeException("Cannot restore tenant [{$tenantKey}] for this chat turn.");
            }
        }

        Filament::setCurrentPanel($panel);
        Filament::setTenant($tenant, isQuiet: true);
    }
}
