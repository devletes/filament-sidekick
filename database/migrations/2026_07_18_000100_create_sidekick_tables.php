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

        // laravel/ai's publish-only migration may already have created these tables; top up our columns.
        if (Schema::hasTable($conversations)) {
            Schema::table($conversations, function (Blueprint $table) use ($conversations) {
                if (! Schema::hasColumn($conversations, 'user_id')) {
                    $table->foreignId('user_id')->nullable();
                    $table->index(['user_id', 'updated_at']);
                }

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
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->foreignId('user_id')->nullable();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('channel', 16)->default('web');
                $table->string('title');
                $table->timestamps();

                $table->index(['participant_type', 'participant_id', 'updated_at'], 'participant_updated_at_index');
                $table->index(['user_id', 'updated_at']);
                $table->index(['tenant_id', 'user_id', 'updated_at']);
            });
        }

        if (Schema::hasTable($messages) && ! Schema::hasColumn($messages, 'user_id')) {
            Schema::table($messages, function (Blueprint $table) {
                $table->foreignId('user_id')->nullable();
                $table->index(['user_id']);
            });
        }

        if (! Schema::hasTable($messages)) {
            Schema::create($messages, function (Blueprint $table) {
                $table->string('id', 36)->primary();
                $table->string('conversation_id', 36)->index();
                $table->string('participant_type')->nullable();
                $table->unsignedBigInteger('participant_id')->nullable();
                $table->foreignId('user_id')->nullable();
                $table->string('agent');
                $table->string('role', 25);
                $table->text('content');
                $table->text('attachments');
                $table->longText('steps');
                $table->text('usage');
                $table->text('meta');
                $table->string('status', 25);
                $table->timestamps();

                $table->index(['conversation_id', 'participant_type', 'participant_id', 'updated_at'], 'conversation_index');
                $table->index(['participant_type', 'participant_id', 'agent'], 'participant_index');
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
        // Conversation tables are shared with laravel/ai; dropping them would destroy host data.
        Schema::dropIfExists(config('sidekick.tables.runs', 'sidekick_runs'));
    }
};
