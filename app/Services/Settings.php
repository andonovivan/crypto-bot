<?php

namespace App\Services;

use App\Models\BotSetting;

class Settings
{
    public const KEYS = [
        // Generic trading (shared across strategies)
        'position_size_pct' => ['config' => 'crypto.trading.position_size_pct', 'type' => 'float', 'label' => 'Position Size (% of balance)'],
        'max_positions' => ['config' => 'crypto.trading.max_positions', 'type' => 'int', 'label' => 'Max Total Positions'],
        'leverage' => ['config' => 'crypto.trading.leverage', 'type' => 'int', 'label' => 'Leverage'],
        'dry_run' => ['config' => 'crypto.trading.dry_run', 'type' => 'bool', 'label' => 'Dry Run Mode'],
        'starting_balance' => ['config' => 'crypto.trading.starting_balance', 'type' => 'float', 'label' => 'Starting Balance (USDT)'],
        'dry_run_fee_rate' => ['config' => 'crypto.trading.dry_run_fee_rate', 'type' => 'float', 'label' => 'Dry Run Fee Rate (0.0005 = 0.05%)'],
        'dry_run_market_slippage_bps' => ['config' => 'crypto.trading.dry_run_market_slippage_bps', 'type' => 'float', 'label' => 'Dry-Run Market Slippage (basis points per side)'],
        'dry_run_maker_fill_rate' => ['config' => 'crypto.trading.dry_run_maker_fill_rate', 'type' => 'float', 'label' => 'Dry-Run Post-Only Fill Rate (0.0-1.0)'],
        'dry_run_bracket_fail_rate' => ['config' => 'crypto.trading.dry_run_bracket_fail_rate', 'type' => 'float', 'label' => 'Dry-Run Bracket Placement Failure Rate (0.0-1.0)'],
        'trading_paused' => ['config' => 'crypto.trading.trading_paused', 'type' => 'bool', 'label' => 'Pause New Positions'],
        'funding_tracking_enabled' => ['config' => 'crypto.trading.funding_tracking_enabled', 'type' => 'bool', 'label' => 'Track Funding Fees'],
        'ws_prices_enabled' => ['config' => 'crypto.trading.ws_prices_enabled', 'type' => 'bool', 'label' => 'WebSocket Price Stream'],

        // Short-scalp strategy (namespaced as strategy.short_scalp.*)
        'strategy.short_scalp.enabled' => ['config' => 'crypto.strategy.short_scalp.enabled', 'type' => 'bool', 'label' => 'Short-Scalp Strategy Enabled'],
        'strategy.short_scalp.scan_interval' => ['config' => 'crypto.strategy.short_scalp.scan_interval', 'type' => 'int', 'label' => 'Scan Interval (seconds)'],
        'strategy.short_scalp.pump_threshold_pct' => ['config' => 'crypto.strategy.short_scalp.pump_threshold_pct', 'type' => 'float', 'label' => 'Pump Threshold (24h %)'],
        'strategy.short_scalp.pump_max_pct' => ['config' => 'crypto.strategy.short_scalp.pump_max_pct', 'type' => 'float', 'label' => 'Pump Upper Cap (24h %, 0=disabled)'],
        'strategy.short_scalp.dump_threshold_pct' => ['config' => 'crypto.strategy.short_scalp.dump_threshold_pct', 'type' => 'float', 'label' => 'Dump Threshold (24h %, positive)'],
        'strategy.short_scalp.min_volume_usdt' => ['config' => 'crypto.strategy.short_scalp.min_volume_usdt', 'type' => 'float', 'label' => 'Min 24h Volume (USDT)'],
        'strategy.short_scalp.max_volume_usdt' => ['config' => 'crypto.strategy.short_scalp.max_volume_usdt', 'type' => 'float', 'label' => 'Max 24h Volume (USDT, 0=disabled)'],
        'strategy.short_scalp.ema_fast' => ['config' => 'crypto.strategy.short_scalp.ema_fast', 'type' => 'int', 'label' => 'EMA Fast (15m)'],
        'strategy.short_scalp.ema_slow' => ['config' => 'crypto.strategy.short_scalp.ema_slow', 'type' => 'int', 'label' => 'EMA Slow (15m)'],
        'strategy.short_scalp.take_profit_pct' => ['config' => 'crypto.strategy.short_scalp.take_profit_pct', 'type' => 'float', 'label' => 'Take Profit (%)'],
        'strategy.short_scalp.stop_loss_pct' => ['config' => 'crypto.strategy.short_scalp.stop_loss_pct', 'type' => 'float', 'label' => 'Stop Loss (%)'],
        'strategy.short_scalp.max_hold_minutes' => ['config' => 'crypto.strategy.short_scalp.max_hold_minutes', 'type' => 'int', 'label' => 'Max Hold (minutes)'],
        'strategy.short_scalp.cooldown_minutes' => ['config' => 'crypto.strategy.short_scalp.cooldown_minutes', 'type' => 'int', 'label' => 'Cooldown After Close (minutes)'],
        'strategy.short_scalp.failed_entry_cooldown_minutes' => ['config' => 'crypto.strategy.short_scalp.failed_entry_cooldown_minutes', 'type' => 'int', 'label' => 'Cooldown After Failed Entry (minutes)'],
        'strategy.short_scalp.max_candle_body_pct' => ['config' => 'crypto.strategy.short_scalp.max_candle_body_pct', 'type' => 'float', 'label' => 'Max 15m Candle Body (%)'],
        'strategy.short_scalp.min_red_candles' => ['config' => 'crypto.strategy.short_scalp.min_red_candles', 'type' => 'int', 'label' => 'Min Consecutive Red Candles'],
        'strategy.short_scalp.use_post_only_entry' => ['config' => 'crypto.strategy.short_scalp.use_post_only_entry', 'type' => 'bool', 'label' => 'Post-Only Limit Entry (maker fee)'],
        'strategy.short_scalp.limit_order_timeout_seconds' => ['config' => 'crypto.strategy.short_scalp.limit_order_timeout_seconds', 'type' => 'int', 'label' => 'Post-Only Fill Timeout (sec)'],
        'strategy.short_scalp.htf_filter_enabled' => ['config' => 'crypto.strategy.short_scalp.htf_filter_enabled', 'type' => 'bool', 'label' => 'Higher-TF Trend Filter (1h)'],
        'strategy.short_scalp.htf_ema_period' => ['config' => 'crypto.strategy.short_scalp.htf_ema_period', 'type' => 'int', 'label' => 'HTF EMA Period (1h)'],
        'strategy.short_scalp.atr_sl_enabled' => ['config' => 'crypto.strategy.short_scalp.atr_sl_enabled', 'type' => 'bool', 'label' => 'ATR-Based Stop Loss'],
        'strategy.short_scalp.atr_sl_multiplier' => ['config' => 'crypto.strategy.short_scalp.atr_sl_multiplier', 'type' => 'float', 'label' => 'ATR SL Multiplier'],
        'strategy.short_scalp.partial_tp_trigger_pct' => ['config' => 'crypto.strategy.short_scalp.partial_tp_trigger_pct', 'type' => 'float', 'label' => 'Partial TP Trigger (%, 0=off)'],
        'strategy.short_scalp.partial_tp_size_pct' => ['config' => 'crypto.strategy.short_scalp.partial_tp_size_pct', 'type' => 'float', 'label' => 'Partial TP Size (% of position)'],
        'strategy.short_scalp.trailing_tp_enabled' => ['config' => 'crypto.strategy.short_scalp.trailing_tp_enabled', 'type' => 'bool', 'label' => 'Trailing Take Profit'],
        'strategy.short_scalp.trailing_tp_arm_pct' => ['config' => 'crypto.strategy.short_scalp.trailing_tp_arm_pct', 'type' => 'float', 'label' => 'Trailing TP Arm Threshold (% favorable)'],
        'strategy.short_scalp.trailing_tp_trail_pct' => ['config' => 'crypto.strategy.short_scalp.trailing_tp_trail_pct', 'type' => 'float', 'label' => 'Trailing TP Trail Distance (%)'],
        'strategy.short_scalp.strict_downtrend_enabled' => ['config' => 'crypto.strategy.short_scalp.strict_downtrend_enabled', 'type' => 'bool', 'label' => 'Strict 15m Downtrend Confirmation'],
        'strategy.short_scalp.circuit_breaker.enabled' => ['config' => 'crypto.strategy.short_scalp.circuit_breaker.enabled', 'type' => 'bool', 'label' => 'Short Circuit Breaker Enabled'],
        'strategy.short_scalp.circuit_breaker.drawdown_pct' => ['config' => 'crypto.strategy.short_scalp.circuit_breaker.drawdown_pct', 'type' => 'float', 'label' => 'Short Breaker Drawdown Threshold (%)'],
        'strategy.short_scalp.circuit_breaker.window_hours' => ['config' => 'crypto.strategy.short_scalp.circuit_breaker.window_hours', 'type' => 'float', 'label' => 'Short Breaker Window (hours, 0=all-time peak)'],
        'strategy.short_scalp.circuit_breaker.cooldown_hours' => ['config' => 'crypto.strategy.short_scalp.circuit_breaker.cooldown_hours', 'type' => 'float', 'label' => 'Short Breaker Cooldown (hours)'],

        // Long-strategy variants under Phase-4 sweep. Each variant only
        // exposes its master `enabled` toggle so `--override=strategy.<key>.enabled=true`
        // works. All other gate parameters fall back to hardcoded defaults
        // inside the variant's scanner.
        'strategy.long_microdump.enabled' => ['config' => 'crypto.strategy.long_microdump.enabled', 'type' => 'bool', 'label' => 'Long Microdump Enabled'],
        'strategy.long_milddump.enabled' => ['config' => 'crypto.strategy.long_milddump.enabled', 'type' => 'bool', 'label' => 'Long Milddump Enabled'],
        'strategy.long_bigdump.enabled' => ['config' => 'crypto.strategy.long_bigdump.enabled', 'type' => 'bool', 'label' => 'Long Bigdump Enabled'],
        'strategy.long_extremedump.enabled' => ['config' => 'crypto.strategy.long_extremedump.enabled', 'type' => 'bool', 'label' => 'Long Extremedump Enabled'],
        'strategy.long_oversold_strict.enabled' => ['config' => 'crypto.strategy.long_oversold_strict.enabled', 'type' => 'bool', 'label' => 'Long Oversold-Strict Enabled'],
        'strategy.long_shallowpull.enabled' => ['config' => 'crypto.strategy.long_shallowpull.enabled', 'type' => 'bool', 'label' => 'Long Shallowpull Enabled'],
        'strategy.long_deeppull.enabled' => ['config' => 'crypto.strategy.long_deeppull.enabled', 'type' => 'bool', 'label' => 'Long Deeppull Enabled'],
        'strategy.long_consolidation_break.enabled' => ['config' => 'crypto.strategy.long_consolidation_break.enabled', 'type' => 'bool', 'label' => 'Long Consolidation-Break Enabled'],
        'strategy.long_breakout_new_high.enabled' => ['config' => 'crypto.strategy.long_breakout_new_high.enabled', 'type' => 'bool', 'label' => 'Long Breakout-New-High Enabled'],
        'strategy.long_range_reclaim.enabled' => ['config' => 'crypto.strategy.long_range_reclaim.enabled', 'type' => 'bool', 'label' => 'Long Range-Reclaim Enabled'],
        'strategy.long_lowpump.enabled' => ['config' => 'crypto.strategy.long_lowpump.enabled', 'type' => 'bool', 'label' => 'Long Lowpump Enabled'],
        'strategy.long_midpump.enabled' => ['config' => 'crypto.strategy.long_midpump.enabled', 'type' => 'bool', 'label' => 'Long Midpump Enabled'],
        'strategy.long_highpump.enabled' => ['config' => 'crypto.strategy.long_highpump.enabled', 'type' => 'bool', 'label' => 'Long Highpump Enabled'],
        'strategy.long_extremepump.enabled' => ['config' => 'crypto.strategy.long_extremepump.enabled', 'type' => 'bool', 'label' => 'Long Extremepump Enabled'],
        'strategy.long_thinvol_pump.enabled' => ['config' => 'crypto.strategy.long_thinvol_pump.enabled', 'type' => 'bool', 'label' => 'Long Thinvol-Pump Enabled'],
        'strategy.long_thickvol_pump.enabled' => ['config' => 'crypto.strategy.long_thickvol_pump.enabled', 'type' => 'bool', 'label' => 'Long Thickvol-Pump Enabled'],
        'strategy.long_btc_aligned.enabled' => ['config' => 'crypto.strategy.long_btc_aligned.enabled', 'type' => 'bool', 'label' => 'Long BTC-Aligned Enabled'],
        'strategy.long_btc_inverted.enabled' => ['config' => 'crypto.strategy.long_btc_inverted.enabled', 'type' => 'bool', 'label' => 'Long BTC-Inverted Enabled'],
    ];

