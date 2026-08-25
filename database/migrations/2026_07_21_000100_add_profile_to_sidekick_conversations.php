<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');

        if (Schema::hasColumn($conversations, 'profile')) {
            return;
        }

        Schema::table($conversations, function (Blueprint $table) {
            // Which assistant profile the conversation belongs to (null = base).
            $table->string('profile', 48)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
