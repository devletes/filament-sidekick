<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            // Which assistant profile the conversation belongs to (null =
            // base). Panels scope their resume/list queries by it, and queued
            // turns re-apply the profile from it.
            $table->string('profile', 48)->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->dropColumn('profile');
        });
    }
};
