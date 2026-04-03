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
        'max_positions' => (int) env('TRADE_MAX_POSITIONS', 2),
        'leverage' => (int) env('TRADE_LEVERAGE', 5),
        'dry_run' => filter_var(env('DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
        'starting_balance' => (float) env('TRADE_STARTING_BALANCE', 10000),
        'dry_run_fee_rate' => (float) env('DRY_RUN_FEE_RATE', 0.0005),
        'watchlist' => env('WATCHLIST', 'BTCUSDT'),
        'max_position_usdt' => (float) env('MAX_POSITION_USDT', 150),
        'dca_enabled' => filter_var(env('DCA_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'dca_max_layers' => (int) env('DCA_MAX_LAYERS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy Selection
    |--------------------------------------------------------------------------
    */
    'strategy' => env('TRADING_STRATEGY', 'wave'), // 'wave' or 'staircase'

    /*
    |--------------------------------------------------------------------------
    | Wave Rider Configuration
    |--------------------------------------------------------------------------
    */
    'wave' => [
        'scan_interval' => (int) env('WAVE_SCAN_INTERVAL', 30),
        'kline_interval' => env('WAVE_KLINE_INTERVAL', '15m'),
        'ema_fast' => (int) env('WAVE_EMA_FAST', 5),
        'ema_slow' => (int) env('WAVE_EMA_SLOW', 13),
        'rsi_period' => (int) env('WAVE_RSI_PERIOD', 7),
        'atr_period' => (int) env('WAVE_ATR_PERIOD', 14),
        'kline_limit' => (int) env('WAVE_KLINE_LIMIT', 50),
        'tp_atr_multiplier' => (float) env('WAVE_TP_ATR_MULTIPLIER', 1.5),
        'sl_atr_multiplier' => (float) env('WAVE_SL_ATR_MULTIPLIER', 1.0),
        'trailing_activation_atr' => (float) env('WAVE_TRAILING_ACTIVATION_ATR', 0.15),
        'trailing_distance_atr' => (float) env('WAVE_TRAILING_DISTANCE_ATR', 0.2),
        'fee_floor_multiplier' => (float) env('WAVE_FEE_FLOOR_MULTIPLIER', 2.5),
        'max_tp_atr' => (float) env('WAVE_MAX_TP_ATR', 2.0),
        'max_hold_minutes' => (int) env('WAVE_MAX_HOLD_MINUTES', 120),
        'dca_trigger_atr' => (float) env('WAVE_DCA_TRIGGER_ATR', 0.5),
        'rsi_overbought' => (int) env('WAVE_RSI_OVERBOUGHT', 80),
        'rsi_oversold' => (int) env('WAVE_RSI_OVERSOLD', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Staircase Strategy Configuration
    |--------------------------------------------------------------------------
    | Fixed-% TP scalping: ride trends in steps by re-entering after each TP hit.
    | Uses EMA alignment from WaveScanner for direction, but enters on any
    | aligned state (not just fresh crosses). No DCA, no trailing stop.
    */
    'staircase' => [
        'take_profit_pct' => (float) env('STAIRCASE_TAKE_PROFIT_PCT', 1.68),
        'stop_loss_pct' => (float) env('STAIRCASE_STOP_LOSS_PCT', 5.0),
        'max_hold_minutes' => (int) env('STAIRCASE_MAX_HOLD_MINUTES', 1440),
        'rsi_filter' => filter_var(env('STAIRCASE_RSI_FILTER', false), FILTER_VALIDATE_BOOLEAN),
        'scan_interval' => (int) env('STAIRCASE_SCAN_INTERVAL', 30),
        'cooldown_minutes' => (int) env('STAIRCASE_COOLDOWN_MINUTES', 30),
        'kline_interval' => env('STAIRCASE_KLINE_INTERVAL', '1h'),
    ],
];
