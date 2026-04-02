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
        'stop_loss_pct' => (float) env('TRADE_STOP_LOSS_PCT', 8),
        'take_profit_pct' => (float) env('TRADE_TAKE_PROFIT_PCT', 15),
        'max_hold_hours' => (int) env('TRADE_MAX_HOLD_HOURS', 24),
        'retry_cooldown_hours' => (int) env('TRADE_RETRY_COOLDOWN_HOURS', 24),
        'trailing_stop_activation_pct' => (float) env('TRADE_TRAILING_STOP_ACTIVATION_PCT', 3),
        'trailing_stop_pct' => (float) env('TRADE_TRAILING_STOP_PCT', 3),
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
    'strategy' => env('TRADING_STRATEGY', 'wave'), // 'wave', 'trend', or 'pump'

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

    /*
    |--------------------------------------------------------------------------
    | Trend Following Configuration
    |--------------------------------------------------------------------------
    */
    'trend' => [
        'scan_interval' => (int) env('TREND_SCAN_INTERVAL', 120),
        'min_score' => (int) env('TREND_MIN_SCORE', 75),
        'max_hold_hours' => (int) env('TREND_MAX_HOLD_HOURS', 2),
        'stop_loss_pct' => (float) env('TREND_STOP_LOSS_PCT', 2.5),
        'take_profit_pct' => (float) env('TREND_TAKE_PROFIT_PCT', 5),
        'trailing_stop_activation_pct' => (float) env('TREND_TRAILING_STOP_ACTIVATION_PCT', 1.5),
        'trailing_stop_pct' => (float) env('TREND_TRAILING_STOP_PCT', 1.5),
    ],

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
];
