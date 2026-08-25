<?php

use Devletes\Sidekick\Support\RunContext;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\FixedResolver;
use Devletes\Sidekick\Tools\Navigate;
use Devletes\Sidekick\Tools\PresentActions;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

it('stores the navigation url on the run context for a known target', function () {
    $context = app()->instance(RunContext::class, new RunContext);
    $tool = (new Navigate(new FixedResolver))->forUser(FakeUser::make());

    $response = json_decode($tool->handle(new Request(['target' => 'notes', 'record' => '7'])), true);

    expect($response['navigating'])->toBeTrue()
        ->and($context->navigateTo)->toBe('https://example.test/notes/7');
});

it('reports unknown navigation targets back to the model with the valid list', function () {
    app()->instance(RunContext::class, $context = new RunContext);
    $tool = (new Navigate(new FixedResolver))->forUser(FakeUser::make());

    $response = json_decode($tool->handle(new Request(['target' => 'bogus'])), true);

    expect($response['error'])->toContain('bogus')
        ->and($response['targets'])->toBe(['notes'])
        ->and($context->navigateTo)->toBeNull();
});

it('validates presented buttons and rejects unresolvable ones', function () {
    $tool = (new PresentActions(new FixedResolver))->forUser(FakeUser::make());

    $response = json_decode($tool->handle(new Request(['actions' => [
        ['label' => 'Open notes', 'target' => 'notes'],
        ['label' => 'Elsewhere', 'url' => 'https://example.test/x'],
        ['label' => 'Broken', 'target' => 'bogus'],
    ]])), true);

    expect($response['presented'])->toBe(['Open notes', 'Elsewhere'])
        ->and($response['not_shown'])->toBe(['Broken']);
});

it('builds schemas against the framework json schema factory', function () {
    $schema = new JsonSchemaTypeFactory;

    $navigate = (new Navigate(new FixedResolver))->schema($schema);
    $present = (new PresentActions(new FixedResolver))->schema($schema);

    expect($navigate['target']->toArray())->toMatchArray(['type' => 'string', 'enum' => ['notes']])
        ->and($present['actions']->toArray()['type'])->toBe('array');
});
