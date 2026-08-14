<?php

use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Facades\Sidekick;
use Devletes\Sidekick\Support\SidekickManager;
use Devletes\Sidekick\Support\ToolRegistry;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\FixedResolver;
use Devletes\Sidekick\Tests\Fixtures\Tools\EchoTool;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Devletes\Sidekick\Tools\Navigate;
use Devletes\Sidekick\Tools\PresentActions;
use Laravel\Ai\Tools\ToolNameResolver;

it('discovers tools and actions from the configured paths, skipping non-contract classes', function () {
    config()->set('sidekick.discover.tools', __DIR__.'/../Fixtures/Tools');
    config()->set('sidekick.discover.actions', __DIR__.'/../Fixtures/Actions');

    $manager = app(SidekickManager::class);

    expect($manager->toolClasses())->toContain(EchoTool::class)
        ->not->toContain(\Devletes\Sidekick\Tests\Fixtures\Tools\NotATool::class)
        ->and($manager->actionClasses())->toContain(CreateNote::class);
});

it('honours the discovery kill switch', function () {
    config()->set('sidekick.discover.enabled', false);
    config()->set('sidekick.discover.tools', __DIR__.'/../Fixtures/Tools');

    expect(app(SidekickManager::class)->toolClasses())->toBe([]);
});

it('merges config tools with runtime registrations, deduplicated', function () {
    config()->set('sidekick.tools', [EchoTool::class]);
    Sidekick::tools([EchoTool::class]);

    expect(Sidekick::toolClasses())->toBe([EchoTool::class]);
});

it('exposes each proposable action as a proposal tool', function () {
    config()->set('sidekick.actions', [CreateNote::class]);

    $tools = app(SidekickManager::class)->toolInstances();
    $proposal = collect($tools)->first(fn ($tool) => $tool instanceof ActionProposalTool);

    expect($proposal)->not->toBeNull()
        ->and(ToolNameResolver::resolve($proposal))->toBe('ProposeCreateNote')
        ->and((string) $proposal->description())->toContain('Creates a note')
        ->and($proposal->description())->toContain('PROPOSES');
});

it('keeps the built-in tools dormant without a resolver and wakes them with one', function () {
    $before = app(SidekickManager::class)->toolInstances();

    expect(collect($before)->first(fn ($tool) => $tool instanceof Navigate))->toBeNull();

    app()->singleton(ActionResolver::class, FixedResolver::class);

    $after = app(SidekickManager::class)->toolInstances();

    expect(collect($after)->first(fn ($tool) => $tool instanceof Navigate))->not->toBeNull()
        ->and(collect($after)->first(fn ($tool) => $tool instanceof PresentActions))->not->toBeNull();
});

it('lets config toggles force built-ins off', function () {
    app()->singleton(ActionResolver::class, FixedResolver::class);
    config()->set('sidekick.builtin_tools.navigate', false);

    $tools = app(SidekickManager::class)->toolInstances();

    expect(collect($tools)->first(fn ($tool) => $tool instanceof Navigate))->toBeNull()
        ->and(collect($tools)->first(fn ($tool) => $tool instanceof PresentActions))->not->toBeNull();
});

it('resolves action handlers by type through the manager', function () {
    config()->set('sidekick.actions', [CreateNote::class]);

    expect(Sidekick::actionHandler('create_note'))->toBeInstanceOf(CreateNote::class)
        ->and(Sidekick::actionHandler('missing'))->toBeNull();
});

it('filters unauthorized tools out per user via the registry', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $tools = app(ToolRegistry::class)->authorizedFor(FakeUser::make());

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(EchoTool::class);
});

it('resolves status labels by model-facing tool name', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    expect(app(ToolRegistry::class)->labelFor('EchoTool'))->toBe('Using: Echo Tool')
        ->and(app(ToolRegistry::class)->labelFor('Nope'))->toBeNull();
});
