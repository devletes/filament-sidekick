<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');
        $runs = config('sidekick.tables.runs', 'sidekick_runs');

        // laravel/ai's own (publish-only) migration creates the same two
        // tables without our columns — create, or top up what's missing.
        if (Schema::hasTable($conversations)) {
            Schema::table($conversations, function (Blueprint $table) use ($conversations) {
                if (! Schema::hasColumn($conversations, 'tenant_id')) {
                    $table->unsignedBigInteger('tenant_id')->nullable();
                }

                if (! Schema::hasColumn($conversations, 'channel')) {
                    $table->string('channel', 16)->default('web');
                }
            });
        }

        if (! Schema::hasTable($conversations)) {
            Schema::create($conversations, function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->foreignId('user_id')->nullable();
                // Host-app scoping columns; hosts without multi-tenancy leave tenant_id null.
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('channel', 16)->default('web');
                $table->string('title');
                $table->timestamps();

                $table->index(['user_id', 'updated_at']);
                $table->index(['tenant_id', 'user_id', 'updated_at']);
            });
        }

        if (! Schema::hasTable($messages)) {
            Schema::create($messages, function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->foreignId('user_id')->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments');
                $table->text('tool_calls');
                $table->text('tool_results');
                $table->text('usage');
                $table->text('meta');
                $table->timestamps();

                $table->index(['conversation_id', 'user_id', 'updated_at'], 'conversation_index');
                $table->index(['user_id']);
            });
        }

        Schema::create($runs, function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->foreignId('user_id')->index();
            $table->text('prompt');
            $table->string('status', 16)->default('queued');
            $table->longText('partial_content')->nullable();
            $table->json('activity')->nullable();
            $table->json('usage')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        // The conversation tables are shared with laravel/ai and may predate
        // this package — dropping them here would destroy the host's data.
        Schema::dropIfExists(config('sidekick.tables.runs', 'sidekick_runs'));
    }
};
