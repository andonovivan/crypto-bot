<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename short-scalp settings rows in `bot_settings` from the legacy flat
 * keys (e.g. `pump_threshold_pct`) to the canonical namespaced keys
 * (`strategy.short_scalp.pump_threshold_pct`) introduced by the
 * multi-strategy refactor.
 *
 * Idempotent: if a target row already exists (re-run, or a fresh DB that
 * never had the legacy name), the legacy row is dropped to avoid a
 * unique-key conflict on `key`.
 *
 * One-way only — there is no down() because reversing the rename would
 * be fragile after any new namespaced-key writes have happened. A pre-
 * migration backup of bot_settings sits in storage/backups/.
 */
return new class extends Migration {
    private const RENAMES = [
        'scan_interval' => 'strategy.short_scalp.scan_interval',
        'pump_threshold_pct' => 'strategy.short_scalp.pump_threshold_pct',
        'pump_max_pct' => 'strategy.short_scalp.pump_max_pct',
        'dump_threshold_pct' => 'strategy.short_scalp.dump_threshold_pct',
        'min_volume_usdt' => 'strategy.short_scalp.min_volume_usdt',
        'max_volume_usdt' => 'strategy.short_scalp.max_volume_usdt',
        'ema_fast' => 'strategy.short_scalp.ema_fast',
        'ema_slow' => 'strategy.short_scalp.ema_slow',
        'take_profit_pct' => 'strategy.short_scalp.take_profit_pct',
        'stop_loss_pct' => 'strategy.short_scalp.stop_loss_pct',
        'max_hold_minutes' => 'strategy.short_scalp.max_hold_minutes',
        'cooldown_minutes' => 'strategy.short_scalp.cooldown_minutes',
        'failed_entry_cooldown_minutes' => 'strategy.short_scalp.failed_entry_cooldown_minutes',
        'max_candle_body_pct' => 'strategy.short_scalp.max_candle_body_pct',
        'min_red_candles' => 'strategy.short_scalp.min_red_candles',
        'use_post_only_entry' => 'strategy.short_scalp.use_post_only_entry',
        'limit_order_timeout_seconds' => 'strategy.short_scalp.limit_order_timeout_seconds',
        'htf_filter_enabled' => 'strategy.short_scalp.htf_filter_enabled',
        'htf_ema_period' => 'strategy.short_scalp.htf_ema_period',
        'atr_sl_enabled' => 'strategy.short_scalp.atr_sl_enabled',
        'atr_sl_multiplier' => 'strategy.short_scalp.atr_sl_multiplier',
        'partial_tp_trigger_pct' => 'strategy.short_scalp.partial_tp_trigger_pct',
        'partial_tp_size_pct' => 'strategy.short_scalp.partial_tp_size_pct',
        'trailing_tp_enabled' => 'strategy.short_scalp.trailing_tp_enabled',
        'trailing_tp_arm_pct' => 'strategy.short_scalp.trailing_tp_arm_pct',
        'trailing_tp_trail_pct' => 'strategy.short_scalp.trailing_tp_trail_pct',
        'strict_downtrend_enabled' => 'strategy.short_scalp.strict_downtrend_enabled',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            $hasOld = DB::table('bot_settings')->where('key', $old)->exists();
            $hasNew = DB::table('bot_settings')->where('key', $new)->exists();

            if ($hasOld && $hasNew) {
                // Already-renamed row wins; drop the stale legacy row.
                DB::table('bot_settings')->where('key', $old)->delete();
            } elseif ($hasOld) {
                DB::table('bot_settings')->where('key', $old)->update(['key' => $new]);
            }
            // else: no-op (neither exists, or already renamed alone — nothing to do).
        }
    }

    public function down(): void
    {
        // Intentionally no reverse: any namespaced-key writes since the up()
        // ran would not be safely reversible. Restore from
        // storage/backups/pre-phase1-*.sql if a rollback is genuinely needed.
    }
};
