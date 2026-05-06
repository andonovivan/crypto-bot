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

return [
    'classes' => [
        'short_scalp' => \App\Services\Strategy\ShortScalp\ShortScalpStrategy::class,
        'long_continuation' => \App\Services\Strategy\LongContinuation\LongContinuationStrategy::class,
    ],

    'order' => [
        'short_scalp',
        'long_continuation',
    ],

    // Defaults consulted by config('strategy.<key>.enabled') when no DB row exists.
    'enabled' => [
        'short_scalp' => filter_var(env('STRATEGY_SHORT_SCALP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'long_continuation' => filter_var(env('STRATEGY_LONG_CONTINUATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],
];
