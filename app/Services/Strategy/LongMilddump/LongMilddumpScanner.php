<?php

namespace App\Services\Strategy\LongMilddump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_milddump: -5% to -10% 24h band. 2 reds then 1 green + EMA turning up.
 * Slightly more conservative than microdump — looks for clearer reversal.
 */
class LongMilddumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_milddump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('dump_min_pct') ?: 5.0);
        $max = (float) ($this->setting('dump_max_pct') ?: 10.0);
        return $changePct <= -$min && $changePct >= -$max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        $prev = $last - 1;
        $priorCandle = $klines[$prev - 1] ?? null;
        $priorPriorCandle = $klines[$prev - 2] ?? null;
        if (! $priorCandle || ! $priorPriorCandle) {
            return [false, 'Not enough klines for prior-candle check', []];
        }

        if ($body = $this->bodyCapBlocked($closedCandle)) {
            return [false, $body, []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }
        if (! $this->isRed($priorCandle) || ! $this->isRed($priorPriorCandle)) {
            return [false, 'Need 2 reds before the green', []];
        }
        // EMA fast > slow now (turning up after the dump)
        if (! ((float) $emaFastValues[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'EMA not turning up', []];
        }

        return [true, null, []];
    }
}