    /**
     * Lazily-built dynamic keys (e.g. per-variant breaker tuning). Returned by
     * the public keys() method alongside the static KEYS const. Kept out of
     * KEYS because PHP doesn't allow method calls in const initializers; this
     * is the workaround for adding 20 × 4 = 80 per-variant breaker keys
     * without inlining every entry.
     *
     * The variant list is the source of truth — adding a variant adds its
     * breaker keys here automatically. The 19 losing variants drop out
     * when their entries are removed in Phase 5 cleanup.
     */
    private static ?array $dynamicKeys = null;

    private static function dynamicKeys(): array
    {
        if (self::$dynamicKeys !== null) {
            return self::$dynamicKeys;
        }
        $variants = [
            'long_microdump', 'long_milddump', 'long_bigdump', 'long_extremedump',
            'long_oversold_strict', 'long_shallowpull', 'long_deeppull',
            'long_consolidation_break',
            'long_breakout_new_high', 'long_range_reclaim', 'long_lowpump',
            'long_midpump', 'long_highpump', 'long_extremepump',
            'long_thinvol_pump', 'long_thickvol_pump',
            'long_btc_aligned', 'long_btc_inverted',
        ];
        $out = [];
        foreach ($variants as $v) {
            $out["strategy.{$v}.circuit_breaker.enabled"] = ['config' => "crypto.strategy.{$v}.circuit_breaker.enabled", 'type' => 'bool', 'label' => "$v Breaker Enabled"];
            $out["strategy.{$v}.circuit_breaker.drawdown_pct"] = ['config' => "crypto.strategy.{$v}.circuit_breaker.drawdown_pct", 'type' => 'float', 'label' => "$v Breaker Drawdown %"];
            $out["strategy.{$v}.circuit_breaker.window_hours"] = ['config' => "crypto.strategy.{$v}.circuit_breaker.window_hours", 'type' => 'float', 'label' => "$v Breaker Window (hours)"];
            $out["strategy.{$v}.circuit_breaker.cooldown_hours"] = ['config' => "crypto.strategy.{$v}.circuit_breaker.cooldown_hours", 'type' => 'float', 'label' => "$v Breaker Cooldown (hours)"];
        }
        return self::$dynamicKeys = $out;
    }

