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
