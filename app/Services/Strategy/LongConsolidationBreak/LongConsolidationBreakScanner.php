<?php

namespace App\Services\Strategy\LongConsolidationBreak;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_consolidation_break: +5% to +25% band. The 5 candles before the most
 * recent each had body ≤ 1%, then the latest closed candle is a breakout
 * (close above the consolidation range high). Classic accumulation→breakout
 * pattern.
 */
class LongConsolidationBreakScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_consolidation_break';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 5.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 25.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $consolidationBars = (int) ($this->setting('consolidation_bars') ?: 5);
        $consolidationBodyMax = (float) ($this->setting('consolidation_body_max_pct') ?: 1.0);
        $totalNeeded = $consolidationBars + 2; // +closed +current
        if (count($klines) < $totalNeeded) {
            return [false, 'Not enough klines', []];
        }
        $endIdx = count($klines) - 2; // last closed
        $rangeHigh = 0.0;
        $rangeLow = PHP_FLOAT_MAX;
        for ($i = $endIdx - $consolidationBars; $i < $endIdx; $i++) {
            $c = $klines[$i];
            $open = (float) $c['open'];
            $close = (float) $c['close'];
            $body = $open > 0 ? abs($close - $open) / $open * 100 : 999;
            if ($body > $consolidationBodyMax) {
                return [false, sprintf('Consolidation bar %d body %.2f%% > %.2f%%', $i, $body, $consolidationBodyMax), []];
            }
            $rangeHigh = max($rangeHigh, (float) $c['high']);
            $rangeLow = min($rangeLow, (float) $c['low']);
        }
        // Breakout: closed candle's close > range high.
        if ((float) $closedCandle['close'] <= $rangeHigh) {
            return [false, sprintf('No breakout (%.5f <= %.5f)', (float) $closedCandle['close'], $rangeHigh), []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Breakout candle not green', []];
        }

        return [true, null, ['rangeHigh' => $rangeHigh, 'rangeLow' => $rangeLow]];
    }
}
