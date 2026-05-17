<?php

namespace App\Services\Strategy\LongDeeppull;

use App\Services\Strategy\LongBase\LongScannerBase;

/**
 * long_deeppull: +5% to +25% 24h band. EMA fast > slow + 2-3 reds totaling
 * 2-5% retracement. Buy the deeper-pullback in an established uptrend.
 */
class LongDeeppullScanner extends LongScannerBase
{
    public function strategyKey(): string
    {
        return 'long_deeppull';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 5.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 25.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function evaluateSignal(
        string $symbol, array $klines, array $closes, array $closedCandle,
        array $emaFastValues, array $emaSlowValues, array $atrValues,
        ?float $fundingRate, bool $htfUpOk,
    ): array {
        $last = count($closes) - 1;
        $minRet = (float) ($this->setting('pullback_min_pct') ?: 2.0);
        $maxRet = (float) ($this->setting('pullback_max_pct') ?: 5.0);

        if (! ((float) $emaFastValues[$last] > (float) $emaSlowValues[$last])) {
            return [false, 'Not in uptrend', []];
        }
        // 2-3 reds ending at or before the last closed candle
        $reds = 0;
        $startIdx = count($klines) - 2;
        $highestClose = 0.0;
        $lowestClose = PHP_FLOAT_MAX;
        for ($i = $startIdx; $i >= max(0, $startIdx - 3); $i--) {
            $c = $klines[$i];
            if (! $this->isRed($c)) break;
            $reds++;
            $highestClose = max($highestClose, (float) $c['open']);
            $lowestClose = min($lowestClose, (float) $c['close']);
        }
        if ($reds < 2 || $reds > 3) {
            return [false, sprintf('Reds %d not in [2,3]', $reds), []];
        }
        $retPct = $highestClose > 0 ? ($highestClose - $lowestClose) / $highestClose * 100 : 0;
        if ($retPct < $minRet || $retPct > $maxRet) {
            return [false, sprintf('Retracement %.2f%% not in [%.1f, %.1f]', $retPct, $minRet, $maxRet), []];
        }

        return [true, null, ['retracementPct' => $retPct, 'redCount' => $reds]];
    }
}
