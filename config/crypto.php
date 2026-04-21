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
        'ws_prices_enabled' => filter_var(env('WS_PRICES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
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
        'failed_entry_cooldown_minutes' => (int) env('FAILED_ENTRY_COOLDOWN_MINUTES', 360),
        'max_candle_body_pct' => (float) env('MAX_CANDLE_BODY_PCT', 3.0),
        'min_red_candles' => (int) env('MIN_RED_CANDLES', 2),
        'use_post_only_entry' => filter_var(env('USE_POST_ONLY_ENTRY', true), FILTER_VALIDATE_BOOLEAN),
        'limit_order_timeout_seconds' => (int) env('LIMIT_ORDER_TIMEOUT_SECONDS', 3),

        // Higher-timeframe trend filter: reject candidates whose 1h close is
        // still above the 1h EMA. Catches 15m-down setups that are actually
        // pullbacks inside a larger uptrend (bounce tops).
        'htf_filter_enabled' => filter_var(env('HTF_FILTER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'htf_ema_period' => (int) env('HTF_EMA_PERIOD', 21),

        // ATR-based stop-loss: SL = entry ± (multiplier × ATR14 on 15m).
        // Gives stops a noise-proportional buffer on volatile pump/dump coins
        // instead of a fixed %. Disabled falls back to stop_loss_pct.
        'atr_sl_enabled' => filter_var(env('ATR_SL_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'atr_sl_multiplier' => (float) env('ATR_SL_MULTIPLIER', 1.5),

        // Partial take-profit: when the trade moves favorably by
        // partial_tp_trigger_pct, close partial_tp_size_pct of the position at
        // market. The remaining portion runs to SL/TP/expiry under the existing
        // brackets (reduceOnly=true, so they naturally close only what's left).
        // Set trigger to 0 to disable.
        'partial_tp_trigger_pct' => (float) env('PARTIAL_TP_TRIGGER_PCT', 1.0),
        'partial_tp_size_pct' => (float) env('PARTIAL_TP_SIZE_PCT', 50.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Controls
    |--------------------------------------------------------------------------
    | Drawdown circuit breaker: halt new entries when realized P&L over a
    | rolling window represents >= threshold % of the wallet at window start.
    | Existing positions continue to be managed (SL/TP/expiry); only new
    | entries are blocked, for cooldown_hours. Defaults are a conservative
    | "25% in 24h, pause 24h" — matches a typical trader's risk-off reflex.
    */
    'risk' => [
        'circuit_breaker_enabled' => filter_var(env('CIRCUIT_BREAKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'circuit_breaker_drawdown_pct' => (float) env('CIRCUIT_BREAKER_DRAWDOWN_PCT', 25.0),
        'circuit_breaker_window_hours' => (float) env('CIRCUIT_BREAKER_WINDOW_HOURS', 24),
        'circuit_breaker_cooldown_hours' => (float) env('CIRCUIT_BREAKER_COOLDOWN_HOURS', 24),
    ],
];
