<?php

use Devletes\Sidekick\Support\ToolRegistry;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\Tools\AdvisedTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\EchoTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\SecretTool;

it('assembles instructions from the offered tools into a guidance block', function () {
    config()->set('sidekick.tools', [AdvisedTool::class, EchoTool::class]);

    $guidance = app(ToolRegistry::class)->instructionsFor(FakeUser::make());

    expect($guidance)->toStartWith('Tool guidance:')
        ->and($guidance)->toContain('Always confirm the employee ID');
});

it('contributes nothing when no offered tool has instructions', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    expect(app(ToolRegistry::class)->instructionsFor(FakeUser::make()))->toBe('');
});

it('never includes instructions from tools the user is not authorized for', function () {
    config()->set('sidekick.tools', [SecretTool::class, AdvisedTool::class]);

    $guidance = app(ToolRegistry::class)->instructionsFor(FakeUser::make());

    expect($guidance)->not->toContain('never leak')
        ->and($guidance)->toContain('Always confirm the employee ID');
});

it('lets actions ship standing instructions through their proposal tool', function () {
    config()->set('sidekick.actions', [CreateNote::class]);

    $guidance = app(ToolRegistry::class)->instructionsFor(FakeUser::make());

    expect($guidance)->toContain('Notes are visible to the whole team');
});
