<?php

namespace App\Services\Strategy\LongLowpump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_lowpump: +10% to +25% 24h band, vol 5M-25M (thin-mid coins). Looks
 * for high-frequency, small-pump continuation. EMA fast > slow + green
 * candle, body cap 4%.
 */
class LongLowpumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_lowpump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 10.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 25.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        if ($body = $this->bodyCapBlocked($closedCandle)) {
            return [false, $body, []];
        }
        if (! ((float) $emaFastValues[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'EMA not up', []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }
        return [true, null, []];
    }
}
