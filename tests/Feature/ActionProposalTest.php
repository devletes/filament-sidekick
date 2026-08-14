<?php

use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->artisan('migrate');
    config()->set('sidekick.actions', [CreateNote::class]);
});

it('stores a confirm card when the model proposes a valid action', function () {
    $tool = (new ActionProposalTool(new CreateNote))->forUser(FakeUser::make());

    $response = json_decode($tool->handle(new Request(['body' => 'Buy milk'])), true);

    expect($response['proposed'])->toBeTrue()
        ->and($response['summary'])->toBe('Create a note');

    $action = PendingAction::query()->findOrFail($response['action_id']);

    expect($action->type)->toBe('create_note')
        ->and($action->status)->toBe(PendingAction::STATUS_PROPOSED)
        ->and($action->payload)->toBe(['body' => 'Buy milk'])
        ->and(CreateNote::$created)->toBe([]);
});

it('returns the validation message to the model instead of throwing', function () {
    $tool = (new ActionProposalTool(new CreateNote))->forUser(FakeUser::make());

    $response = json_decode($tool->handle(new Request(['body' => '   '])), true);

    expect($response)->toHaveKey('error', 'A body is required.')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('supersedes an earlier unanswered proposal in the same conversation', function () {
    $context = new \Devletes\Sidekick\Support\RunContext;
    $context->conversationId = 'conv-1';
    app()->instance(\Devletes\Sidekick\Support\RunContext::class, $context);

    $tool = (new ActionProposalTool(new CreateNote))->forUser(FakeUser::make());

    $first = json_decode($tool->handle(new Request(['body' => 'One'])), true);
    $second = json_decode($tool->handle(new Request(['body' => 'Two'])), true);

    expect(PendingAction::query()->findOrFail($first['action_id'])->status)->toBe(PendingAction::STATUS_CANCELLED)
        ->and(PendingAction::query()->findOrFail($second['action_id'])->status)->toBe(PendingAction::STATUS_PROPOSED);
});
