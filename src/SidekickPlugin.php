<?php

namespace Devletes\Sidekick;

use Closure;
use Devletes\Sidekick\Pages\SidekickInsights;
use Devletes\Sidekick\Support\Profiles;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

class SidekickPlugin implements Plugin
{
    protected ?string $profile = null;

    protected ?bool $history = null;

    protected bool $chat = true;

    protected ?bool $insights = null;

    protected ?Closure $insightsAuthorization = null;

    /** @var class-string<SidekickInsights>|null */
    protected ?string $insightsPage = null;

    protected string|Htmlable|Closure|null $icon = null;

    public static function make(): static
    {
        return app(static::class);
    }

    /** Null outside a panel (queue workers, console). */
    public static function get(): ?static
    {
        try {
            $plugin = Filament::getCurrentPanel()?->getPlugin('devletes-sidekick');
        } catch (Throwable) {
            return null;
        }

        return $plugin instanceof static ? $plugin : null;
    }

    /** Registers everything except the chat itself; use it for panels with no tenant to scope conversations to. */
    public function withoutChat(): static
    {
        $this->chat = false;

        return $this;
    }

    /** Without an authorization closure the page is visible to anyone who can reach the panel. */
    public function enableInsights(bool|Closure $condition = true): static
    {
        if ($condition instanceof Closure) {
            $this->insights = true;
            $this->insightsAuthorization = $condition;

            return $this;
        }

        $this->insights = $condition;

        return $this;
    }

    public function getInsightsAuthorization(): ?Closure
    {
        return $this->insightsAuthorization;
    }

    /** @param  class-string<SidekickInsights>|null  $page */
    public function insightsPage(?string $page): static
    {
        $this->insightsPage = $page;

        return $this;
    }

    /** @return class-string<SidekickInsights> */
    public function getInsightsPage(): string
    {
        return $this->insightsPage ?? SidekickInsights::class;
    }

    /** Run this panel's assistant under a named `sidekick.profiles.*` entry. */
    public function profile(?string $profile): static
    {
        $this->profile = $profile;

        return $this;
    }

    /** Overrides `sidekick.history.enabled` for this panel in either direction. */
    public function enableHistory(bool $condition = true): static
    {
        $this->history = $condition;

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

        // Registered whenever the panel asked for it; canAccess() still gates who may open it.
        if ($this->insights ?? config('sidekick.insights.enabled', false)) {
            $panel->pages([$this->getInsightsPage()]);
        }

        if (! $this->chat) {
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

        // After the profile, so an explicit per-panel call wins over whatever config the profile brought in.
        if ($this->history !== null) {
            config(['sidekick.history.enabled' => $this->history]);
        }

        if ($this->insights !== null) {
            config(['sidekick.insights.enabled' => $this->insights]);
        }
    }
}
