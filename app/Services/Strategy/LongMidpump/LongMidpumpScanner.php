<?php

namespace App\Services\Strategy\LongMidpump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_midpump: +25% to +50% 24h band — overlaps with short_scalp on this
 * band. Tests how strategies behave when they fight for the same symbols
 * (short wins via order priority; long picks up the leftovers). EMA + 2
 * greens.
 */
class LongMidpumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_midpump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 25.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 50.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        $prev = $last - 1;
        $priorCandle = $klines[$prev - 1] ?? null;
        if (! $priorCandle) {
            return [false, 'Not enough klines', []];
        }

        if ($body = $this->bodyCapBlocked($closedCandle)) {
            return [false, $body, []];
        }
        if (! ((float) $emaFastValues[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'EMA not up', []];
        }
        if (! $this->isGreen($closedCandle) || ! $this->isGreen($priorCandle)) {
            return [false, 'Need 2 consecutive greens', []];
        }
        return [true, null, []];
    }
}
