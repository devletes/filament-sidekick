<?php

namespace Devletes\Sidekick;

use Closure;
use Devletes\Sidekick\Support\Profiles;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;

class SidekickPlugin implements Plugin
{
    protected ?string $profile = null;

    protected string|Htmlable|Closure|null $icon = null;

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

    /** The toggle button's icon: a registered icon name, or raw SVG markup (string, Htmlable, or view). */
    public function icon(string|Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): string|Htmlable|null
    {
        $icon = $this->icon instanceof Closure ? ($this->icon)() : $this->icon;

        return $icon ?? config('sidekick.icons.assistant', 'heroicon-o-sparkles');
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
                fn (): string => view('sidekick::toggle-button', ['icon' => $this->getIcon()])->render(),
            )
            ->renderHook(
                PanelsRenderHook::LAYOUT_END,
                fn (): string => view('sidekick::panel')->render(),
            );
    }

    public function boot(Panel $panel): void
    {
        // boot() runs only for the panel serving the request; register() runs for every panel.
        app(Profiles::class)->apply($this->profile);
    }
}
