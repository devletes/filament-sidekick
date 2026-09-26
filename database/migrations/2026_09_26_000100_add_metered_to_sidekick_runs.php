<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $runs = config('sidekick.tables.runs', 'sidekick_runs');

        Schema::table($runs, function (Blueprint $table) use ($runs) {
            if (! Schema::hasColumn($runs, 'metered')) {
                // Whether the turn counts against an allowance. An exempt turn (Contracts\UsageExemptions) is
                // still a row with its tokens, so the log and the insights keep every turn; limits skip it.
                $table->boolean('metered')->default(true)->after('tokens');
            }
        });
    }

    public function down(): void
    {
        $runs = config('sidekick.tables.runs', 'sidekick_runs');

        Schema::table($runs, function (Blueprint $table) {
            $table->dropColumn('metered');
        });
    }
};
