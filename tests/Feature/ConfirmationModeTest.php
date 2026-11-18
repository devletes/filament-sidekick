<?php

use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Livewire\ChatPanel;
use Devletes\Sidekick\Models\ConversationMessage;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Support\RunContext;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\Actions\NameNote;
use Devletes\Sidekick\Tests\Fixtures\Actions\PickNotes;
use Devletes\Sidekick\Tests\Fixtures\Actions\ReviewReport;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

beforeEach(function () {
    $this->artisan('migrate');
    ReviewReport::$approved = [];
    config()->set('sidekick.actions', [CreateNote::class, ReviewReport::class, PickNotes::class, NameNote::class]);
    PickNotes::$picked = [];
    NameNote::$saved = [];
});

function proposeNote(string $conversationId, FakeUser $user, string $title): PendingAction
{
    $context = new RunContext;
    $context->conversationId = $conversationId;
    app()->instance(RunContext::class, $context);

    $proposed = json_decode((new ActionProposalTool(new NameNote))->forUser($user)->handle(new Request(['title' => $title])), true);

    return PendingAction::query()->findOrFail($proposed['action_id']);
}

it('lets the user correct what the card was filled with before confirming', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();
    $this->actingAs($user);
    $action = proposeNote($conversationId, $user, 'Salmn');

    Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->assertSet('cardFormData.title', 'Salmn')
        ->set('cardFormData.title', 'Salman')
        ->call('confirmAction', $action->id);

    expect(NameNote::$saved)->toBe(['Salman'])
        ->and($action->refresh()->status)->toBe(PendingAction::STATUS_EXECUTED);
});

it('notes the outcome for the model as the app\'s, and leaves it out of the chat', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();
    $this->actingAs($user);
    $action = proposeNote($conversationId, $user, 'Salman');

    $panel = Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->call('confirmAction', $action->id);

    $note = ConversationMessage::query()->where('conversation_id', $conversationId)->sole();

    expect($note->role)->toBe('assistant')
        ->and($note->content)->toBe('[App note, not written by you: The user confirmed "'.$action->summary.'". Result: Created Salman.]');

    $panel->assertDontSee('App note');
});

it('keeps a form card open with the error under its field when execute() refuses it', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();
    $this->actingAs($user);
    $action = proposeNote($conversationId, $user, 'Taken');

    $panel = Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->call('confirmAction', $action->id)
        ->assertHasErrors(['cardFormData.title'])
        ->assertSet('cardFormData.title', 'Taken')
        ->assertSee('A note called Taken already exists.');

    expect($action->refresh()->status)->toBe(PendingAction::STATUS_PROPOSED)
        ->and(NameNote::$saved)->toBe([]);

    // Editing the field clears the refusal before Confirm is pressed again.
    $panel->set('cardFormData.title', 'Fresh')
        ->assertHasNoErrors()
        ->assertDontSee('A note called Taken already exists.')
        ->call('confirmAction', $action->id)
        ->assertHasNoErrors();

    expect(NameNote::$saved)->toBe(['Fresh'])
        ->and($action->refresh()->status)->toBe(PendingAction::STATUS_EXECUTED);
});

it('shows an error that names no field on the card itself', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();
    $this->actingAs($user);
    $action = proposeNote($conversationId, $user, 'Closed');

    Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->call('confirmAction', $action->id)
        ->assertSet('cardError', 'That notebook is closed.')
        ->assertSee('That notebook is closed.');

    expect($action->refresh()->status)->toBe(PendingAction::STATUS_PROPOSED);
});

it('defaults to an inline confirmation', function () {
    expect((new CreateNote)->confirmation())->toBe(ConfirmationMode::Inline);
});

it('stores the action\'s confirmation mode on the card', function () {
    $tool = (new ActionProposalTool(new ReviewReport))->forUser(FakeUser::make());

    $response = json_decode($tool->handle(new Request(['title' => 'Q3 numbers'])), true);
    $action = PendingAction::query()->findOrFail($response['action_id']);

    expect($action->confirmation)->toBe(ConfirmationMode::Modal)
        ->and($action->rendersInModal())->toBeTrue();
});

it('offers a way back into a modal card the user reloaded away from', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();

    PendingAction::query()->create([
        'conversation_id' => $conversationId,
        'user_id' => $user->getAuthIdentifier(),
        'type' => 'review_report',
        'status' => PendingAction::STATUS_PROPOSED,
        'confirmation' => ConfirmationMode::Modal->value,
        'summary' => 'Approve the quarterly report',
        'payload' => ['title' => 'Q3 numbers'],
        'preview' => [],
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user);

    $panel = Livewire::test(ChatPanel::class)->set('conversationId', $conversationId);

    $panel->assertSet('actionModalOpen', false)
        ->assertSee('A confirmation is waiting')
        ->assertSee('Review it');

    $panel->call('openActionModal')->assertSet('actionModalOpen', true);
});

it('confirms a modal card and closes the modal', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();

    $action = PendingAction::query()->create([
        'conversation_id' => $conversationId,
        'user_id' => $user->getAuthIdentifier(),
        'type' => 'review_report',
        'status' => PendingAction::STATUS_PROPOSED,
        'confirmation' => ConfirmationMode::Modal->value,
        'summary' => 'Approve the quarterly report',
        'payload' => ['title' => 'Q3 numbers'],
        'preview' => [],
        'expires_at' => now()->addHour(),
    ]);

    $this->actingAs($user);

    Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->call('openActionModal')
        ->call('confirmAction', $action->id)
        ->assertSet('actionModalOpen', false);

    expect(ReviewReport::$approved)->toBe(['Q3 numbers'])
        ->and($action->refresh()->status)->toBe(PendingAction::STATUS_EXECUTED);
});

function proposeIn(string $conversationId, FakeUser $user, array $titles): array
{
    $context = new RunContext;
    $context->conversationId = $conversationId;
    app()->instance(RunContext::class, $context);

    return json_decode((new ActionProposalTool(new PickNotes))->forUser($user)->handle(new Request(['titles' => $titles])), true);
}

it('draws an action\'s confirmation form and hands what was ticked to execute()', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();
    $this->actingAs($user);

    $proposed = proposeIn($conversationId, $user, ['Alpha', 'Beta', 'Gamma']);
    $action = PendingAction::query()->findOrFail($proposed['action_id']);

    $panel = Livewire::test(ChatPanel::class)
        ->set('conversationId', $conversationId)
        ->assertSee('Alpha')
        ->assertSee('Gamma');

    $rows = $panel->get('cardFormData.rows');
    expect($rows)->toHaveCount(3);

    $beta = collect($rows)->search(fn (array $row): bool => $row['title'] === 'Beta');
    $panel->set("cardFormData.rows.{$beta}.selected", false)
        ->call('confirmAction', $action->id);

    expect(PickNotes::$picked)->toBe(['Alpha', 'Gamma'])
        ->and($action->refresh()->status)->toBe(PendingAction::STATUS_EXECUTED);
});

it('keeps what was ticked across a refresh of the panel', function () {
    $user = FakeUser::make();
    $conversationId = (string) Str::uuid7();
    $this->actingAs($user);

    $proposed = proposeIn($conversationId, $user, ['Alpha', 'Beta']);

    $panel = Livewire::test(ChatPanel::class)->set('conversationId', $conversationId);
    $first = array_key_first($panel->get('cardFormData.rows'));

    $panel->set("cardFormData.rows.{$first}.selected", false)
        ->call('$refresh')
        ->assertSet("cardFormData.rows.{$first}.selected", false);

    expect($proposed['action_id'])->not->toBeEmpty();
});
