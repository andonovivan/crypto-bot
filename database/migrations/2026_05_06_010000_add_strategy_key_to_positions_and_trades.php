<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 of the multi-strategy refactor: add `strategy_key` to positions
 * and trades so each row can be attributed to the strategy that opened
 * it. Existing rows are backfilled with 'short_scalp' since that was the
 * only strategy in production before this change.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('strategy_key', 64)->nullable()->after('side');
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->string('strategy_key', 64)->nullable()->after('side');
        });

        // Backfill all pre-existing rows with the legacy strategy name.
        DB::statement("UPDATE positions SET strategy_key = 'short_scalp' WHERE strategy_key IS NULL");
        DB::statement("UPDATE trades SET strategy_key = 'short_scalp' WHERE strategy_key IS NULL");

        // Composite index lets per-strategy `Position::open()` lookups stay
        // fast as the table grows. Trades index supports per-strategy
        // P&L aggregation on the dashboard.
        Schema::table('positions', function (Blueprint $table) {
            $table->index(['strategy_key', 'status'], 'positions_strategy_status_idx');
        });

        Schema::table('trades', function (Blueprint $table) {
            $table->index(['strategy_key', 'created_at'], 'trades_strategy_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('positions_strategy_status_idx');
            $table->dropColumn('strategy_key');
        });
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex('trades_strategy_created_idx');
            $table->dropColumn('strategy_key');
        });
    }
};
