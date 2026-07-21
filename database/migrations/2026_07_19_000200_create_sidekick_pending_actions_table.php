<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sidekick_pending_actions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('conversation_id', 36)->index();
            $table->string('run_id', 36)->nullable();
            $table->foreignId('user_id')->index();
            $table->string('type', 48);
            $table->json('payload');
            $table->json('preview');
            $table->string('summary', 200);
            $table->string('status', 16)->default('proposed');
            $table->string('result', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sidekick_pending_actions');
    }
};
