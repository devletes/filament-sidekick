<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sidekick_pending_actions', function (Blueprint $table) {
            // Stored per card so the panel renders the right surface after a reload.
            $table->string('confirmation', 16)->default('inline');
        });
    }

    public function down(): void
    {
        Schema::table('sidekick_pending_actions', function (Blueprint $table) {
            $table->dropColumn('confirmation');
        });
    }
};
