<?php

namespace App\Services\Strategy\LongHighpump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_highpump: +50% to +100% 24h band — this is the deleted
 * long_continuation's old territory. Included as a control variant so the
 * sweep produces an apples-to-apples comparison against the deprecated
 * strategy. EMA + 2 greens + 1h HTF up.
 */
class LongHighpumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_highpump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 50.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 100.0);
        return $changePct > $min && $changePct <= $max;
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
        // HTF check already enforced by base when htf_filter_enabled=true.
        return [true, null, []];
    }
}
