<?php

namespace App\Services;

use App\Models\BotSetting;

class Settings
{
    public const KEYS = [
        // Generic trading
        'position_size_pct' => ['config' => 'crypto.trading.position_size_pct', 'type' => 'float', 'label' => 'Position Size (% of balance)'],
        'max_positions' => ['config' => 'crypto.trading.max_positions', 'type' => 'int', 'label' => 'Max Total Positions'],
        'leverage' => ['config' => 'crypto.trading.leverage', 'type' => 'int', 'label' => 'Leverage'],
        'dry_run' => ['config' => 'crypto.trading.dry_run', 'type' => 'bool', 'label' => 'Dry Run Mode'],
        'starting_balance' => ['config' => 'crypto.trading.starting_balance', 'type' => 'float', 'label' => 'Starting Balance (USDT)'],
        'dry_run_fee_rate' => ['config' => 'crypto.trading.dry_run_fee_rate', 'type' => 'float', 'label' => 'Dry Run Fee Rate (0.0005 = 0.05%)'],
        'trading_paused' => ['config' => 'crypto.trading.trading_paused', 'type' => 'bool', 'label' => 'Pause New Positions'],
        'funding_tracking_enabled' => ['config' => 'crypto.trading.funding_tracking_enabled', 'type' => 'bool', 'label' => 'Track Funding Fees'],
        'ws_prices_enabled' => ['config' => 'crypto.trading.ws_prices_enabled', 'type' => 'bool', 'label' => 'WebSocket Price Stream'],

        // Short-scalp strategy
        'scan_interval' => ['config' => 'crypto.scalp.scan_interval', 'type' => 'int', 'label' => 'Scan Interval (seconds)'],
        'pump_threshold_pct' => ['config' => 'crypto.scalp.pump_threshold_pct', 'type' => 'float', 'label' => 'Pump Threshold (24h %)'],
        'dump_threshold_pct' => ['config' => 'crypto.scalp.dump_threshold_pct', 'type' => 'float', 'label' => 'Dump Threshold (24h %, positive)'],
        'min_volume_usdt' => ['config' => 'crypto.scalp.min_volume_usdt', 'type' => 'float', 'label' => 'Min 24h Volume (USDT)'],
        'ema_fast' => ['config' => 'crypto.scalp.ema_fast', 'type' => 'int', 'label' => 'EMA Fast (15m)'],
        'ema_slow' => ['config' => 'crypto.scalp.ema_slow', 'type' => 'int', 'label' => 'EMA Slow (15m)'],
        'take_profit_pct' => ['config' => 'crypto.scalp.take_profit_pct', 'type' => 'float', 'label' => 'Take Profit (%)'],
        'stop_loss_pct' => ['config' => 'crypto.scalp.stop_loss_pct', 'type' => 'float', 'label' => 'Stop Loss (%)'],
        'max_hold_minutes' => ['config' => 'crypto.scalp.max_hold_minutes', 'type' => 'int', 'label' => 'Max Hold (minutes)'],
        'cooldown_minutes' => ['config' => 'crypto.scalp.cooldown_minutes', 'type' => 'int', 'label' => 'Cooldown After Close (minutes)'],
        'failed_entry_cooldown_minutes' => ['config' => 'crypto.scalp.failed_entry_cooldown_minutes', 'type' => 'int', 'label' => 'Cooldown After Failed Entry (minutes)'],
        'max_candle_body_pct' => ['config' => 'crypto.scalp.max_candle_body_pct', 'type' => 'float', 'label' => 'Max 15m Candle Body (%)'],
        'min_red_candles' => ['config' => 'crypto.scalp.min_red_candles', 'type' => 'int', 'label' => 'Min Consecutive Red Candles'],
        'use_post_only_entry' => ['config' => 'crypto.scalp.use_post_only_entry', 'type' => 'bool', 'label' => 'Post-Only Limit Entry (maker fee)'],
        'limit_order_timeout_seconds' => ['config' => 'crypto.scalp.limit_order_timeout_seconds', 'type' => 'int', 'label' => 'Post-Only Fill Timeout (sec)'],

        'htf_filter_enabled' => ['config' => 'crypto.scalp.htf_filter_enabled', 'type' => 'bool', 'label' => 'Higher-TF Trend Filter (1h)'],
        'htf_ema_period' => ['config' => 'crypto.scalp.htf_ema_period', 'type' => 'int', 'label' => 'HTF EMA Period (1h)'],

        'atr_sl_enabled' => ['config' => 'crypto.scalp.atr_sl_enabled', 'type' => 'bool', 'label' => 'ATR-Based Stop Loss'],
        'atr_sl_multiplier' => ['config' => 'crypto.scalp.atr_sl_multiplier', 'type' => 'float', 'label' => 'ATR SL Multiplier'],

        'partial_tp_trigger_pct' => ['config' => 'crypto.scalp.partial_tp_trigger_pct', 'type' => 'float', 'label' => 'Partial TP Trigger (%, 0=off)'],
        'partial_tp_size_pct' => ['config' => 'crypto.scalp.partial_tp_size_pct', 'type' => 'float', 'label' => 'Partial TP Size (% of position)'],
    ];

    /** Process-local overrides that shadow the DB for the duration of a single process. */
    private static array $overrides = [];

    /**
     * Override a setting for this PHP process only — no DB write, no effect on
     * other containers. Used by bot:backtest to flip dry_run=true and set a
     * custom starting_balance without stomping on the live bot's shared state.
     */
    public static function override(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function clearOverrides(): void
    {
        self::$overrides = [];
    }

    public static function get(string $key): mixed
    {
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
        $settings = [];

        foreach (self::KEYS as $key => $meta) {
            $settings[$key] = [
                'value' => self::get($key),
                'type' => $meta['type'],
                'label' => $meta['label'],
            ];
        }

        return $settings;
    }

    public static function set(string $key, mixed $value): void
    {
        $meta = self::KEYS[$key] ?? null;

        if (! $meta) {
            return;
        }

        BotSetting::set($key, $value, $meta['type']);
    }
}
