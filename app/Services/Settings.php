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
        'position_size_pct' => ['config' => 'crypto.trading.position_size_pct', 'type' => 'float', 'label' => 'Position Size (% of balance)'],
        'max_positions' => ['config' => 'crypto.trading.max_positions', 'type' => 'int', 'label' => 'Max Total Positions'],
        'leverage' => ['config' => 'crypto.trading.leverage', 'type' => 'int', 'label' => 'Leverage'],
        'dry_run' => ['config' => 'crypto.trading.dry_run', 'type' => 'bool', 'label' => 'Dry Run Mode'],
        'starting_balance' => ['config' => 'crypto.trading.starting_balance', 'type' => 'float', 'label' => 'Starting Balance (USDT)'],
        'dry_run_fee_rate' => ['config' => 'crypto.trading.dry_run_fee_rate', 'type' => 'float', 'label' => 'Dry Run Fee Rate (0.0005 = 0.05%)'],
        'watchlist' => ['config' => 'crypto.trading.watchlist', 'type' => 'string', 'label' => 'Watchlist (comma-separated symbols)'],
        'max_position_usdt' => ['config' => 'crypto.trading.max_position_usdt', 'type' => 'float', 'label' => 'Max Position Size (USDT)'],
        'trading_paused' => ['config' => 'crypto.trading.trading_paused', 'type' => 'bool', 'label' => 'Pause New Positions'],
        'funding_tracking_enabled' => ['config' => 'crypto.trading.funding_tracking_enabled', 'type' => 'bool', 'label' => 'Track Funding Fees'],

        // Grid trading settings
        'grid_scan_interval' => ['config' => 'crypto.grid.scan_interval', 'type' => 'int', 'label' => 'Scan Interval (seconds)'],
        'grid_kline_interval' => ['config' => 'crypto.grid.kline_interval', 'type' => 'string', 'label' => 'Kline Interval'],
        'grid_max_hold_minutes' => ['config' => 'crypto.grid.max_hold_minutes', 'type' => 'int', 'label' => 'Max Hold Time (minutes)'],
        'grid_rsi_filter' => ['config' => 'crypto.grid.rsi_filter', 'type' => 'bool', 'label' => 'RSI Filter'],
        'grid_cooldown_minutes' => ['config' => 'crypto.grid.cooldown_minutes', 'type' => 'int', 'label' => 'Cooldown After Close (minutes)'],

        // Indicator settings
        'grid_ema_fast' => ['config' => 'crypto.grid.ema_fast', 'type' => 'int', 'label' => 'EMA Fast Period'],
        'grid_ema_slow' => ['config' => 'crypto.grid.ema_slow', 'type' => 'int', 'label' => 'EMA Slow Period'],
        'grid_rsi_period' => ['config' => 'crypto.grid.rsi_period', 'type' => 'int', 'label' => 'RSI Period'],
        'grid_atr_period' => ['config' => 'crypto.grid.atr_period', 'type' => 'int', 'label' => 'ATR Period'],
        'grid_kline_limit' => ['config' => 'crypto.grid.kline_limit', 'type' => 'int', 'label' => 'Kline Candles'],
        'grid_rsi_overbought' => ['config' => 'crypto.grid.rsi_overbought', 'type' => 'int', 'label' => 'RSI Overbought'],
        'grid_rsi_oversold' => ['config' => 'crypto.grid.rsi_oversold', 'type' => 'int', 'label' => 'RSI Oversold'],

        // Grid-specific settings
        'grid_max_per_symbol' => ['config' => 'crypto.grid.max_per_symbol', 'type' => 'int', 'label' => 'Max Positions Per Symbol'],
        'grid_spacing_pct' => ['config' => 'crypto.grid.spacing_pct', 'type' => 'float', 'label' => 'Grid Spacing (% between entries)'],

        // Direction-specific TP/SL
        'grid_take_profit_pct' => ['config' => 'crypto.grid.take_profit_pct', 'type' => 'float', 'label' => 'Default TP (%)'],
        'grid_stop_loss_pct' => ['config' => 'crypto.grid.stop_loss_pct', 'type' => 'float', 'label' => 'Default SL (%)'],
        'grid_long_tp_pct' => ['config' => 'crypto.grid.long_tp_pct', 'type' => 'float', 'label' => 'Long TP (%)'],
        'grid_long_sl_pct' => ['config' => 'crypto.grid.long_sl_pct', 'type' => 'float', 'label' => 'Long SL (%)'],
        'grid_short_tp_pct' => ['config' => 'crypto.grid.short_tp_pct', 'type' => 'float', 'label' => 'Short TP (%)'],
        'grid_short_sl_pct' => ['config' => 'crypto.grid.short_sl_pct', 'type' => 'float', 'label' => 'Short SL (%)'],

        // Auto-add to losing positions
        'grid_auto_add_enabled' => ['config' => 'crypto.grid.auto_add_enabled', 'type' => 'bool', 'label' => 'Auto Add to Losing Positions'],
        'grid_auto_add_sl_proximity_pct' => ['config' => 'crypto.grid.auto_add_sl_proximity_pct', 'type' => 'float', 'label' => 'Auto Add SL Proximity (%)'],
        'grid_auto_add_max_layers' => ['config' => 'crypto.grid.auto_add_max_layers', 'type' => 'int', 'label' => 'Auto Add Max Layers'],
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
