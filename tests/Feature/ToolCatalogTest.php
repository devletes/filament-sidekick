<?php

use Devletes\Sidekick\Contracts\ActionResolver;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\ToolRegistry;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\FixedResolver;
use Devletes\Sidekick\Tests\Fixtures\Tools\AdvisedTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\EchoTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\SecretTool;
use Devletes\Sidekick\Tools\ListTools;
use Devletes\Sidekick\Tools\Navigate;
use Devletes\Sidekick\Tools\PresentActions;
use Devletes\Sidekick\Tools\RunTool;
use Laravel\Ai\Tools\Request;

function offeredNames(?object $user = null): array
{
    return array_map(
        fn ($tool) => class_basename($tool),
        app(ToolRegistry::class)->offeredTo($user ?? FakeUser::make()),
    );
}

it('hands the model every tool directly while the catalog is off', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    expect(offeredNames())->toBe(['EchoTool']);
});

it('swaps the tool list for the catalog pair once enabled', function () {
    config()->set('sidekick.tools', [EchoTool::class]);
    config()->set('sidekick.tool_catalog.enabled', true);

    expect(offeredNames())->toBe(['ListTools', 'RunTool']);
});

it('flips to the catalog only once the authorized set passes the threshold', function () {
    config()->set('sidekick.tools', [EchoTool::class]);
    config()->set('sidekick.tool_catalog.above', 1);

    expect(offeredNames())->toBe(['EchoTool']);

    config()->set('sidekick.tools', [EchoTool::class, AdvisedTool::class]);

    expect(offeredNames())->toBe(['ListTools', 'RunTool']);
});

it('counts what this user can see, not what is registered', function () {
    // SecretTool is registered but unauthorized, so the set this user sees is still one tool.
    config()->set('sidekick.tools', [EchoTool::class, SecretTool::class]);
    config()->set('sidekick.tool_catalog.above', 1);

    expect(offeredNames())->toBe(['EchoTool']);
});

it('stays in direct mode when the user has no tools at all', function () {
    config()->set('sidekick.tools', []);
    config()->set('sidekick.tool_catalog.enabled', true);

    expect(offeredNames())->toBe([]);
});

it('keeps the built-ins direct so their calls stay readable afterwards', function () {
    config()->set('sidekick.tools', [EchoTool::class]);
    config()->set('sidekick.tool_catalog.enabled', true);
    app()->singleton(ActionResolver::class, FixedResolver::class);

    $offered = app(ToolRegistry::class)->offeredTo(FakeUser::make());

    expect(offeredNames())->toContain('Navigate', 'PresentActions')
        ->and(collect($offered)->first(fn ($t) => $t instanceof Navigate))->not->toBeNull()
        ->and(collect($offered)->first(fn ($t) => $t instanceof PresentActions))->not->toBeNull()
        // ...and they are not duplicated inside the catalog.
        ->and(array_column(app(ToolRegistry::class)->catalogFor(FakeUser::make()), 'name'))
        ->not->toContain('Navigate');
});

it('describes each tool with its parameters in the catalog', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $catalog = app(ToolRegistry::class)->catalogFor(FakeUser::make());

    expect($catalog)->toHaveCount(1)
        ->and($catalog[0]['name'])->toBe('EchoTool')
        ->and($catalog[0]['description'])->toContain('Echoes')
        ->and($catalog[0]['parameters'])->toHaveKey('text')
        ->and($catalog[0]['parameters']['text']['type'])->toBe('string')
        ->and($catalog[0]['parameters']['text']['required'])->toBeTrue();
});

it('never lists a tool the user is not authorized for', function () {
    config()->set('sidekick.tools', [EchoTool::class, SecretTool::class]);

    $names = array_column(app(ToolRegistry::class)->catalogFor(FakeUser::make()), 'name');

    expect($names)->toBe(['EchoTool']);
});

it('runs a catalogued tool through RunTool', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'EchoTool',
        'arguments' => '{"text":"hello"}',
    ]));

    expect($result)->toContain('hello');
});

it('accepts an already-decoded argument bag', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'EchoTool',
        'arguments' => ['text' => 'decoded'],
    ]));

    expect($result)->toContain('decoded');
});