    /**
     * Full key registry: static KEYS const ∪ dynamic per-variant entries.
     * Callers should prefer this over reading KEYS directly when the lookup
     * may target a dynamically-registered variant key.
     */
    public static function keys(): array
    {
        return self::KEYS + self::dynamicKeys();
    }

    /**
     * Legacy flat-key → namespaced-key aliases. In effect through Phase 4 to
     * keep internal callers (TradingEngine, ShortScanner, BotRun, dashboard
     * controllers) reading the same values both before and after the
     * settings rename migration. Drop in Phase 4 once every call site uses
     * the namespaced keys directly.
     */
    public const ALIASES = [
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
        // Pre-Phase-1 risk controls — flat keys land on short_scalp's breaker so
        // dashboard rows and CLAUDE.md's documented "20% / 4h" production override
        // continue to resolve after the per-strategy breaker rewrite.
        'circuit_breaker_enabled' => 'strategy.short_scalp.circuit_breaker.enabled',
        'circuit_breaker_drawdown_pct' => 'strategy.short_scalp.circuit_breaker.drawdown_pct',
        'circuit_breaker_window_hours' => 'strategy.short_scalp.circuit_breaker.window_hours',
        'circuit_breaker_cooldown_hours' => 'strategy.short_scalp.circuit_breaker.cooldown_hours',
    ];

