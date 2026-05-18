<?php

$isTestnet = filter_var(env('BINANCE_TESTNET', true), FILTER_VALIDATE_BOOLEAN);

// Short-scalp strategy defaults defined once and surfaced under both
// `crypto.scalp.*` (deprecated alias kept through Phase 4) and
// `crypto.strategy.short_scalp.*` (canonical, namespaced for the multi-strategy
// architecture). Settings::KEYS points at the canonical paths; legacy aliases
// resolve through Settings::ALIASES.
$shortScalpDefaults = [
    'enabled' => filter_var(env('STRATEGY_SHORT_SCALP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'scan_interval' => (int) env('SCAN_INTERVAL', 30),
    'pump_threshold_pct' => (float) env('PUMP_THRESHOLD_PCT', 25.0),
    // Skip pumps above this. Research shows pumps >=50% have a continuation
    // pattern, not mean-reversion (median 24h close +12.6%, not -10%).
    // Set to 0 to disable the upper cap.
    'pump_max_pct' => (float) env('PUMP_MAX_PCT', 50.0),
    'dump_threshold_pct' => (float) env('DUMP_THRESHOLD_PCT', 10.0),
    'min_volume_usdt' => (float) env('MIN_VOLUME_USDT', 10_000_000),
    // Skip pumps with 15m bar quote volume above this. Low/mid volume
    // (1-25M USDT) reverts more reliably than super-thin or super-thick.
    // Set to 0 to disable the upper cap.
    'max_volume_usdt' => (float) env('MAX_VOLUME_USDT', 25_000_000),
    'ema_fast' => (int) env('EMA_FAST', 9),
    'ema_slow' => (int) env('EMA_SLOW', 21),
    'take_profit_pct' => (float) env('TAKE_PROFIT_PCT', 2.0),
    'stop_loss_pct' => (float) env('STOP_LOSS_PCT', 1.0),
    'max_hold_minutes' => (int) env('MAX_HOLD_MINUTES', 120),
    'cooldown_minutes' => (int) env('COOLDOWN_MINUTES', 120),
    // 0 disables the cooldown — same symbol can be retried immediately
    // after a Failed entry. Matches backtest's effective behaviour
    // (replay stubs out order rejections so Failed rows are rare).
    'failed_entry_cooldown_minutes' => (int) env('FAILED_ENTRY_COOLDOWN_MINUTES', 0),
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

    // Trailing take-profit: arms once the position is favorable by
    // trailing_tp_arm_pct, then exits when price retraces by
    // trailing_tp_trail_pct from the running extreme. Replaces the fixed
    // take_profit_pct exit; the fixed stop_loss_pct still applies until
    // the trailing stop ratchets past it. Implemented by tightening
    // stop_loss_price downward (for SHORT) — never widens.
    'trailing_tp_enabled' => filter_var(env('TRAILING_TP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'trailing_tp_arm_pct' => (float) env('TRAILING_TP_ARM_PCT', 2.0),
    'trailing_tp_trail_pct' => (float) env('TRAILING_TP_TRAIL_PCT', 1.5),

    // Strict downtrend confirmation: when true, requires 2 red 15m candles
    // + EMA fast<slow on current and prior + price below fast EMA + body
    // size cap (+ 1h HTF EMA when htf_filter_enabled). Set false to enter
    // immediately at the pump/dump threshold cross (only the funding-rate
    // guard applies). Research showed the strict gate delays entry past
    // the easy reversion.
    'strict_downtrend_enabled' => filter_var(env('STRICT_DOWNTREND_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Per-strategy drawdown circuit breaker. Tracks this strategy's P&L
    // (Trade::sum('pnl' + 'funding_fee') + Position::open()->sum('unrealized_pnl'),
    // both filtered by strategy_key) and halts new entries when drawdown from
    // the peak in the window breaches the threshold.
    //
    // window_hours = 0 preserves the legacy "all-time peak since last trip"
    // semantics that short_scalp's validated 20%/4h config was tested against.
    // window_hours > 0 turns it into a true rolling-window detector.
    //
    // CLAUDE.md's production override is enabled=true / drawdown_pct=20 /
    // cooldown_hours=4 — those values are typically set in the DB via the
    // dashboard, not via env, so the config-default stays conservative.
    'circuit_breaker' => [
        'enabled' => filter_var(env('CIRCUIT_BREAKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'drawdown_pct' => (float) env('CIRCUIT_BREAKER_DRAWDOWN_PCT', 25.0),
        'window_hours' => (float) env('CIRCUIT_BREAKER_WINDOW_HOURS', 0),
        'cooldown_hours' => (float) env('CIRCUIT_BREAKER_COOLDOWN_HOURS', 24),
    ],
];

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
    | Trading Configuration (shared across strategies)
    |--------------------------------------------------------------------------
    */
    'trading' => [
        'position_size_pct' => (float) env('TRADE_POSITION_SIZE_PCT', 10.0),
        'max_positions' => (int) env('TRADE_MAX_POSITIONS', 10),
        'leverage' => (int) env('TRADE_LEVERAGE', 25),
        'dry_run' => filter_var(env('DRY_RUN', true), FILTER_VALIDATE_BOOLEAN),
        'starting_balance' => (float) env('TRADE_STARTING_BALANCE', 10000),
        'dry_run_fee_rate' => (float) env('DRY_RUN_FEE_RATE', 0.0005),
        // Dry-run execution realism: adverse slippage on market fills (basis points
        // per side, default 3 bps), maker fill probability for post-only LIMITs
        // (default 0.6 — 40% fall back to MARKET via the timeout path), and per-bracket
        // placement failure rate (default 0.01 — exercises the placeBrackets fail-safe).
        'dry_run_market_slippage_bps' => (float) env('DRY_RUN_MARKET_SLIPPAGE_BPS', 3.0),
        'dry_run_maker_fill_rate' => (float) env('DRY_RUN_MAKER_FILL_RATE', 0.6),
        'dry_run_bracket_fail_rate' => (float) env('DRY_RUN_BRACKET_FAIL_RATE', 0.01),
        'trading_paused' => filter_var(env('TRADING_PAUSED', false), FILTER_VALIDATE_BOOLEAN),
        'funding_tracking_enabled' => filter_var(env('FUNDING_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'ws_prices_enabled' => filter_var(env('WS_PRICES_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    |--------------------------------------------------------------------------
    | Strategy Configurations (per-strategy keys)
    |--------------------------------------------------------------------------
    | Canonical home for strategy-owned settings under the multi-strategy
    | architecture. Settings::KEYS points here. The legacy `scalp.*` block
    | below mirrors the same defaults for any code path that has not yet
    | migrated to the namespaced key.
    */
    'strategy' => [
        'short_scalp' => $shortScalpDefaults,

        // Long-side variants kept after Phase 4A's promotion gate (2026-05-18).
        // Each ships only its master `enabled` toggle as a config-default;
        // individual gate parameters are hardcoded fallbacks inside each
        // variant's scanner. Phase 5 will give long_microdump (the live pick)
        // a full per-strategy settings block once it has run live for some
        // weeks; the other 2 stay benched in case microdump underperforms.
        'long_microdump' => [
            'enabled' => filter_var(env('STRATEGY_LONG_MICRODUMP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        ],
        'long_thinvol_pump' => ['enabled' => false],
        'long_lowpump' => ['enabled' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Short-Scalp Strategy Configuration (legacy alias — DEPRECATED)
    |--------------------------------------------------------------------------
    | Kept through Phase 4 so any non-migrated config('crypto.scalp.x') call
    | still resolves. Same defaults as `strategy.short_scalp.*` above.
    */
    'scalp' => $shortScalpDefaults,

];
