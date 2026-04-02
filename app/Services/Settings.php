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
        'stop_loss_pct' => ['config' => 'crypto.trading.stop_loss_pct', 'type' => 'float', 'label' => 'Stop Loss (%)'],
        'take_profit_pct' => ['config' => 'crypto.trading.take_profit_pct', 'type' => 'float', 'label' => 'Take Profit (%)'],
        'max_hold_hours' => ['config' => 'crypto.trading.max_hold_hours', 'type' => 'int', 'label' => 'Max Hold Time (hours)'],
        'retry_cooldown_hours' => ['config' => 'crypto.trading.retry_cooldown_hours', 'type' => 'int', 'label' => 'Retry Cooldown (hours)'],
        'trailing_stop_activation_pct' => ['config' => 'crypto.trading.trailing_stop_activation_pct', 'type' => 'float', 'label' => 'Trailing Stop Activation (%)'],
        'trailing_stop_pct' => ['config' => 'crypto.trading.trailing_stop_pct', 'type' => 'float', 'label' => 'Trailing Stop Distance (%)'],
        'leverage' => ['config' => 'crypto.trading.leverage', 'type' => 'int', 'label' => 'Leverage'],
        'dry_run' => ['config' => 'crypto.trading.dry_run', 'type' => 'bool', 'label' => 'Dry Run Mode'],
        'starting_balance' => ['config' => 'crypto.trading.starting_balance', 'type' => 'float', 'label' => 'Starting Balance (USDT)'],
        'dry_run_fee_rate' => ['config' => 'crypto.trading.dry_run_fee_rate', 'type' => 'float', 'label' => 'Dry Run Fee Rate (0.0005 = 0.05%)'],
        'min_price_change_pct' => ['config' => 'crypto.pump_detection.min_price_change_pct', 'type' => 'float', 'label' => 'Min Price Change (%)'],
        'min_volume_multiplier' => ['config' => 'crypto.pump_detection.min_volume_multiplier', 'type' => 'float', 'label' => 'Min Volume Multiplier'],
        'reversal_drop_pct' => ['config' => 'crypto.pump_detection.reversal_drop_pct', 'type' => 'float', 'label' => 'Reversal Drop (%)'],
        'min_volume_usdt' => ['config' => 'crypto.pump_detection.min_volume_usdt', 'type' => 'float', 'label' => 'Min 24h Volume (USDT)'],

        // Strategy selection
        'strategy' => ['config' => 'crypto.strategy', 'type' => 'string', 'label' => 'Strategy (pump or trend)'],

        // Trend following settings
        'trend_scan_interval' => ['config' => 'crypto.trend.scan_interval', 'type' => 'int', 'label' => 'Trend Scan Interval (seconds)'],
        'trend_min_score' => ['config' => 'crypto.trend.min_score', 'type' => 'int', 'label' => 'Trend Min Score (0-100)'],
        'trend_max_hold_hours' => ['config' => 'crypto.trend.max_hold_hours', 'type' => 'int', 'label' => 'Trend Max Hold Time (hours)'],
        'trend_stop_loss_pct' => ['config' => 'crypto.trend.stop_loss_pct', 'type' => 'float', 'label' => 'Trend Stop Loss (%)'],
        'trend_take_profit_pct' => ['config' => 'crypto.trend.take_profit_pct', 'type' => 'float', 'label' => 'Trend Take Profit (%)'],
        'trend_trailing_stop_activation_pct' => ['config' => 'crypto.trend.trailing_stop_activation_pct', 'type' => 'float', 'label' => 'Trend Trailing Stop Activation (%)'],
        'trend_trailing_stop_pct' => ['config' => 'crypto.trend.trailing_stop_pct', 'type' => 'float', 'label' => 'Trend Trailing Stop Distance (%)'],
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
