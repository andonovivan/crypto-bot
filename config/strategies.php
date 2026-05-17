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
    'long_milddump' => \App\Services\Strategy\LongMilddump\LongMilddumpStrategy::class,
    'long_bigdump' => \App\Services\Strategy\LongBigdump\LongBigdumpStrategy::class,
    'long_extremedump' => \App\Services\Strategy\LongExtremedump\LongExtremedumpStrategy::class,
    'long_oversold_strict' => \App\Services\Strategy\LongOversoldStrict\LongOversoldStrictStrategy::class,
    'long_shallowpull' => \App\Services\Strategy\LongShallowpull\LongShallowpullStrategy::class,
    'long_deeppull' => \App\Services\Strategy\LongDeeppull\LongDeeppullStrategy::class,
    'long_consolidation_break' => \App\Services\Strategy\LongConsolidationBreak\LongConsolidationBreakStrategy::class,
    'long_breakout_new_high' => \App\Services\Strategy\LongBreakoutNewHigh\LongBreakoutNewHighStrategy::class,
    'long_range_reclaim' => \App\Services\Strategy\LongRangeReclaim\LongRangeReclaimStrategy::class,
    'long_lowpump' => \App\Services\Strategy\LongLowpump\LongLowpumpStrategy::class,
    'long_midpump' => \App\Services\Strategy\LongMidpump\LongMidpumpStrategy::class,
    'long_highpump' => \App\Services\Strategy\LongHighpump\LongHighpumpStrategy::class,
    'long_extremepump' => \App\Services\Strategy\LongExtremepump\LongExtremepumpStrategy::class,
    'long_thinvol_pump' => \App\Services\Strategy\LongThinvolPump\LongThinvolPumpStrategy::class,
    'long_thickvol_pump' => \App\Services\Strategy\LongThickvolPump\LongThickvolPumpStrategy::class,
    'long_btc_aligned' => \App\Services\Strategy\LongBtcAligned\LongBtcAlignedStrategy::class,
    'long_btc_inverted' => \App\Services\Strategy\LongBtcInverted\LongBtcInvertedStrategy::class,
];

return [
    'classes' => array_merge(
        ['short_scalp' => \App\Services\Strategy\ShortScalp\ShortScalpStrategy::class],
        $longVariants,
    ),

    // short_scalp wins on band overlap (e.g., long_midpump shares short's
    // 25-50% band). Long variants are listed but default-disabled — the
    // Phase-4 sweep enables them one at a time via --override.
    'order' => array_merge(['short_scalp'], array_keys($longVariants)),

    // Defaults consulted by config('strategy.<key>.enabled') when no DB row exists.
    'enabled' => array_merge(
        ['short_scalp' => filter_var(env('STRATEGY_SHORT_SCALP_ENABLED', true), FILTER_VALIDATE_BOOLEAN)],
        array_fill_keys(array_keys($longVariants), false),
    ),
];