    /** Process-local overrides that shadow the DB for the duration of a single process. */
    private static array $overrides = [];

    /** Resolve a legacy flat key to its canonical namespaced key (or return as-is). */
    private static function canonical(string $key): string
    {
        return self::ALIASES[$key] ?? $key;
    }

    /**
     * Override a setting for this PHP process only — no DB write, no effect on
     * other containers. Used by bot:backtest to flip dry_run=true and set a
     * custom starting_balance without stomping on the live bot's shared state.
     *
     * Both legacy flat keys and canonical namespaced keys are accepted; the
     * override is stored under the canonical key so subsequent get() calls
     * via either form find it.
     */
    public static function override(string $key, mixed $value): void
    {
        self::$overrides[self::canonical($key)] = $value;
    }

    public static function clearOverrides(): void
    {
        self::$overrides = [];
    }

    public static function get(string $key): mixed
    {
        $key = self::canonical($key);

        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $registry = self::keys();
        $meta = $registry[$key] ?? null;

        if (! $meta) {
            return null;
        }

        $dbValue = BotSetting::get($key);

        if ($dbValue !== null) {
            return $dbValue;
        }

        return config($meta['config']);
    }

    public static function all(): array
    {
        $descriptions = config('settings_meta.descriptions', []);
        $constraints = config('settings_meta.constraints', []);
        $settings = [];

        foreach (self::keys() as $key => $meta) {
            $settings[$key] = [
                'value' => self::get($key),
                'type' => $meta['type'],
                'label' => $meta['label'],
                'description' => $descriptions[$key] ?? null,
                'constraints' => $constraints[$key] ?? null,
            ];
        }

        return $settings;
    }

    /**
     * Returns the settings grouping metadata for the UI. Groups are defined in
     * config/settings_meta.php and reference keys from self::KEYS. Any key not
     * referenced by a group falls into a synthetic "other" bucket.
     */
    public static function groups(): array
    {
        $groups = config('settings_meta.groups', []);
        $registry = self::keys();
        $assigned = [];
        $out = [];

        foreach ($groups as $group) {
            $keys = array_values(array_filter(
                $group['keys'] ?? [],
                fn (string $k) => array_key_exists($k, $registry)
            ));
            array_push($assigned, ...$keys);
            $out[] = [
                'id' => $group['id'],
                'title' => $group['title'],
                'description' => $group['description'] ?? null,
                'keys' => $keys,
            ];
        }

        $other = array_values(array_diff(array_keys($registry), $assigned));
        if ($other) {
            $out[] = [
                'id' => 'other',
                'title' => 'Other',
                'description' => null,
                'keys' => $other,
            ];
        }

        return $out;
    }

    public static function set(string $key, mixed $value): void
    {
        $key = self::canonical($key);
        $registry = self::keys();
        $meta = $registry[$key] ?? null;

        if (! $meta) {
            return;
        }

        BotSetting::set($key, $value, $meta['type']);
    }
}
