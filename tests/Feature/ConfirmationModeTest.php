<?php

use Devletes\Sidekick\Enums\ConfirmationMode;
use Devletes\Sidekick\Livewire\ChatPanel;
use Devletes\Sidekick\Models\PendingAction;
use Devletes\Sidekick\Tests\Fixtures\Actions\CreateNote;
use Devletes\Sidekick\Tests\Fixtures\Actions\ReviewReport;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tools\ActionProposalTool;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

beforeEach(function () {
    $this->artisan('migrate');
    ReviewReport::$approved = [];
    config()->set('sidekick.actions', [CreateNote::class, ReviewReport::class]);
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

    // A page load must not spring the modal open by itself; the dock offers the way back in.
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
