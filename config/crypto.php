<?php

$isTestnet = filter_var(env('BINANCE_TESTNET', true), FILTER_VALIDATE_BOOLEAN);

return [
    /*
    |--------------------------------------------------------------------------
    | Exchange Configuration
    |--------------------------------------------------------------------------
    */
    'exchange' => env('EXCHANGE_DRIVER', 'binance'),

    'binance' => [
        'api_key' => env('BINANCE_API_KEY', ''),
        'api_secret' => env('BINANCE_API_SECRET', ''),
        'testnet' => $isTestnet,
        'base_url' => $isTestnet
            ? 'https://testnet.binancefuture.com'
            : 'https://fapi.binance.com',
        'ws_url' => $isTestnet
            ? 'wss://stream.binancefuture.com'
            : 'wss://fstream.binance.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trading Configuration
    |--------------------------------------------------------------------------
    */
    'trading' => [
        'position_size_usdt' => (float) env('TRADE_POSITION_SIZE_USDT', 50),
        'max_positions' => (int) env('TRADE_MAX_POSITIONS', 5),
        'stop_loss_pct' => (float) env('TRADE_STOP_LOSS_PCT', 8),
        'take_profit_pct' => (float) env('TRADE_TAKE_PROFIT_PCT', 15),
        'max_hold_hours' => (int) env('TRADE_MAX_HOLD_HOURS', 24),
        'trailing_stop_activation_pct' => (float) env('TRADE_TRAILING_STOP_ACTIVATION_PCT', 3),
        'trailing_stop_pct' => (float) env('TRADE_TRAILING_STOP_PCT', 3),
        'leverage' => (int) env('TRADE_LEVERAGE', 5),
        'dry_run' => filter_var(env('DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
        'starting_balance' => (float) env('TRADE_STARTING_BALANCE', 10000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pump Detection Configuration
    |--------------------------------------------------------------------------
    */
    'pump_detection' => [
        'min_price_change_pct' => (float) env('PUMP_MIN_PRICE_CHANGE_PCT', 15),
        'min_volume_multiplier' => (float) env('PUMP_MIN_VOLUME_MULTIPLIER', 3),
        'reversal_drop_pct' => (float) env('PUMP_REVERSAL_DROP_PCT', 5),
        'scan_interval_minutes' => (int) env('PUMP_SCAN_INTERVAL_MINUTES', 5),
        'min_volume_usdt' => (float) env('PUMP_MIN_VOLUME_USDT', 5000000),
    ],
];
