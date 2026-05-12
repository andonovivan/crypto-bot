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

        // Long-continuation strategy (rides +50–100% 24h pumps)
        'strategy.long_continuation.enabled' => ['config' => 'crypto.strategy.long_continuation.enabled', 'type' => 'bool', 'label' => 'Long-Continuation Strategy Enabled'],
        'strategy.long_continuation.pump_threshold_pct' => ['config' => 'crypto.strategy.long_continuation.pump_threshold_pct', 'type' => 'float', 'label' => 'Long Pump Lower Bound (24h %)'],
        'strategy.long_continuation.pump_max_pct' => ['config' => 'crypto.strategy.long_continuation.pump_max_pct', 'type' => 'float', 'label' => 'Long Pump Upper Bound (24h %, 0=no cap)'],
        'strategy.long_continuation.min_volume_usdt' => ['config' => 'crypto.strategy.long_continuation.min_volume_usdt', 'type' => 'float', 'label' => 'Long Min 24h Volume (USDT)'],
        'strategy.long_continuation.max_volume_usdt' => ['config' => 'crypto.strategy.long_continuation.max_volume_usdt', 'type' => 'float', 'label' => 'Long Max 24h Volume (USDT, 0=no cap)'],
        'strategy.long_continuation.ema_fast' => ['config' => 'crypto.strategy.long_continuation.ema_fast', 'type' => 'int', 'label' => 'Long EMA Fast (15m)'],
        'strategy.long_continuation.ema_slow' => ['config' => 'crypto.strategy.long_continuation.ema_slow', 'type' => 'int', 'label' => 'Long EMA Slow (15m)'],
        'strategy.long_continuation.min_green_candles' => ['config' => 'crypto.strategy.long_continuation.min_green_candles', 'type' => 'int', 'label' => 'Long Min Consecutive Green Candles'],
        'strategy.long_continuation.max_candle_body_pct' => ['config' => 'crypto.strategy.long_continuation.max_candle_body_pct', 'type' => 'float', 'label' => 'Long Max 15m Candle Body (%)'],
        'strategy.long_continuation.funding_max_rate' => ['config' => 'crypto.strategy.long_continuation.funding_max_rate', 'type' => 'float', 'label' => 'Long Funding Max Rate (longs pay positive — skip if above)'],
        'strategy.long_continuation.strict_uptrend_enabled' => ['config' => 'crypto.strategy.long_continuation.strict_uptrend_enabled', 'type' => 'bool', 'label' => 'Long Strict 15m Uptrend Confirmation'],
        'strategy.long_continuation.htf_filter_enabled' => ['config' => 'crypto.strategy.long_continuation.htf_filter_enabled', 'type' => 'bool', 'label' => 'Long Higher-TF Trend Filter (1h)'],
        'strategy.long_continuation.htf_ema_period' => ['config' => 'crypto.strategy.long_continuation.htf_ema_period', 'type' => 'int', 'label' => 'Long HTF EMA Period (1h)'],
        'strategy.long_continuation.stop_loss_pct' => ['config' => 'crypto.strategy.long_continuation.stop_loss_pct', 'type' => 'float', 'label' => 'Long Stop Loss (% below entry)'],
        'strategy.long_continuation.atr_sl_enabled' => ['config' => 'crypto.strategy.long_continuation.atr_sl_enabled', 'type' => 'bool', 'label' => 'Long ATR-Based Stop Loss'],
        'strategy.long_continuation.atr_sl_multiplier' => ['config' => 'crypto.strategy.long_continuation.atr_sl_multiplier', 'type' => 'float', 'label' => 'Long ATR SL Multiplier'],
        'strategy.long_continuation.take_profit_pct' => ['config' => 'crypto.strategy.long_continuation.take_profit_pct', 'type' => 'float', 'label' => 'Long Take Profit (%)'],
        'strategy.long_continuation.partial_tp_trigger_pct' => ['config' => 'crypto.strategy.long_continuation.partial_tp_trigger_pct', 'type' => 'float', 'label' => 'Long Partial TP Trigger (%, 0=off)'],
        'strategy.long_continuation.partial_tp_size_pct' => ['config' => 'crypto.strategy.long_continuation.partial_tp_size_pct', 'type' => 'float', 'label' => 'Long Partial TP Size (% of position)'],
        'strategy.long_continuation.trailing_tp_enabled' => ['config' => 'crypto.strategy.long_continuation.trailing_tp_enabled', 'type' => 'bool', 'label' => 'Long Trailing Take Profit'],
        'strategy.long_continuation.trailing_tp_arm_pct' => ['config' => 'crypto.strategy.long_continuation.trailing_tp_arm_pct', 'type' => 'float', 'label' => 'Long Trailing TP Arm Threshold (% favorable)'],
        'strategy.long_continuation.trailing_tp_trail_pct' => ['config' => 'crypto.strategy.long_continuation.trailing_tp_trail_pct', 'type' => 'float', 'label' => 'Long Trailing TP Trail Distance (%)'],
        'strategy.long_continuation.max_hold_minutes' => ['config' => 'crypto.strategy.long_continuation.max_hold_minutes', 'type' => 'int', 'label' => 'Long Max Hold (minutes)'],
        'strategy.long_continuation.cooldown_minutes' => ['config' => 'crypto.strategy.long_continuation.cooldown_minutes', 'type' => 'int', 'label' => 'Long Cooldown After Close (minutes)'],
        'strategy.long_continuation.failed_entry_cooldown_minutes' => ['config' => 'crypto.strategy.long_continuation.failed_entry_cooldown_minutes', 'type' => 'int', 'label' => 'Long Cooldown After Failed Entry (minutes)'],
        'strategy.long_continuation.use_post_only_entry' => ['config' => 'crypto.strategy.long_continuation.use_post_only_entry', 'type' => 'bool', 'label' => 'Long Post-Only Limit Entry'],
        'strategy.long_continuation.limit_order_timeout_seconds' => ['config' => 'crypto.strategy.long_continuation.limit_order_timeout_seconds', 'type' => 'int', 'label' => 'Long Post-Only Fill Timeout (sec)'],
        'strategy.long_continuation.max_positions' => ['config' => 'crypto.strategy.long_continuation.max_positions', 'type' => 'int', 'label' => 'Long Max Concurrent Positions (sub-cap)'],

        // Risk controls — drawdown circuit breaker
        'circuit_breaker_enabled' => ['config' => 'crypto.risk.circuit_breaker_enabled', 'type' => 'bool', 'label' => 'Drawdown Circuit Breaker'],
        'circuit_breaker_drawdown_pct' => ['config' => 'crypto.risk.circuit_breaker_drawdown_pct', 'type' => 'float', 'label' => 'Circuit Breaker Drawdown Threshold (%)'],
        'circuit_breaker_window_hours' => ['config' => 'crypto.risk.circuit_breaker_window_hours', 'type' => 'float', 'label' => 'Circuit Breaker Window (hours)'],
        'circuit_breaker_cooldown_hours' => ['config' => 'crypto.risk.circuit_breaker_cooldown_hours', 'type' => 'float', 'label' => 'Circuit Breaker Cooldown (hours)'],
    ];

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

        $meta = self::KEYS[$key] ?? null;

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

        foreach (self::KEYS as $key => $meta) {
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
        $assigned = [];
        $out = [];

        foreach ($groups as $group) {
            $keys = array_values(array_filter(
                $group['keys'] ?? [],
                fn (string $k) => array_key_exists($k, self::KEYS)
            ));
            array_push($assigned, ...$keys);
            $out[] = [
                'id' => $group['id'],
                'title' => $group['title'],
                'description' => $group['description'] ?? null,
                'keys' => $keys,
            ];
        }

        $other = array_values(array_diff(array_keys(self::KEYS), $assigned));
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
        $meta = self::KEYS[$key] ?? null;

        if (! $meta) {
            return;
        }

        BotSetting::set($key, $value, $meta['type']);
    }
}
