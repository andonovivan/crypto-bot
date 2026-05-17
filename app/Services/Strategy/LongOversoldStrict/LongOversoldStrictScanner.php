<?php

namespace App\Services\Strategy\LongOversoldStrict;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_oversold_strict: any 24h band. Pure RSI signal — RSI(14)<20 (very
 * oversold) + first green candle + volume > avg×1.5. Looks for the truly
 * exhausted-seller setup regardless of 24h % move.
 */
class LongOversoldStrictScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_oversold_strict';
    }

    protected function bandPass(float $changePct): bool
    {
        // Almost any move qualifies — the gate is RSI in analyze().
        $cap = (float) ($this->setting('pump_max_pct') ?: 25.0);
        $dump = (float) ($this->setting('dump_max_pct') ?: 30.0);
        return $changePct <= $cap && $changePct >= -$dump;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $rsiThr = (float) ($this->setting('rsi_oversold_threshold') ?: 20.0);
        $volMult = (float) ($this->setting('volume_spike_mult') ?: 1.5);
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
        // Volume spike on the green candle vs prior 20 bars' avg (NOT
        // including the closed candle itself — that's the candle we're
        // comparing TO).
        $endIdx = count($klines) - 2;
        $vols = [];
        for ($i = max(0, $endIdx - 20); $i < $endIdx; $i++) {
            $vols[] = (float) ($klines[$i]['volume'] ?? 0);
        }
        $avgVol = $vols ? array_sum($vols) / count($vols) : 0;
        $candleVol = (float) ($closedCandle['volume'] ?? 0);
        if ($avgVol > 0 && $candleVol < $avgVol * $volMult) {
            return [false, sprintf('Volume %.0f < %.1f×avg', $candleVol, $volMult), ['rsi' => $rsiNow]];
        }

        return [true, null, ['rsi' => $rsiNow, 'volumeRatio' => $avgVol > 0 ? $candleVol / $avgVol : 0]];
    }
}
