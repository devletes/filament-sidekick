<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('sidekick.tables.runs', 'sidekick_runs'), function (Blueprint $table) {
            // Failed by a UsageLimiter rather than an error: the panel shows
            // the limiter's message verbatim instead of the generic notice.
            $table->boolean('denied')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(config('sidekick.tables.runs', 'sidekick_runs'), function (Blueprint $table) {
            $table->dropColumn('denied');
        });
    }
};
