<?php

/**
 * UI metadata for the Settings tab. The functional source of truth is
 * App\Services\Settings::KEYS — this file only describes how to group the
 * keys, what help text to show under each, and what numeric constraints
 * the input should accept.
 */
return [
    'groups' => [
        [
            'id' => 'trading',
            'title' => 'Generic Trading',
            'description' => 'Capital sizing, leverage, and global pause / dry-run toggles.',
            'keys' => [
                'dry_run',
                'trading_paused',
                'position_size_pct',
                'max_positions',
                'leverage',
                'starting_balance',
                'dry_run_fee_rate',
                'funding_tracking_enabled',
                'ws_prices_enabled',
            ],
        ],
        [
            'id' => 'scalp',
            'title' => 'Short-Scalp Strategy',
            'description' => '24h pump/dump filter, EMA crossover, and the basic exit rules.',
            'keys' => [
                'scan_interval',
                'pump_threshold_pct',
                'pump_max_pct',
                'dump_threshold_pct',
                'min_volume_usdt',
                'max_volume_usdt',
                'ema_fast',
                'ema_slow',
                'take_profit_pct',
                'stop_loss_pct',
                'max_hold_minutes',
                'cooldown_minutes',
                'failed_entry_cooldown_minutes',
                'max_candle_body_pct',
                'min_red_candles',
                'strict_downtrend_enabled',
                'use_post_only_entry',
                'limit_order_timeout_seconds',
            ],
        ],
        [
            'id' => 'htf',
            'title' => 'Higher-Timeframe Filter',
            'description' => 'Optional 1h-EMA confirmation on top of the 15m signal.',
            'keys' => [
                'htf_filter_enabled',
                'htf_ema_period',
            ],
        ],
        [
            'id' => 'atr',
            'title' => 'ATR Stop Loss',
            'description' => 'Volatility-aware SL distance derived from 14-period ATR on the 15m chart.',
            'keys' => [
                'atr_sl_enabled',
                'atr_sl_multiplier',
            ],
        ],
        [
            'id' => 'partial_tp',
            'title' => 'Partial Take-Profit',
            'description' => 'Scale out a portion of the position when an early favorable target is hit.',
            'keys' => [
                'partial_tp_trigger_pct',
                'partial_tp_size_pct',
            ],
        ],
        [
            'id' => 'trailing_tp',
            'title' => 'Trailing Take-Profit',
            'description' => 'Replace the fixed TP with a server-side trailing stop. Mutually exclusive with partial TP.',
            'keys' => [
                'trailing_tp_enabled',
                'trailing_tp_arm_pct',
                'trailing_tp_trail_pct',
            ],
        ],
        [
            'id' => 'risk',
            'title' => 'Risk Controls',
            'description' => 'Drawdown circuit breaker that gates new entries during losing streaks.',
            'keys' => [
                'circuit_breaker_enabled',
                'circuit_breaker_drawdown_pct',
                'circuit_breaker_window_hours',
                'circuit_breaker_cooldown_hours',
            ],
        ],
    ],

    'descriptions' => [
        // Generic trading
        'dry_run' => 'Paper-trade against the real exchange feed without sending live orders. Close all open positions before flipping.',
        'trading_paused' => 'Stop opening new positions. Existing positions keep being managed.',
        'position_size_pct' => 'Margin per trade as a percentage of wallet balance. With 25× leverage and 10%, each trade is 250% wallet notional.',
        'max_positions' => 'Hard cap on simultaneously open positions across all symbols.',
        'leverage' => 'Futures leverage applied to each new entry.',
        'starting_balance' => 'Synthetic wallet balance used in dry-run. Realized P&L accumulates on top.',
        'dry_run_fee_rate' => 'Simulated taker rate in dry-run (0.0005 = 0.05%). Maker is half.',
        'funding_tracking_enabled' => 'Settle funding fees against open positions at 8-hour boundaries.',
        'ws_prices_enabled' => 'Documentation toggle — the actual ws-prices worker is process-level.',

        // Scalp strategy
        'scan_interval' => 'Seconds between scan cycles in the bot loop.',
        'pump_threshold_pct' => 'Minimum 24h gain to qualify a coin as a pump candidate.',
        'pump_max_pct' => 'Skip pumps above this threshold (continuation territory). 0 disables the cap.',
        'dump_threshold_pct' => '24h loss threshold to qualify a coin as a dump candidate (stored positive).',
        'min_volume_usdt' => 'Minimum 24h quote volume in USDT for a candidate to be considered.',
        'max_volume_usdt' => 'Skip pumps with volume above this. 0 disables the cap.',
        'ema_fast' => 'Fast EMA period on the 15m chart.',
        'ema_slow' => 'Slow EMA period on the 15m chart.',
        'take_profit_pct' => 'Take-profit distance below entry (SHORT). Used when trailing TP is off.',
        'stop_loss_pct' => 'Stop-loss distance above entry (SHORT). Fallback when ATR SL is off.',
        'max_hold_minutes' => 'Hard expiry — close any position that hits this hold time.',
        'cooldown_minutes' => 'Wait period after closing a symbol before re-entering it.',
        'failed_entry_cooldown_minutes' => 'Wait period after a Failed entry on a symbol before retrying.',
        'max_candle_body_pct' => 'Reject if the last 15m candle body exceeds this — frenzied volatility filter.',
        'min_red_candles' => 'Require this many consecutive closed red 15m candles before entry.',
        'strict_downtrend_enabled' => 'When false, skip the 15m EMA / red-candle / body-cap / 1h HTF gates entirely.',
        'use_post_only_entry' => 'Try a LIMIT maker entry first; fall back to MARKET on timeout or rejection.',
        'limit_order_timeout_seconds' => 'How long to poll for the post-only fill before falling back.',

        // HTF
        'htf_filter_enabled' => 'Require the 1h close to sit below its EMA. Fails open on data errors.',
        'htf_ema_period' => 'EMA period on the 1h chart.',

        // ATR
        'atr_sl_enabled' => 'Use ATR14 × multiplier for SL distance instead of stop_loss_pct.',
        'atr_sl_multiplier' => 'Multiplier applied to ATR14 to derive the SL offset.',

        // Partial TP
        'partial_tp_trigger_pct' => 'Favorable % at which to scale out part of the position. 0 disables.',
        'partial_tp_size_pct' => 'Percentage of position quantity to close at the partial trigger.',

        // Trailing TP
        'trailing_tp_enabled' => 'Replace the fixed TP with a Binance trailing stop. Set partial_tp_trigger_pct=0 alongside.',
        'trailing_tp_arm_pct' => 'Favorable % at which the trail arms (sets the activation price).',
        'trailing_tp_trail_pct' => 'Trail distance (callback rate). Clamped to Binance 0.1–5.0% live.',

        // Risk
        'circuit_breaker_enabled' => 'Block new entries when realized + unrealized drawdown breaches the threshold.',
        'circuit_breaker_drawdown_pct' => 'Peak-to-trough drawdown % that trips the breaker.',
        'circuit_breaker_window_hours' => 'Legacy v1 setting — present for backwards compat, not read by current detector.',
        'circuit_breaker_cooldown_hours' => 'Hours to block new entries after a trip.',
    ],

    'constraints' => [
        'position_size_pct' => ['min' => 0.1, 'max' => 100, 'step' => 0.5],
        'max_positions' => ['min' => 1, 'max' => 50, 'step' => 1],
        'leverage' => ['min' => 1, 'max' => 125, 'step' => 1],
        'starting_balance' => ['min' => 1, 'step' => 100],
        'dry_run_fee_rate' => ['min' => 0, 'max' => 0.01, 'step' => 0.0001],
        'scan_interval' => ['min' => 5, 'max' => 600, 'step' => 5],
        'pump_threshold_pct' => ['min' => 0, 'step' => 0.5],
        'pump_max_pct' => ['min' => 0, 'step' => 1],
        'dump_threshold_pct' => ['min' => 0, 'step' => 0.5],
        'min_volume_usdt' => ['min' => 0, 'step' => 100000],
        'max_volume_usdt' => ['min' => 0, 'step' => 100000],
        'ema_fast' => ['min' => 1, 'max' => 200, 'step' => 1],
        'ema_slow' => ['min' => 1, 'max' => 200, 'step' => 1],
        'take_profit_pct' => ['min' => 0.1, 'max' => 50, 'step' => 0.1],
        'stop_loss_pct' => ['min' => 0.1, 'max' => 50, 'step' => 0.1],
        'max_hold_minutes' => ['min' => 1, 'step' => 5],
        'cooldown_minutes' => ['min' => 0, 'step' => 5],
        'failed_entry_cooldown_minutes' => ['min' => 0, 'step' => 30],
        'max_candle_body_pct' => ['min' => 0, 'step' => 0.1],
        'min_red_candles' => ['min' => 1, 'max' => 5, 'step' => 1],
        'limit_order_timeout_seconds' => ['min' => 1, 'max' => 60, 'step' => 1],
        'htf_ema_period' => ['min' => 1, 'max' => 200, 'step' => 1],
        'atr_sl_multiplier' => ['min' => 0.1, 'max' => 10, 'step' => 0.1],
        'partial_tp_trigger_pct' => ['min' => 0, 'step' => 0.1],
        'partial_tp_size_pct' => ['min' => 0, 'max' => 100, 'step' => 5],
        'trailing_tp_arm_pct' => ['min' => 0.1, 'step' => 0.1],
        'trailing_tp_trail_pct' => ['min' => 0.1, 'max' => 5, 'step' => 0.1],
        'circuit_breaker_drawdown_pct' => ['min' => 0.1, 'max' => 100, 'step' => 0.5],
        'circuit_breaker_window_hours' => ['min' => 0.1, 'step' => 1],
        'circuit_breaker_cooldown_hours' => ['min' => 0.1, 'step' => 1],
    ],
];
