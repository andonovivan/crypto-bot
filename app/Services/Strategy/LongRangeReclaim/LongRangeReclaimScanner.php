<?php

namespace App\Services\Strategy\LongRangeReclaim;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_range_reclaim: -3% to +3% 24h band (sideways). Price near the 30-bar
 * low + bullish reversal candle (close > open AND close > prior candle's
 * open). Picks up the low-of-range bounces in chop markets.
 */
class LongRangeReclaimScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_range_reclaim';
    }

    protected function bandPass(float $changePct): bool
    {
        $absMax = (float) ($this->setting('band_abs_max_pct') ?: 3.0);
        return abs($changePct) <= $absMax;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $proximityPct = (float) ($this->setting('low_proximity_pct') ?: 1.5);
        $endIdx = count($klines) - 2; // last closed
        if ($endIdx < 20) {
            return [false, 'Not enough klines for low-proximity check', []];
        }
        $lows = [];
        for ($i = max(0, $endIdx - 30); $i < $endIdx; $i++) {
            $lows[] = (float) $klines[$i]['low'];
        }
        $rangeLow = $lows ? min($lows) : 0;
        if ($rangeLow <= 0) {
            return [false, 'Invalid range low', []];
        }
        $closedLow = (float) $closedCandle['low'];
        $proximity = ($closedLow - $rangeLow) / $rangeLow * 100;
        if ($proximity > $proximityPct) {
            return [false, sprintf('Not near 30-bar low (%.2f%% > %.2f%%)', $proximity, $proximityPct), []];
        }
        // Bullish reversal: green AND close > prior open
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }
        $priorCandle = $klines[$endIdx - 1] ?? null;
        if (! $priorCandle || (float) $closedCandle['close'] <= (float) $priorCandle['open']) {
            return [false, 'Close not above prior open', []];
        }

        return [true, null, ['rangeLow' => $rangeLow, 'proximityPct' => $proximity]];
    }
}
