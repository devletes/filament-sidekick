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
                // A string, unlike the conversations table's integer: tenants are often keyed by uuid.
                $table->string('tenant_id', 64)->nullable()->after('user_id');
            }

            if (! Schema::hasColumn($runs, 'tokens')) {
                $table->unsignedInteger('tokens')->nullable()->after('usage');
            }
        });

        Schema::table($runs, function (Blueprint $table) {
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
