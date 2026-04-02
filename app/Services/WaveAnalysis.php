<?php

namespace App\Services;

/**
 * Ephemeral value object representing the current wave state for a symbol.
 * Never stored in DB — exists only during the current scan loop iteration.
 */
class WaveAnalysis
{
    public function __construct(
        public readonly string $direction,    // 'LONG' or 'SHORT'
        public readonly bool $freshCross,     // EMA just crossed this candle
        public readonly float $rsi,           // Current RSI value
        public readonly float $atr,           // Current ATR value
        public readonly float $currentPrice,  // Current close price
        public readonly string $waveState,    // 'new_wave', 'riding', 'weakening'
        public readonly float $emaGap,        // abs(emaFast - emaSlow) as % of price
    ) {}
}
