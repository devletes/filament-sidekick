<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('sidekick.tables.attachments', 'sidekick_attachments'), function (Blueprint $table) {
            $table->string('id', 36)->primary();
            // Nullable: files staged from a fresh panel are linked to the
            // conversation only when the message is actually sent.
            $table->string('conversation_id', 36)->nullable()->index();
            $table->foreignId('user_id')->index();
            $table->string('name', 191);
            $table->string('disk', 48);
            $table->string('path');
            $table->string('mime', 127);
            $table->unsignedBigInteger('size');
            $table->string('status', 16)->default('staged');
            $table->timestamps();

            // Prune scans (staged + old) and per-user listings.
            $table->index(['status', 'created_at']);
        });

        Schema::table(config('sidekick.tables.runs', 'sidekick_runs'), function (Blueprint $table) {
            // Attachment ids sent with this turn (metadata goes to the model,
            // file contents never do).
            $table->json('attachments')->nullable()->after('prompt');
        });

        Schema::table('sidekick_pending_actions', function (Blueprint $table) {
            // Upload spec from ActionHandler::prepare() — presence makes the
            // confirm card render a file field.
            $table->json('upload')->nullable()->after('preview');
        });
    }

    public function down(): void
    {
        Schema::table('sidekick_pending_actions', function (Blueprint $table) {
            $table->dropColumn('upload');
        });

        Schema::table(config('sidekick.tables.runs', 'sidekick_runs'), function (Blueprint $table) {
            $table->dropColumn('attachments');
        });

        Schema::dropIfExists(config('sidekick.tables.attachments', 'sidekick_attachments'));
    }
};
