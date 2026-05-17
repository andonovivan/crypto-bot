<?php

namespace App\Services\Strategy\LongBigdump;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_bigdump: -10% to -25% 24h band. RSI(14)<30 + first green candle.
 * No HTF gate (data may be too distorted on big dumps to trust 1h EMA).
 * Aggressive oversold-bounce setup. Wider stops (2.5/2.0%), longer hold.
 */
class LongBigdumpScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_bigdump';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('dump_min_pct') ?: 10.0);
        $max = (float) ($this->setting('dump_max_pct') ?: 25.0);
        return $changePct <= -$min && $changePct >= -$max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $rsiThr = (float) ($this->setting('rsi_oversold_threshold') ?: 30.0);
        $rsiValues = $this->ta->calculateRSI($closes, 14);
        $last = count($closes) - 1;
        $rsiNow = (float) ($rsiValues[$last] ?? 50);

        if ($body = $this->bodyCapBlocked($closedCandle)) {
            return [false, $body, []];
        }
        if ($rsiNow >= $rsiThr) {
            return [false, sprintf('RSI %.1f >= %.1f', $rsiNow, $rsiThr), ['rsi' => $rsiNow]];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Last candle not green', ['rsi' => $rsiNow]];
        }

        return [true, null, ['rsi' => $rsiNow]];
    }
}
