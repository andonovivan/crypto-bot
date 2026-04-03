<?php

namespace App\Services;

use App\Models\BotSetting;

/**
 * Reads bot settings from the database, falling back to config/crypto.php defaults.
 */
class Settings
{
    /**
     * All configurable keys with their config path, type, and label.
     */
    public const KEYS = [
        'position_size_usdt' => ['config' => 'crypto.trading.position_size_usdt', 'type' => 'float', 'label' => 'Position Size (USDT)'],
        'max_positions' => ['config' => 'crypto.trading.max_positions', 'type' => 'int', 'label' => 'Max Positions'],
        'leverage' => ['config' => 'crypto.trading.leverage', 'type' => 'int', 'label' => 'Leverage'],
        'dry_run' => ['config' => 'crypto.trading.dry_run', 'type' => 'bool', 'label' => 'Dry Run Mode'],
        'starting_balance' => ['config' => 'crypto.trading.starting_balance', 'type' => 'float', 'label' => 'Starting Balance (USDT)'],
        'dry_run_fee_rate' => ['config' => 'crypto.trading.dry_run_fee_rate', 'type' => 'float', 'label' => 'Dry Run Fee Rate (0.0005 = 0.05%)'],

        // Watchlist & DCA
        'watchlist' => ['config' => 'crypto.trading.watchlist', 'type' => 'string', 'label' => 'Watchlist (comma-separated symbols)'],
        'max_position_usdt' => ['config' => 'crypto.trading.max_position_usdt', 'type' => 'float', 'label' => 'Max Position Size incl. DCA (USDT)'],
        'dca_enabled' => ['config' => 'crypto.trading.dca_enabled', 'type' => 'bool', 'label' => 'DCA Enabled'],
        'dca_max_layers' => ['config' => 'crypto.trading.dca_max_layers', 'type' => 'int', 'label' => 'DCA Max Layers'],

        // Strategy selection
        'strategy' => ['config' => 'crypto.strategy', 'type' => 'string', 'label' => 'Strategy'],

        // Wave Rider settings
        'wave_scan_interval' => ['config' => 'crypto.wave.scan_interval', 'type' => 'int', 'label' => 'Wave Scan Interval (seconds)'],
        'wave_ema_fast' => ['config' => 'crypto.wave.ema_fast', 'type' => 'int', 'label' => 'Wave EMA Fast Period'],
        'wave_ema_slow' => ['config' => 'crypto.wave.ema_slow', 'type' => 'int', 'label' => 'Wave EMA Slow Period'],
        'wave_rsi_period' => ['config' => 'crypto.wave.rsi_period', 'type' => 'int', 'label' => 'Wave RSI Period'],
        'wave_atr_period' => ['config' => 'crypto.wave.atr_period', 'type' => 'int', 'label' => 'Wave ATR Period'],
        'wave_kline_limit' => ['config' => 'crypto.wave.kline_limit', 'type' => 'int', 'label' => 'Wave Kline Candles'],
        'wave_tp_atr_multiplier' => ['config' => 'crypto.wave.tp_atr_multiplier', 'type' => 'float', 'label' => 'Wave TP (ATR multiplier)'],
        'wave_sl_atr_multiplier' => ['config' => 'crypto.wave.sl_atr_multiplier', 'type' => 'float', 'label' => 'Wave SL (ATR multiplier)'],
        'wave_trailing_activation_atr' => ['config' => 'crypto.wave.trailing_activation_atr', 'type' => 'float', 'label' => 'Wave Trailing Activation (ATR mult)'],
        'wave_trailing_distance_atr' => ['config' => 'crypto.wave.trailing_distance_atr', 'type' => 'float', 'label' => 'Wave Trailing Distance (ATR mult)'],
        'wave_kline_interval' => ['config' => 'crypto.wave.kline_interval', 'type' => 'string', 'label' => 'Wave Kline Interval'],
        'wave_fee_floor_multiplier' => ['config' => 'crypto.wave.fee_floor_multiplier', 'type' => 'float', 'label' => 'Wave Fee Floor Multiplier'],
        'wave_max_tp_atr' => ['config' => 'crypto.wave.max_tp_atr', 'type' => 'float', 'label' => 'Wave Max TP (ATR multiplier)'],
        'wave_max_hold_minutes' => ['config' => 'crypto.wave.max_hold_minutes', 'type' => 'int', 'label' => 'Wave Max Hold Time (minutes)'],
        'wave_dca_trigger_atr' => ['config' => 'crypto.wave.dca_trigger_atr', 'type' => 'float', 'label' => 'Wave DCA Trigger (ATR mult per layer)'],
        'wave_rsi_overbought' => ['config' => 'crypto.wave.rsi_overbought', 'type' => 'int', 'label' => 'Wave RSI Overbought'],
        'wave_rsi_oversold' => ['config' => 'crypto.wave.rsi_oversold', 'type' => 'int', 'label' => 'Wave RSI Oversold'],

        // Staircase strategy settings
        'staircase_take_profit_pct' => ['config' => 'crypto.staircase.take_profit_pct', 'type' => 'float', 'label' => 'Staircase TP (%)'],
        'staircase_stop_loss_pct' => ['config' => 'crypto.staircase.stop_loss_pct', 'type' => 'float', 'label' => 'Staircase SL (%)'],
        'staircase_max_hold_minutes' => ['config' => 'crypto.staircase.max_hold_minutes', 'type' => 'int', 'label' => 'Staircase Max Hold (min)'],
        'staircase_rsi_filter' => ['config' => 'crypto.staircase.rsi_filter', 'type' => 'bool', 'label' => 'Staircase RSI Filter'],
        'staircase_scan_interval' => ['config' => 'crypto.staircase.scan_interval', 'type' => 'int', 'label' => 'Staircase Scan Interval (s)'],
        'staircase_cooldown_minutes' => ['config' => 'crypto.staircase.cooldown_minutes', 'type' => 'int', 'label' => 'Staircase Cooldown After Close (min)'],
        'staircase_kline_interval' => ['config' => 'crypto.staircase.kline_interval', 'type' => 'string', 'label' => 'Staircase Kline Interval'],
    ];

    /**
     * Get a setting value. DB overrides config.
     */
    public static function get(string $key): mixed
    {
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

    /**
     * Get all settings as key => value array.
     */
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

    /**
     * Save a setting to the database.
     */
    public static function set(string $key, mixed $value): void
    {
        $meta = self::KEYS[$key] ?? null;

        if (! $meta) {
            return;
        }

        BotSetting::set($key, $value, $meta['type']);
    }
}
