<?php

namespace App\Services\Strategy\LongBreakoutNewHigh;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_breakout_new_high: 0% to +25% 24h band. Close > max(last 20 closes)
 * AND volume > avg×1.5 — classic new-high breakout with confirmation.
 */
class LongBreakoutNewHighScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_breakout_new_high';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 0.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 25.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $lookback = (int) ($this->setting('breakout_lookback') ?: 20);
        $volMult = (float) ($this->setting('volume_spike_mult') ?: 1.5);
        $endIdx = count($klines) - 2; // last closed
        if ($endIdx < $lookback) {
            return [false, 'Not enough klines for breakout lookback', []];
        }
        $priorCloses = [];
        $priorVolumes = [];
        for ($i = $endIdx - $lookback; $i < $endIdx; $i++) {
            $priorCloses[] = (float) $klines[$i]['close'];
            $priorVolumes[] = (float) ($klines[$i]['volume'] ?? 0);
        }
        $rangeHigh = max($priorCloses);
        $closedClose = (float) $closedCandle['close'];
        if ($closedClose <= $rangeHigh) {
            return [false, sprintf('No new high (%.5f <= %.5f)', $closedClose, $rangeHigh), []];
        }
        $avgVol = $priorVolumes ? array_sum($priorVolumes) / count($priorVolumes) : 0;
        $candleVol = (float) ($closedCandle['volume'] ?? 0);
        if ($avgVol > 0 && $candleVol < $avgVol * $volMult) {
            return [false, sprintf('Volume %.0f < %.1f×avg', $candleVol, $volMult), []];
        }
        if (! $this->isGreen($closedCandle)) {
            return [false, 'Breakout candle not green', []];
        }

        return [true, null, ['rangeHigh' => $rangeHigh, 'volumeRatio' => $avgVol > 0 ? $candleVol / $avgVol : 0]];
    }
}
