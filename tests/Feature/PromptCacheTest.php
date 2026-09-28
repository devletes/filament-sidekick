<?php

use Devletes\Sidekick\Agents\ChatAgent;
use Devletes\Sidekick\Jobs\RunChatTurn;
use Devletes\Sidekick\Storage\LeanConversationStore;
use Devletes\Sidekick\Support\ToolRegistry;
use Devletes\Sidekick\Tests\Fixtures\FakeUser;
use Devletes\Sidekick\Tests\Fixtures\Tools\AdvisedTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\EchoTool;
use Devletes\Sidekick\Tests\Fixtures\Tools\StatusTool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;

/**
 * The instructions hold nothing that changes between turns, the conversation is cached on Anthropic, and the
 * history is replayed exactly as it was sent, so a turn reads what the turn before it already paid for.
 */
function cachedConversation(): string
{
    $id = (string) Str::uuid7();

    DB::table(config('ai.conversations.tables.conversations', 'agent_conversations'))->insert([
        'id' => $id,
        'user_id' => 1,
        'title' => 'Cache test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function appendMessage(string $conversationId, string $role, string $content, ?string $context = null): void
{
    static $second = 0;

    DB::table(config('ai.conversations.tables.messages', 'agent_conversation_messages'))->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'user_id' => 1,
        'agent' => 'test',
        'role' => $role,
        'content' => $content,
        'context' => $context,
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now()->addSeconds(++$second),
        'updated_at' => now()->addSeconds($second),
    ]);
}

beforeEach(function () {
    $this->artisan('migrate');
    StatusTool::$now = 'Step 3 of 9: Positions.';
});

it('keeps what changes between turns out of the instructions and hands it to the message instead', function () {
    config()->set('sidekick.tools', [StatusTool::class, AdvisedTool::class]);

    $agent = ChatAgent::make()->continue((string) Str::uuid7(), FakeUser::make());

    expect($agent->instructions())->not->toContain('Step 3 of 9')
        ->and($agent->instructions())->toContain('Always confirm the employee ID')
        ->and($agent->turnContext())->toBe('Step 3 of 9: Positions.');

    StatusTool::$now = 'Step 4 of 9: Employees.';

    // The instructions read the same whatever the step, which is what keeps them, and all after them, cached.
    expect($agent->turnContext())->toBe('Step 4 of 9: Employees.');
});

it('offers turn context only from tools that provide it', function () {
    config()->set('sidekick.tools', [EchoTool::class, AdvisedTool::class]);

    expect(app(ToolRegistry::class)->turnContextFor(FakeUser::make()))->toBe('');
});

it('wraps the context in a note the model is told how to read', function () {
    expect(RunChatTurn::contextNote(''))->toBe('')
        ->and(RunChatTurn::contextNote('Step 3.'))->toBe("[App context when this message was sent:\nStep 3.]");
});

it('replays a message with its context note, as it was sent', function () {
    config()->set('sidekick.history_token_budget', null);

    $id = cachedConversation();
    appendMessage($id, 'user', 'Where do I start?', "[App context when this message was sent:\nStep 1.]");
    appendMessage($id, 'assistant', 'With your locations.');

    $messages = app(LeanConversationStore::class)->getLatestConversationMessages($id, 10)->values();

    expect($messages[0]->content)->toBe("Where do I start?\n\n[App context when this message was sent:\nStep 1.]");
});

it('asks Anthropic to cache the conversation, and no other provider', function () {
    $agent = ChatAgent::make();

    expect($agent->providerOptions(Lab::Anthropic)['cache_control'] ?? null)->toBe(['type' => 'ephemeral'])
        ->and($agent->providerOptions('anthropic'))->toHaveKey('cache_control')
        ->and($agent->providerOptions(Lab::OpenAI))->not->toHaveKey('cache_control');

    config()->set('sidekick.cache_history', false);

    expect($agent->providerOptions(Lab::Anthropic))->not->toHaveKey('cache_control');
});

it('moves the start of the history in steps, so it stays put while turns are added', function () {
    config()->set('sidekick.history_bytes_per_token', 4);
    // 40 bytes a message = 10 tokens; pages of 20 tokens, two messages each.
    config()->set('sidekick.history_token_budget', 40);

    $id = cachedConversation();
    $firstKept = function () use ($id): string {
        return app(LeanConversationStore::class)->getLatestConversationMessages($id, PHP_INT_MAX)->first()->content;
    };

    foreach (range(1, 7) as $i) {
        appendMessage($id, $i % 2 ? 'user' : 'assistant', str_repeat((string) $i, 40));
    }

    $start = $firstKept();
    appendMessage($id, 'assistant', str_repeat('8', 40));

    // A window sliding one message per turn would start one later now; this one starts where it did.
    expect($firstKept())->toBe($start)
        ->and(app(LeanConversationStore::class)->getLatestConversationMessages($id, PHP_INT_MAX))->toHaveCount(4);
});
