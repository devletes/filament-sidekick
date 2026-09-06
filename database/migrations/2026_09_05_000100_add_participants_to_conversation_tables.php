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

        // laravel/ai 0.10 made the conversation owner polymorphic
        // (participant_type + participant_id) and 0.10 added approval_state to
        // messages for human-in-the-loop tool calls. Its store writes those
        // columns unconditionally, so a host that created the tables through
        // this package's 0.7-era migration fails every turn without them.
        // user_id stays: the package's own scopes, insights and runs read it.
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
                $table->index(['participant_type', 'participant_id'], 'participant_index');
            }

            if (! Schema::hasColumn($messages, 'approval_state')) {
                $table->text('approval_state')->nullable();
            }
        });

        // Existing rows were keyed on user_id alone; mirror it into the
        // polymorphic pair so laravel/ai's participant lookups find them.
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

            if (Schema::hasColumn($messages, 'approval_state')) {
                $table->dropColumn('approval_state');
            }
        });
    }

    /** The morph discriminator laravel/ai records for the host's user model — its alias when a morph map names one. */
    protected function participantType(): string
    {
        $model = (string) config('auth.providers.users.model');

        return class_exists($model) ? (new $model)->getMorphClass() : $model;
    }
};
