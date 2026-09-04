<?php

use Devletes\Sidekick\Support\ChatToolBase;
use Devletes\Sidekick\Tests\Fixtures\Actions\BrokenAction;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\Tools\BrokenTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\EchoTool;

it('passes when every dependency resolves', function () {
    config()->set('sidekick.tools', [EchoTool::class]);
    config()->set('sidekick.actions', [CreateNote::class]);

    $this->artisan('sidekick:check')
        ->expectsOutputToContain('No missing dependencies')
        ->assertSuccessful();
});

it('fails and names the file when a declared dependency is gone', function () {
    config()->set('sidekick.tools', [EchoTool::class, BrokenTool::class]);

    $this->artisan('sidekick:check')
        ->expectsOutputToContain(BrokenTool::class)
        ->expectsOutputToContain(BrokenTool::MISSING)
        ->assertFailed();
});

it('reports a broken action as well as a broken tool', function () {
    config()->set('sidekick.actions', [BrokenAction::class]);

    $this->artisan('sidekick:check')
        ->expectsOutputToContain(BrokenAction::MISSING)
        ->assertFailed();
});

it('lists what depends on a class before it is deleted', function () {
    config()->set('sidekick.tools', [BrokenTool::class, EchoTool::class]);

    $this->artisan('sidekick:check', ['--uses' => BrokenTool::MISSING])
        ->expectsOutputToContain(BrokenTool::class)
        ->assertSuccessful();
});

it('finds dependents through imports even when nothing was declared', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    // EchoTool never declares dependsOn(), but it imports ChatToolBase.
    $this->artisan('sidekick:check', ['--uses' => ChatToolBase::class])
        ->expectsOutputToContain(EchoTool::class)
        ->assertSuccessful();
});

it('says so plainly when nothing depends on the class', function () {
    config()->set('sidekick.tools', [EchoTool::class]);

    $this->artisan('sidekick:check', ['--uses' => 'App\Models\NeverReferenced'])
        ->expectsOutputToContain('Nothing depends on')
        ->assertSuccessful();
});
