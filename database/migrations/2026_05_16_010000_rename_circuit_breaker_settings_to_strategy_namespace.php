<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename legacy flat circuit-breaker rows in `bot_settings` to the canonical
 * per-strategy namespaced form. After this migration the breaker is scoped
 * per strategy (Phase 1 of the long-strategy overhaul); the existing
 * production override ("20% / 4h, enabled") lands on short_scalp's breaker
 * so its validated behaviour is preserved exactly.
 *
 * Idempotent: when both the legacy and namespaced rows exist (re-run or
 * fresh DB), the legacy row is dropped to avoid a unique-key conflict.
 */
return new class extends Migration {
    private const RENAMES = [
        'circuit_breaker_enabled' => 'strategy.short_scalp.circuit_breaker.enabled',
        'circuit_breaker_drawdown_pct' => 'strategy.short_scalp.circuit_breaker.drawdown_pct',
        'circuit_breaker_window_hours' => 'strategy.short_scalp.circuit_breaker.window_hours',
        'circuit_breaker_cooldown_hours' => 'strategy.short_scalp.circuit_breaker.cooldown_hours',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $hasOld = DB::table('bot_settings')->where('key', $old)->exists();
            $hasNew = DB::table('bot_settings')->where('key', $new)->exists();

            if ($hasOld && $hasNew) {
                DB::table('bot_settings')->where('key', $old)->delete();
            } elseif ($hasOld) {
                DB::table('bot_settings')->where('key', $old)->update(['key' => $new]);
            }
        }
    }

    public function down(): void
    {
        // No reverse: any namespaced-key writes since up() would not be safely
        // reversible. Restore from a pre-deploy backup if needed.
    }
};
