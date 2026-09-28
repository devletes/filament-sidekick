<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($messages, function (Blueprint $table) use ($messages) {
            if (! Schema::hasColumn($messages, 'context')) {
                // The app's note sent with a user message (Contracts\ProvidesTurnContext, recent action outcomes).
                // Kept apart from the content so the panel never shows it, and replayed with the message so the
                // history the provider sees is byte for byte what it saw, which is what lets it be read from the cache.
                $table->text('context')->nullable();
            }
        });
    }

    public function down(): void
    {
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($messages, function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
