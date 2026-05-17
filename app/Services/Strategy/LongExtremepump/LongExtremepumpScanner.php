<?php

namespace App\Services\Strategy\LongExtremepump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_extremepump: +100%+ 24h band. Megapump territory — most signals
 * break down here, so just demand immediate momentum (EMA up + green
 * candle). Fast in-out: 60min hold, wide stops.
 */
class LongExtremepumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_extremepump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 100.0);
        // No upper cap.
        return $changePct >= $min;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        // No body cap — megapump candles are huge.
        if (! ((float) $emaFastValues[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'EMA not up', []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }
        return [true, null, []];
    }
}
