<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('sidekick.tables.runs', 'sidekick_runs'), function (Blueprint $table) {
            // One-shot navigation intent produced by a turn; the panel
            // consumes it (nulls it) as it performs the client redirect.
            $table->string('navigate_to', 500)->nullable()->after('usage');
        });
    }

    public function down(): void
    {
        Schema::table(config('sidekick.tables.runs', 'sidekick_runs'), function (Blueprint $table) {
            $table->dropColumn('navigate_to');
        });
    }
};
