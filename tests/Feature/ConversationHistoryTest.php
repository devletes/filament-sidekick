<?php

use Devletes\Sidekick\Livewire\ChatPanel;
use Devletes\Sidekick\Models\Conversation;
use Devletes\Sidekick\SidekickPlugin;
use Devletes\Sidekick\Support\Profiles;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Filament\Panel;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('migrate');
});

function makeConversation(int $userId = 1, ?string $profile = null, string $title = 'A chat'): Conversation
{
    $conversation = new Conversation([
        'user_id' => $userId,
        'profile' => $profile,
        'title' => $title,
    ]);

    $conversation->id = (string) Str::uuid7();
    $conversation->save();

    return $conversation;
}

it('offers no history until it is switched on', function () {
    config()->set('sidekick.history.enabled', false);

    $user = FakeUser::make();
    makeConversation($user->getAuthIdentifier());

    $panel = new ChatPanel;

    expect($panel->recentConversations())->toBeEmpty();
});

it('lists the user\'s recent conversations once enabled', function () {
    config()->set('sidekick.history.enabled', true);

    $user = FakeUser::make();
    $this->actingAs($user);

    makeConversation($user->getAuthIdentifier(), title: 'Older');
    $newest = makeConversation($user->getAuthIdentifier(), title: 'Newest');
    $newest->touch();

    $titles = (new ChatPanel)->recentConversations()->pluck('title');

    expect($titles)->toHaveCount(2)
        ->and($titles->first())->toBe('Newest');
});

it('caps the list at the configured limit', function () {
    config()->set('sidekick.history.enabled', true);
    config()->set('sidekick.history.limit', 3);

    $user = FakeUser::make();
    $this->actingAs($user);

    foreach (range(1, 6) as $i) {
        makeConversation($user->getAuthIdentifier(), title: "Chat {$i}");
    }

    expect((new ChatPanel)->recentConversations())->toHaveCount(3);
});

it('never lists another user\'s conversations', function () {
    config()->set('sidekick.history.enabled', true);

    $user = FakeUser::make();
    $this->actingAs($user);

    makeConversation($user->getAuthIdentifier(), title: 'Mine');
    makeConversation(999, title: 'Someone else\'s');

    expect((new ChatPanel)->recentConversations()->pluck('title')->all())->toBe(['Mine']);
});

it('keeps each profile\'s history to itself', function () {
    config()->set('sidekick.history.enabled', true);
    config()->set('sidekick.profiles', ['boss' => ['assistant' => ['name' => 'Boss']]]);

    $user = FakeUser::make();
    $this->actingAs($user);

    makeConversation($user->getAuthIdentifier(), profile: null, title: 'Base chat');
    makeConversation($user->getAuthIdentifier(), profile: 'boss', title: 'Boss chat');

    app(Profiles::class)->apply('boss');

    expect((new ChatPanel)->recentConversations()->pluck('title')->all())->toBe(['Boss chat']);
});

it('refuses to open a conversation the user does not own', function () {
    config()->set('sidekick.history.enabled', true);

    $user = FakeUser::make();
    $this->actingAs($user);

    $theirs = makeConversation(999, title: 'Not yours');

    $panel = new ChatPanel;
    $panel->openConversation($theirs->id);

    expect($panel->conversationId)->toBeNull();
});

it('opens a conversation the user does own', function () {
    config()->set('sidekick.history.enabled', true);

    $user = FakeUser::make();
    $this->actingAs($user);

    $mine = makeConversation($user->getAuthIdentifier(), title: 'Mine');

    $panel = new ChatPanel;
    $panel->openConversation($mine->id);

    expect($panel->conversationId)->toBe($mine->id);
});

it('ignores openConversation entirely while history is off', function () {
    config()->set('sidekick.history.enabled', false);

    $user = FakeUser::make();
    $this->actingAs($user);

    $mine = makeConversation($user->getAuthIdentifier());

    $panel = new ChatPanel;
    $panel->openConversation($mine->id);

    expect($panel->conversationId)->toBeNull();
});

it('lets a panel turn history on through the plugin API', function () {
    config()->set('sidekick.history.enabled', false);

    SidekickPlugin::make()->enableHistory()->boot(app(Panel::class));

    expect(config('sidekick.history.enabled'))->toBeTrue();
});

it('lets a panel turn history back off when config enabled it', function () {
    config()->set('sidekick.history.enabled', true);

    SidekickPlugin::make()->enableHistory(false)->boot(app(Panel::class));

    expect(config('sidekick.history.enabled'))->toBeFalse();
});
