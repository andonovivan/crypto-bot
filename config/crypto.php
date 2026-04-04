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
        'position_size_pct' => (float) env('TRADE_POSITION_SIZE_PCT', 1.0),
        'max_positions' => (int) env('TRADE_MAX_POSITIONS', 30),
        'leverage' => (int) env('TRADE_LEVERAGE', 5),
        'dry_run' => filter_var(env('DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
        'starting_balance' => (float) env('TRADE_STARTING_BALANCE', 10000),
        'dry_run_fee_rate' => (float) env('DRY_RUN_FEE_RATE', 0.0005),
        'watchlist' => env('WATCHLIST', 'BTCUSDT'),
        'max_position_usdt' => (float) env('MAX_POSITION_USDT', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Grid Trading Configuration
    |--------------------------------------------------------------------------
    | Grid strategy: opens multiple concurrent positions per symbol at different
    | price levels. Uses EMA alignment for direction, fixed-% TP/SL per trade.
    | Direction-specific TP/SL: longs get wider stops, shorts get tighter exits.
    */
    'grid' => [
        'scan_interval' => (int) env('GRID_SCAN_INTERVAL', 30),
        'kline_interval' => env('GRID_KLINE_INTERVAL', '1h'),
        'max_hold_minutes' => (int) env('GRID_MAX_HOLD_MINUTES', 1440),
        'rsi_filter' => filter_var(env('GRID_RSI_FILTER', false), FILTER_VALIDATE_BOOLEAN),
        'cooldown_minutes' => (int) env('GRID_COOLDOWN_MINUTES', 1),

        // Indicator settings
        'ema_fast' => (int) env('GRID_EMA_FAST', 5),
        'ema_slow' => (int) env('GRID_EMA_SLOW', 13),
        'rsi_period' => (int) env('GRID_RSI_PERIOD', 7),
        'atr_period' => (int) env('GRID_ATR_PERIOD', 14),
        'kline_limit' => (int) env('GRID_KLINE_LIMIT', 50),
        'rsi_overbought' => (int) env('GRID_RSI_OVERBOUGHT', 80),
        'rsi_oversold' => (int) env('GRID_RSI_OVERSOLD', 20),

        // Grid-specific settings
        'max_per_symbol' => (int) env('GRID_MAX_PER_SYMBOL', 10),
        'spacing_pct' => (float) env('GRID_SPACING_PCT', 0.5),

        // Direction-specific TP/SL
        'take_profit_pct' => (float) env('GRID_TAKE_PROFIT_PCT', 1.68),
        'stop_loss_pct' => (float) env('GRID_STOP_LOSS_PCT', 5.0),
        'long_tp_pct' => (float) env('GRID_LONG_TP_PCT', 1.68),
        'long_sl_pct' => (float) env('GRID_LONG_SL_PCT', 5.0),
        'short_tp_pct' => (float) env('GRID_SHORT_TP_PCT', 1.0),
        'short_sl_pct' => (float) env('GRID_SHORT_SL_PCT', 2.0),

        // Auto-add to losing positions (DCA when signal still confirms direction)
        'auto_add_enabled' => filter_var(env('GRID_AUTO_ADD_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'auto_add_loss_pct' => (float) env('GRID_AUTO_ADD_LOSS_PCT', 1.5),
        'auto_add_max_layers' => (int) env('GRID_AUTO_ADD_MAX_LAYERS', 3),
    ],
];
