<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->artisan('migrate');
});

function legacyConversation(int $userId): string
{
    $id = (string) Str::uuid7();

    // Keyed on user_id alone, the way rows looked before laravel/ai 0.10.
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'Before participants',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $id,
        'user_id' => $userId,
        'agent' => 'App\\Agents\\Nyra',
        'role' => 'user',
        'content' => 'hello',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('adds the columns laravel/ai 0.10+ writes to both conversation tables', function () {
    expect(Schema::hasColumns('agent_conversations', ['participant_type', 'participant_id']))->toBeTrue()
        ->and(Schema::hasColumns('agent_conversation_messages', ['participant_type', 'participant_id', 'approval_state']))->toBeTrue()
        // user_id stays: the package's own scopes and insights read it.
        ->and(Schema::hasColumn('agent_conversations', 'user_id'))->toBeTrue();
});

it('backfills the participant pair from user_id on rows that predate the columns', function () {
    $id = legacyConversation(7);

    // Re-running the migration is idempotent: the columns already exist, so only the backfill runs.
    (require __DIR__.'/../../database/migrations/2026_09_05_000100_add_participants_to_conversation_tables.php')->up();

    $expectedType = (new (config('auth.providers.users.model')))->getMorphClass();

    $conversation = DB::table('agent_conversations')->where('id', $id)->first();
    $message = DB::table('agent_conversation_messages')->where('conversation_id', $id)->first();

    expect($conversation->participant_type)->toBe($expectedType)
        ->and((int) $conversation->participant_id)->toBe(7)
        ->and($message->participant_type)->toBe($expectedType)
        ->and((int) $message->participant_id)->toBe(7)
        ->and($message->approval_state)->toBeNull();
});

it('leaves rows that already carry a participant alone', function () {
    $id = legacyConversation(7);

    DB::table('agent_conversations')->where('id', $id)->update([
        'participant_type' => 'custom',
        'participant_id' => 99,
    ]);

    (require __DIR__.'/../../database/migrations/2026_09_05_000100_add_participants_to_conversation_tables.php')->up();

    $conversation = DB::table('agent_conversations')->where('id', $id)->first();

    expect($conversation->participant_type)->toBe('custom')
        ->and((int) $conversation->participant_id)->toBe(99);
});
