<?php

/**
 * Plug-in strategy registry. Each strategy class implements
 * App\Services\Strategy\StrategyInterface and is bound by the
 * StrategyServiceProvider. The `order` array determines tie-break
 * priority when two strategies fire on the same symbol in one cycle
 * (first listed wins).
 *
 * The runtime enabled flag lives in Settings under
 * `strategy.<key>.enabled`; this file's `enabled` defaults are only
 * consulted when neither override nor DB row nor legacy config-default
 * is present.
 */

$longVariants = [
    'long_microdump' => \App\Services\Strategy\LongMicrodump\LongMicrodumpStrategy::class,
    'long_thinvol_pump' => \App\Services\Strategy\LongThinvolPump\LongThinvolPumpStrategy::class,
    'long_lowpump' => \App\Services\Strategy\LongLowpump\LongLowpumpStrategy::class,
];

return [
    'classes' => array_merge(
        ['short_scalp' => \App\Services\Strategy\ShortScalp\ShortScalpStrategy::class],
        $longVariants,
    ),

    // short_scalp wins on band overlap. The 3 long-side variants kept after
    // Phase 4A's promotion gate: long_microdump (default ON in production —
    // highest WR + most liquid + cleanest mean-reversion signal),
    // long_thinvol_pump and long_lowpump (default OFF, kept on the bench
    // for re-evaluation if microdump underperforms live).
    'order' => array_merge(['short_scalp'], array_keys($longVariants)),

    // Defaults consulted by config('strategy.<key>.enabled') when no DB row exists.
    'enabled' => [
        'short_scalp' => filter_var(env('STRATEGY_SHORT_SCALP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'long_microdump' => filter_var(env('STRATEGY_LONG_MICRODUMP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'long_thinvol_pump' => false,
        'long_lowpump' => false,
    ],
];
