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
        'position_size_pct' => (float) env('TRADE_POSITION_SIZE_PCT', 10.0),
        'max_positions' => (int) env('TRADE_MAX_POSITIONS', 10),
        'leverage' => (int) env('TRADE_LEVERAGE', 25),
        'dry_run' => filter_var(env('DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
        'starting_balance' => (float) env('TRADE_STARTING_BALANCE', 10000),
        'dry_run_fee_rate' => (float) env('DRY_RUN_FEE_RATE', 0.0005),
        'trading_paused' => filter_var(env('TRADING_PAUSED', false), FILTER_VALIDATE_BOOLEAN),
        'funding_tracking_enabled' => filter_var(env('FUNDING_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Short-Scalp Strategy Configuration
    |--------------------------------------------------------------------------
    | Shorts pumped (>=25% 24h) or dumping (<=-10% 24h) coins on confirmed
    | 15m downtrend with high leverage for quick 2% profit exits.
    */
    'scalp' => [
        'scan_interval' => (int) env('SCAN_INTERVAL', 30),
        'pump_threshold_pct' => (float) env('PUMP_THRESHOLD_PCT', 25.0),
        'dump_threshold_pct' => (float) env('DUMP_THRESHOLD_PCT', 10.0),
        'min_volume_usdt' => (float) env('MIN_VOLUME_USDT', 10_000_000),
        'ema_fast' => (int) env('EMA_FAST', 9),
        'ema_slow' => (int) env('EMA_SLOW', 21),
        'take_profit_pct' => (float) env('TAKE_PROFIT_PCT', 2.0),
        'stop_loss_pct' => (float) env('STOP_LOSS_PCT', 1.0),
        'max_hold_minutes' => (int) env('MAX_HOLD_MINUTES', 120),
        'cooldown_minutes' => (int) env('COOLDOWN_MINUTES', 120),
        'max_candle_body_pct' => (float) env('MAX_CANDLE_BODY_PCT', 3.0),
    ],
];
