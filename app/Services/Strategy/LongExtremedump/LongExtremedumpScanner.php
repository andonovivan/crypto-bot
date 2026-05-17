<?php

namespace App\Services\Strategy\LongExtremedump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_extremedump: -25% to -50% 24h band. ANY green candle qualifies — no
 * EMA, no RSI, no HTF (extreme dumps overwhelm those indicators). Loose
 * stops (4/3%), long hold (240min) to ride the bounce.
 */
class LongExtremedumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_extremedump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('dump_min_pct') ?: 25.0);
        $max = (float) ($this->setting('dump_max_pct') ?: 50.0);
        return $changePct <= -$min && $changePct >= -$max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        // No body cap — extreme dumps have big candles by nature.
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }
        return [true, null, []];
    }
}
