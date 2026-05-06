<?php

/**
 * UI metadata for the Settings tab. The functional source of truth is
 * App\Services\Settings::KEYS — this file only describes how to group the
 * keys, what help text to show under each, and what numeric constraints
 * the input should accept.
 *
 * Strategy-owned keys are namespaced as `strategy.<key>.*` after the
 * Phase 1 multi-strategy refactor; generic trading and risk keys remain
 * flat at the root.
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
            'id' => 'short_scalp_global',
            'title' => 'Short-Scalp Strategy',
            'description' => 'Master toggle for the short-scalp pump/dump reversion strategy.',
            'keys' => [
                'strategy.short_scalp.enabled',
            ],
        ],
        [
            'id' => 'short_scalp_entry',
            'title' => 'Short-Scalp Entry',
            'description' => '24h pump/dump filter, EMA crossover, and the basic entry rules.',
            'keys' => [
                'strategy.short_scalp.scan_interval',
                'strategy.short_scalp.pump_threshold_pct',
                'strategy.short_scalp.pump_max_pct',
                'strategy.short_scalp.dump_threshold_pct',
                'strategy.short_scalp.min_volume_usdt',
                'strategy.short_scalp.max_volume_usdt',
                'strategy.short_scalp.ema_fast',
                'strategy.short_scalp.ema_slow',
                'strategy.short_scalp.max_candle_body_pct',
                'strategy.short_scalp.min_red_candles',
                'strategy.short_scalp.strict_downtrend_enabled',
                'strategy.short_scalp.use_post_only_entry',
                'strategy.short_scalp.limit_order_timeout_seconds',
            ],
        ],
        [
            'id' => 'short_scalp_exit',
            'title' => 'Short-Scalp Exit',
            'description' => 'Take-profit, stop-loss, and hold-time rules for the short-scalp strategy.',
            'keys' => [
                'strategy.short_scalp.take_profit_pct',
                'strategy.short_scalp.stop_loss_pct',
                'strategy.short_scalp.max_hold_minutes',
                'strategy.short_scalp.cooldown_minutes',
                'strategy.short_scalp.failed_entry_cooldown_minutes',
            ],
        ],
        [
            'id' => 'short_scalp_htf',
            'title' => 'Short-Scalp HTF Filter',
            'description' => 'Optional 1h-EMA confirmation on top of the 15m signal.',
            'keys' => [
                'strategy.short_scalp.htf_filter_enabled',
                'strategy.short_scalp.htf_ema_period',
            ],
        ],
        [
            'id' => 'short_scalp_atr',
            'title' => 'Short-Scalp ATR Stop Loss',
            'description' => 'Volatility-aware SL distance derived from 14-period ATR on the 15m chart.',
            'keys' => [
                'strategy.short_scalp.atr_sl_enabled',
                'strategy.short_scalp.atr_sl_multiplier',
            ],
        ],
        [
            'id' => 'short_scalp_partial_tp',
            'title' => 'Short-Scalp Partial Take-Profit',
            'description' => 'Scale out a portion of the position when an early favorable target is hit.',
            'keys' => [
                'strategy.short_scalp.partial_tp_trigger_pct',
                'strategy.short_scalp.partial_tp_size_pct',
            ],
        ],
        [
            'id' => 'short_scalp_trailing_tp',
            'title' => 'Short-Scalp Trailing Take-Profit',
            'description' => 'Replace the fixed TP with a server-side trailing stop. Mutually exclusive with partial TP.',
            'keys' => [
                'strategy.short_scalp.trailing_tp_enabled',
                'strategy.short_scalp.trailing_tp_arm_pct',
                'strategy.short_scalp.trailing_tp_trail_pct',
            ],
        ],
        [
            'id' => 'long_continuation_global',
            'title' => 'Long-Continuation Strategy',
            'description' => 'Master toggle for the long-continuation strategy that rides +50–100% 24h pumps. Off by default; enable after backtest validation.',
            'keys' => [
                'strategy.long_continuation.enabled',
            ],
        ],
        [
            'id' => 'long_continuation_entry',
            'title' => 'Long-Continuation Entry',
            'description' => '24h pump band, volume window, EMA + green-candle confirmation, funding cap.',
            'keys' => [
                'strategy.long_continuation.pump_threshold_pct',
                'strategy.long_continuation.pump_max_pct',
                'strategy.long_continuation.min_volume_usdt',
                'strategy.long_continuation.max_volume_usdt',
                'strategy.long_continuation.ema_fast',
                'strategy.long_continuation.ema_slow',
                'strategy.long_continuation.min_green_candles',
                'strategy.long_continuation.max_candle_body_pct',
                'strategy.long_continuation.funding_max_rate',
                'strategy.long_continuation.strict_uptrend_enabled',
                'strategy.long_continuation.htf_filter_enabled',
                'strategy.long_continuation.htf_ema_period',
                'strategy.long_continuation.use_post_only_entry',
                'strategy.long_continuation.limit_order_timeout_seconds',
            ],
        ],
        [
            'id' => 'long_continuation_exit',
            'title' => 'Long-Continuation Exit',
            'description' => 'SL below entry, TP / trailing TP, max hold, cooldowns, sub-cap.',
            'keys' => [
                'strategy.long_continuation.stop_loss_pct',
                'strategy.long_continuation.atr_sl_enabled',
                'strategy.long_continuation.atr_sl_multiplier',
                'strategy.long_continuation.take_profit_pct',
                'strategy.long_continuation.partial_tp_trigger_pct',
                'strategy.long_continuation.partial_tp_size_pct',
                'strategy.long_continuation.trailing_tp_enabled',
                'strategy.long_continuation.trailing_tp_arm_pct',
                'strategy.long_continuation.trailing_tp_trail_pct',
                'strategy.long_continuation.max_hold_minutes',
                'strategy.long_continuation.cooldown_minutes',
                'strategy.long_continuation.failed_entry_cooldown_minutes',
                'strategy.long_continuation.max_positions',
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

        // Short-scalp strategy (namespaced)
        'strategy.short_scalp.enabled' => 'Master on/off for the short-scalp strategy. When off, the bot opens no new short positions but continues managing existing ones.',
        'strategy.short_scalp.scan_interval' => 'Seconds between scan cycles in the bot loop.',
        'strategy.short_scalp.pump_threshold_pct' => 'Minimum 24h gain to qualify a coin as a pump candidate.',
        'strategy.short_scalp.pump_max_pct' => 'Skip pumps above this threshold (continuation territory). 0 disables the cap.',
        'strategy.short_scalp.dump_threshold_pct' => '24h loss threshold to qualify a coin as a dump candidate (stored positive).',
        'strategy.short_scalp.min_volume_usdt' => 'Minimum 24h quote volume in USDT for a candidate to be considered.',
        'strategy.short_scalp.max_volume_usdt' => 'Skip pumps with volume above this. 0 disables the cap.',
        'strategy.short_scalp.ema_fast' => 'Fast EMA period on the 15m chart.',
        'strategy.short_scalp.ema_slow' => 'Slow EMA period on the 15m chart.',
        'strategy.short_scalp.take_profit_pct' => 'Take-profit distance below entry (SHORT). Used when trailing TP is off.',
        'strategy.short_scalp.stop_loss_pct' => 'Stop-loss distance above entry (SHORT). Fallback when ATR SL is off.',
        'strategy.short_scalp.max_hold_minutes' => 'Hard expiry — close any position that hits this hold time.',
        'strategy.short_scalp.cooldown_minutes' => 'Wait period after closing a symbol before re-entering it.',
        'strategy.short_scalp.failed_entry_cooldown_minutes' => 'Wait period after a Failed entry on a symbol before retrying.',
        'strategy.short_scalp.max_candle_body_pct' => 'Reject if the last 15m candle body exceeds this — frenzied volatility filter.',
        'strategy.short_scalp.min_red_candles' => 'Require this many consecutive closed red 15m candles before entry.',
        'strategy.short_scalp.strict_downtrend_enabled' => 'When false, skip the 15m EMA / red-candle / body-cap / 1h HTF gates entirely.',
        'strategy.short_scalp.use_post_only_entry' => 'Try a LIMIT maker entry first; fall back to MARKET on timeout or rejection.',
        'strategy.short_scalp.limit_order_timeout_seconds' => 'How long to poll for the post-only fill before falling back.',
        'strategy.short_scalp.htf_filter_enabled' => 'Require the 1h close to sit below its EMA. Fails open on data errors.',
        'strategy.short_scalp.htf_ema_period' => 'EMA period on the 1h chart.',
        'strategy.short_scalp.atr_sl_enabled' => 'Use ATR14 × multiplier for SL distance instead of stop_loss_pct.',
        'strategy.short_scalp.atr_sl_multiplier' => 'Multiplier applied to ATR14 to derive the SL offset.',
        'strategy.short_scalp.partial_tp_trigger_pct' => 'Favorable % at which to scale out part of the position. 0 disables.',
        'strategy.short_scalp.partial_tp_size_pct' => 'Percentage of position quantity to close at the partial trigger.',
        'strategy.short_scalp.trailing_tp_enabled' => 'Replace the fixed TP with a Binance trailing stop. Set partial_tp_trigger_pct=0 alongside.',
        'strategy.short_scalp.trailing_tp_arm_pct' => 'Favorable % at which the trail arms (sets the activation price).',
        'strategy.short_scalp.trailing_tp_trail_pct' => 'Trail distance (callback rate). Clamped to Binance 0.1–5.0% live.',

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

        'strategy.short_scalp.scan_interval' => ['min' => 5, 'max' => 600, 'step' => 5],
        'strategy.short_scalp.pump_threshold_pct' => ['min' => 0, 'step' => 0.5],
        'strategy.short_scalp.pump_max_pct' => ['min' => 0, 'step' => 1],
        'strategy.short_scalp.dump_threshold_pct' => ['min' => 0, 'step' => 0.5],
        'strategy.short_scalp.min_volume_usdt' => ['min' => 0, 'step' => 100000],
        'strategy.short_scalp.max_volume_usdt' => ['min' => 0, 'step' => 100000],
        'strategy.short_scalp.ema_fast' => ['min' => 1, 'max' => 200, 'step' => 1],
        'strategy.short_scalp.ema_slow' => ['min' => 1, 'max' => 200, 'step' => 1],
        'strategy.short_scalp.take_profit_pct' => ['min' => 0.1, 'max' => 50, 'step' => 0.1],
        'strategy.short_scalp.stop_loss_pct' => ['min' => 0.1, 'max' => 50, 'step' => 0.1],
        'strategy.short_scalp.max_hold_minutes' => ['min' => 1, 'step' => 5],
        'strategy.short_scalp.cooldown_minutes' => ['min' => 0, 'step' => 5],
        'strategy.short_scalp.failed_entry_cooldown_minutes' => ['min' => 0, 'step' => 30],
        'strategy.short_scalp.max_candle_body_pct' => ['min' => 0, 'step' => 0.1],
        'strategy.short_scalp.min_red_candles' => ['min' => 1, 'max' => 5, 'step' => 1],
        'strategy.short_scalp.limit_order_timeout_seconds' => ['min' => 1, 'max' => 60, 'step' => 1],
        'strategy.short_scalp.htf_ema_period' => ['min' => 1, 'max' => 200, 'step' => 1],
        'strategy.short_scalp.atr_sl_multiplier' => ['min' => 0.1, 'max' => 10, 'step' => 0.1],
        'strategy.short_scalp.partial_tp_trigger_pct' => ['min' => 0, 'step' => 0.1],
        'strategy.short_scalp.partial_tp_size_pct' => ['min' => 0, 'max' => 100, 'step' => 5],
        'strategy.short_scalp.trailing_tp_arm_pct' => ['min' => 0.1, 'step' => 0.1],
        'strategy.short_scalp.trailing_tp_trail_pct' => ['min' => 0.1, 'max' => 5, 'step' => 0.1],

        'circuit_breaker_drawdown_pct' => ['min' => 0.1, 'max' => 100, 'step' => 0.5],
        'circuit_breaker_window_hours' => ['min' => 0.1, 'step' => 1],
        'circuit_breaker_cooldown_hours' => ['min' => 0.1, 'step' => 1],
    ],
];
