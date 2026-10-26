<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversations, function (Blueprint $table) use ($conversations) {
            if (! Schema::hasColumn($conversations, 'participant_type')) {
                $table->string('participant_type')->nullable();
            }

            if (! Schema::hasColumn($conversations, 'participant_id')) {
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
            }
        });

        Schema::table($messages, function (Blueprint $table) use ($messages) {
            if (! Schema::hasColumn($messages, 'participant_type')) {
                $table->string('participant_type')->nullable();
            }

            if (! Schema::hasColumn($messages, 'participant_id')) {
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
            }
        });

        // One-way data rewrite: mirror legacy user_id into the polymorphic pair for laravel/ai lookups.
        $type = $this->participantType();

        foreach ([$conversations, $messages] as $table) {
            if (! Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            DB::table($table)
                ->whereNull('participant_id')
                ->whereNotNull('user_id')
                ->update([
                    'participant_type' => $type,
                    'participant_id' => DB::raw('user_id'),
                ]);
        }
    }

    public function down(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversations, function (Blueprint $table) use ($conversations) {
            if (Schema::hasColumn($conversations, 'participant_id')) {
                $table->dropIndex('participant_updated_at_index');
                $table->dropColumn(['participant_type', 'participant_id']);
            }
        });

        Schema::table($messages, function (Blueprint $table) use ($messages) {
            if (Schema::hasColumn($messages, 'participant_id')) {
                $table->dropIndex('participant_index');
                $table->dropColumn(['participant_type', 'participant_id']);
            }
        });
    }

    protected function participantType(): string
    {
        $model = (string) config('auth.providers.users.model');

        return class_exists($model) ? (new $model)->getMorphClass() : $model;
    }
};
