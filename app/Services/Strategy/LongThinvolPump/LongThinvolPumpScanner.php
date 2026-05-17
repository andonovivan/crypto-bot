<?php

namespace App\Services\Strategy\LongThinvolPump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_thinvol_pump: +10% to +30% 24h, volume 1M-5M (very thin coins).
 * Meme-coin territory; small floats run hard. EMA up + green candle.
 */
class LongThinvolPumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_thinvol_pump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 10.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 30.0);
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
