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
            if (! Schema::hasColumn($runs, 'tenant_id')) {
                // A string rather than the conversations table's integer: plenty of panels key tenants by uuid,
                // and limits have to scope correctly either way.
                $table->string('tenant_id', 64)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn($runs, 'tokens')) {
                // Denormalised from `usage` so limits and insights can sum in SQL instead of decoding JSON per row.
                $table->unsignedInteger('tokens')->nullable()->after('usage');
            }
        });

        Schema::table($runs, function (Blueprint $table) {
            // Both windows are "this tenant/user, since a timestamp", which is what limits and charts ask for.
            $table->index(['tenant_id', 'created_at'], 'sidekick_runs_tenant_created_idx');
            $table->index(['user_id', 'created_at'], 'sidekick_runs_user_created_idx');
        });
    }

    public function down(): void
    {
        $runs = config('sidekick.tables.runs', 'sidekick_runs');

        Schema::table($runs, function (Blueprint $table) {
            $table->dropIndex('sidekick_runs_tenant_created_idx');
            $table->dropIndex('sidekick_runs_user_created_idx');
            $table->dropColumn(['tenant_id', 'tokens']);
        });
    }
};
