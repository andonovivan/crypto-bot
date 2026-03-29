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
        'leverage' => ['config' => 'crypto.trading.leverage', 'type' => 'int', 'label' => 'Leverage'],
        'dry_run' => ['config' => 'crypto.trading.dry_run', 'type' => 'bool', 'label' => 'Dry Run Mode'],
        'starting_balance' => ['config' => 'crypto.trading.starting_balance', 'type' => 'float', 'label' => 'Starting Balance (USDT)'],
        'min_price_change_pct' => ['config' => 'crypto.pump_detection.min_price_change_pct', 'type' => 'float', 'label' => 'Min Price Change (%)'],
        'min_volume_multiplier' => ['config' => 'crypto.pump_detection.min_volume_multiplier', 'type' => 'float', 'label' => 'Min Volume Multiplier'],
        'reversal_drop_pct' => ['config' => 'crypto.pump_detection.reversal_drop_pct', 'type' => 'float', 'label' => 'Reversal Drop (%)'],
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
