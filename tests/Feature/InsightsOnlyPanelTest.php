<?php

use Devletes\Sidekick\Pages\SidekickInsights;
use Devletes\Sidekick\SidekickPlugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

/**
 * The render hooks a panel has been given. Read by reflection rather than rendered: a panel only pushes them
 * into FilamentView when it boots, so rendering proves nothing here — and both chat views are @auth-gated,
 * which would make an empty render look like success either way.
 *
 * @return array<int, string>
 */
function registeredHookNames(Panel $panel): array
{
    $hooks = (new ReflectionProperty(Panel::class, 'renderHooks'))->getValue($panel);

    return array_keys($hooks);
}

function freshPanel(): Panel
{
    registerFilamentPanels(app());

    return Filament::getPanel('testing');
}

it('mounts the chat panel by default', function () {
    $panel = freshPanel();

    SidekickPlugin::make()->register($panel);

    expect(registeredHookNames($panel))
        ->toContain(PanelsRenderHook::USER_MENU_AFTER)
        ->toContain(PanelsRenderHook::LAYOUT_END);
});

it('registers no chat panel at all under withoutChat', function () {
    $panel = freshPanel();

    SidekickPlugin::make()->withoutChat()->register($panel);

    // Nothing mounted means nothing for a tenant-scoped SidekickContext to fail to scope.
    expect(registeredHookNames($panel))
        ->not->toContain(PanelsRenderHook::USER_MENU_AFTER)
        ->not->toContain(PanelsRenderHook::LAYOUT_END);
});

it('still registers the insights page under withoutChat', function () {
    config()->set('sidekick.insights.enabled', true);
    $panel = freshPanel();

    SidekickPlugin::make()->enableInsights()->withoutChat()->register($panel);

    expect($panel->getPages())->toContain(SidekickInsights::class)
        ->and(registeredHookNames($panel))->not->toContain(PanelsRenderHook::LAYOUT_END);
});

it('registers nothing when the package is switched off entirely', function () {
    config()->set('sidekick.enabled', false);
    config()->set('sidekick.insights.enabled', true);
    $panel = freshPanel();

    SidekickPlugin::make()->enableInsights()->register($panel);

    expect($panel->getPages())->not->toContain(SidekickInsights::class)
        ->and(registeredHookNames($panel))->not->toContain(PanelsRenderHook::LAYOUT_END);
});
