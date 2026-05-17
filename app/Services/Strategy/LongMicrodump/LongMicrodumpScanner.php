<?php

namespace App\Services\Strategy\LongMicrodump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_microdump: -2% to -5% 24h band, first-green-after-EMA-cross-up.
 * Targets the highest-frequency mean-reversion setup. Tight TP/SL (1%/1%),
 * 30min hold — scalp-style.
 */
class LongMicrodumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_microdump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('dump_min_pct') ?: 2.0);  // shallow side
        $max = (float) ($this->setting('dump_max_pct') ?: 5.0);  // deep side
        return $changePct <= -$min && $changePct >= -$max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        $prev = $last - 1;

        // EMA crossed up: fast > slow now, fast <= slow on prior bar.
        $emaFastNow = (float) $emaFastValues[$last];
        $emaSlowNow = (float) $emaSlowValues[$last];
        $emaFastPrev = (float) $emaFastValues[$prev];
        $emaSlowPrev = (float) $emaSlowValues[$prev];

        if ($body = $this->bodyCapBlocked($closedCandle)) {
            return [false, $body, []];
        }
        if (! ($emaFastNow > $emaSlowNow)) {
            return [false, 'EMA not up (current)', []];
        }
        if (! ($emaFastPrev <= $emaSlowPrev)) {
            return [false, 'EMA cross too old (was already up)', []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }

        return [true, null, ['emaCrossJustFlipped' => true]];
    }
}
