<?php

use Devletes\Sidekick\Storage\LeanConversationStore;
use Devletes\Sidekick\Support\TokenBudget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('migrate');
});

/** Write $count messages of $bytes each, oldest first, and return the conversation id. */
function seedConversation(int $count, int $bytes = 40): string
{
    $conversationId = (string) Str::uuid7();

    DB::table(config('ai.conversations.tables.conversations', 'agent_conversations'))->insert([
        'id' => $conversationId,
        'user_id' => 1,
        'title' => 'Budget test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(1, $count) as $i) {
        DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))->insert([
            // uuid7 sorts lexicographically by time, which is the order the store rehydrates in.
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => 1,
            'agent' => 'test',
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => str_repeat((string) ($i % 10), $bytes),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now()->addSeconds($i),
            'updated_at' => now()->addSeconds($i),
        ]);
    }

    return $conversationId;
}

it('estimates tokens from byte length', function () {
    config()->set('sidekick.history_bytes_per_token', 4);

    expect(TokenBudget::estimate(''))->toBe(0)
        ->and(TokenBudget::estimate(str_repeat('a', 40)))->toBe(10)
        // Multi-byte scripts cost more per character, which is what byte length is for.
        ->and(TokenBudget::estimate('日本語'))->toBeGreaterThan(TokenBudget::estimate('abc'));
});

it('rehydrates every row within the limit when no budget is set', function () {
    config()->set('sidekick.history_token_budget', null);

    $id = seedConversation(6);

    expect(app(LeanConversationStore::class)->getLatestConversationMessages($id, 10))->toHaveCount(6);
});

it('drops the oldest messages until the history fits the token budget', function () {
    config()->set('sidekick.history_bytes_per_token', 4);
    // 40 bytes per message = 10 tokens each; 25 tokens fits two.
    config()->set('sidekick.history_token_budget', 25);

    $id = seedConversation(6, bytes: 40);

    $messages = app(LeanConversationStore::class)->getLatestConversationMessages($id, 10);

    expect($messages)->toHaveCount(2)
        // The newest survive: recency is what carries the thread.
        ->and($messages->last()->content)->toBe(str_repeat('6', 40))
        ->and($messages->first()->content)->toBe(str_repeat('5', 40));
});

it('keeps the newest message even when it alone blows the budget', function () {
    config()->set('sidekick.history_bytes_per_token', 4);
    config()->set('sidekick.history_token_budget', 1);

    $id = seedConversation(4, bytes: 400);

    expect(app(LeanConversationStore::class)->getLatestConversationMessages($id, 10))->toHaveCount(1);
});

it('still respects the row limit when a generous budget is set', function () {
    config()->set('sidekick.history_token_budget', 1_000_000);

    $id = seedConversation(8);

    expect(app(LeanConversationStore::class)->getLatestConversationMessages($id, 3))->toHaveCount(3);
});
