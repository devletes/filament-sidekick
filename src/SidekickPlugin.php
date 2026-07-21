<?php

namespace Devletes\Sidekick;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

class SidekickPlugin implements Plugin
{
    protected ?string $profile = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /** Run this panel's assistant under a named `sidekick.profiles.*` entry. */
    public function profile(?string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    public function getId(): string
    {
        return 'devletes-sidekick';
    }

    public function register(Panel $panel): void
    {
        if (! config('sidekick.enabled')) {
            return;
        }

        $panel
            ->renderHook(
                PanelsRenderHook::USER_MENU_AFTER,
                fn (): string => view('sidekick::toggle-button')->render(),
            )
            ->renderHook(
                PanelsRenderHook::LAYOUT_END,
                fn (): string => view('sidekick::panel')->render(),
            );
    }

    public function boot(Panel $panel): void
    {
        // boot() runs only for the panel actually serving the request
        // (register() runs for every panel), so this is the profile switch.
        app(\Devletes\Sidekick\Support\Profiles::class)->apply($this->profile);
    }
}