it('refuses to run a tool the user is not authorized for, even when named exactly', function () {
    config()->set('sidekick.tools', [EchoTool::class, SecretTool::class]);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'SecretTool',
        'arguments' => '{}',
    ]));

    expect($result)->toContain('Unknown or unavailable tool [SecretTool]');
});

it('refuses to run the built-ins through the catalog', function () {
    config()->set('sidekick.tools', [EchoTool::class]);
    app()->singleton(ActionResolver::class, FixedResolver::class);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'PresentActions',
        'arguments' => '{}',
    ]));

    expect($result)->toContain('Unknown or unavailable tool [PresentActions]');
});

it('cannot be talked into calling itself', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'RunTool',
        'arguments' => '{}',
    ]));

    expect($result)->toContain('Unknown or unavailable tool [RunTool]');
});

it('hands back a correctable error for malformed arguments', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'EchoTool',
        'arguments' => 'not json',
    ]));

    expect($result)->toContain('must be a JSON object');
});

it('lists the catalog from ListTools', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $result = (new ListTools)->forUser(FakeUser::make())->handle(new Request([]));

    expect($result)->toContain('EchoTool')->toContain('Echoes');
});

it('resolves a RunTool call back to the tool it actually ran', function () {
    expect(ToolRegistry::ranTool('RunTool', ['tool' => 'EchoTool']))->toBe('EchoTool')
        // Providers hand arguments back as a JSON string in some transports.
        ->and(ToolRegistry::ranTool('RunTool', '{"tool":"EchoTool"}'))->toBe('EchoTool')
        // A direct call is already the answer.
        ->and(ToolRegistry::ranTool('EchoTool', []))->toBe('EchoTool')
        // Nothing usable inside: fall back to the wrapper rather than inventing a name.
        ->and(ToolRegistry::ranTool('RunTool', []))->toBe('RunTool')
        ->and(ToolRegistry::ranTool('RunTool', ['tool' => '  ']))->toBe('RunTool');
});

it('labels a catalog call with the inner tool\'s own status line', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    expect(app(ToolRegistry::class)->labelForCall('RunTool', ['tool' => 'EchoTool']))
        ->toBe('Using: Echo Tool');
});

it('still only proposes a write when the action is reached through the catalog', function () {
    $this->artisan('migrate');

    config()->set('sidekick.tools', []);
    config()->set('sidekick.actions', [CreateNote::class]);
    config()->set('sidekick.tool_catalog.enabled', true);
    CreateNote::$created = [];

    // The proposal tool is catalogued like any other, under its Propose{Type} name.
    expect(array_column(app(ToolRegistry::class)->catalogFor(FakeUser::make()), 'name'))
        ->toBe(['ProposeCreateNote']);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'ProposeCreateNote',
        'arguments' => '{"body":"Buy milk"}',
    ]));

    $response = json_decode($result, true);

    expect($response['proposed'])->toBeTrue()
        // The indirection changes nothing that matters: a card is waiting and nothing was written.
        ->and(PendingAction::query()->findOrFail($response['action_id'])->status)
        ->toBe(PendingAction::STATUS_PROPOSED)
        ->and(CreateNote::$created)->toBe([]);
});

it('returns an action\'s validation message through the catalog for the model to correct', function () {
    $this->artisan('migrate');

    config()->set('sidekick.actions', [CreateNote::class]);

    $result = (new RunTool)->forUser(FakeUser::make())->handle(new Request([
        'tool' => 'ProposeCreateNote',
        'arguments' => '{"body":"   "}',
    ]));

    expect(json_decode($result, true))->toHaveKey('error', 'A body is required.')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('keeps tool guidance in the system prompt even when the tools are behind the catalog', function () {
    config()->set('sidekick.tools', [AdvisedTool::class]);
    config()->set('sidekick.tool_catalog.enabled', true);

    // Safety guidance has to arrive before the model chooses, not after it fetches the catalog.
    expect(app(ToolRegistry::class)->instructionsFor(FakeUser::make()))->toContain('Tool guidance:');
});
