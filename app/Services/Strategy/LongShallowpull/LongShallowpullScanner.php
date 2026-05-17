<?php

namespace App\Services\Strategy\LongShallowpull;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_shallowpull: +5% to +15% 24h band. EMA fast > slow (confirmed
 * uptrend) + the last closed candle is a shallow red ≤ 0.5% body —
 * i.e., a brief pullback within an uptrend. Buy the dip.
 */
class LongShallowpullScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_shallowpull';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 5.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 15.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        $shallowBodyMax = (float) ($this->setting('shallow_body_max_pct') ?: 0.5);
        $open = (float) $closedCandle['open'];
        $close = (float) $closedCandle['close'];
        $bodyPct = $open > 0 ? abs($close - $open) / $open * 100 : 0;

        // Need confirmed uptrend
        if (! ((float) $emaFastValues[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'Not in uptrend (EMA fast <= slow)', []];
        }
        // Need the closed candle to be a shallow red
        if (! $this->isRed($closedCandle)) {
            return [false, 'Last candle not red (no pullback signal)', []];
        }
        if ($bodyPct > $shallowBodyMax) {
            return [false, sprintf('Pullback body %.2f%% > %.2f%%', $bodyPct, $shallowBodyMax), []];
        }
        // Price still above slow EMA (uptrend not broken)
        if (! ((float) $closes[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'Price below slow EMA — uptrend may have broken', []];
        }

        return [true, null, ['pullbackBodyPct' => $bodyPct]];
    }
}
