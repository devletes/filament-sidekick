<?php

use Devletes\Sidekick\Livewire\ChatPanel;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\RunContext;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

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

it('executes a confirmed action exactly once under concurrent clicks', function () {
    CreateNote::$created = [];

    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();

    $action = PendingAction::query()->create([
        'conversation_id' => $conversationId,
        'user_id' => $user->getAuthIdentifier(),
        'type' => 'create_note',
        'status' => PendingAction::STATUS_PROPOSED,
        'summary' => 'Create a note',
        'payload' => ['body' => 'Buy milk'],
        'preview' => [],
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user);

    // Two panel instances on the same conversation, both showing the card.
    $confirm = fn () => Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->call('confirmAction', $action->id);

    $confirm();
    $confirm();

    expect(CreateNote::$created)->toBe(['Buy milk'])
        ->and($action->refresh()->status)->toBe(PendingAction::STATUS_EXECUTED);
});

it('supersedes an earlier unanswered proposal in the same conversation', function () {
    $context = new RunContext;
    $context->conversationId = 'conv-1';
    app()->instance(RunContext::class, $context);

    $tool = (new ActionProposalTool(new CreateNote))->forUser(FakeUser::make());

    $first = json_decode($tool->handle(new Request(['body' => 'One'])), true);
    $second = json_decode($tool->handle(new Request(['body' => 'Two'])), true);

    expect(PendingAction::query()->findOrFail($first['action_id'])->status)->toBe(PendingAction::STATUS_CANCELLED)
        ->and(PendingAction::query()->findOrFail($second['action_id'])->status)->toBe(PendingAction::STATUS_PROPOSED);
});
