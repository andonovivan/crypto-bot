<?php

namespace App\Services\Strategy\LongThickvolPump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_thickvol_pump: +5% to +20% 24h, volume 100M-1B (heavyweights).
 * Tight breakout scalp on coins with deep liquidity; smaller bands, much
 * tighter stops (1.0/0.7%), 30min hold.
 */
class LongThickvolPumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_thickvol_pump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 5.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 20.0);
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
        // Tighter breakout: close > max of last 10 closes.
        $endIdx = count($klines) - 2;
        $look = 10;
        if ($endIdx < $look) {
            return [false, 'Not enough klines', []];
        }
        $priorCloses = [];
        for ($i = $endIdx - $look; $i < $endIdx; $i++) {
            $priorCloses[] = (float) $klines[$i]['close'];
        }
        $rangeHigh = max($priorCloses);
        if ((float) $closedCandle['close'] <= $rangeHigh) {
            return [false, 'No 10-bar breakout', []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', []];
        }
        return [true, null, ['rangeHigh' => $rangeHigh]];
    }
}
