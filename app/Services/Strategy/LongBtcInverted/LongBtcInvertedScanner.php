<?php

namespace App\Services\Strategy\LongBtcInverted;

use App\Services\Strategy\LongBase\LongScannerBase;
use Illuminate\Support\Facades\Log;

/**
 * long_btc_inverted: +10% to +30% 24h band, gated by BTC regime DOWN —
 * only enters when BTC 1h close < BTC 4h EMA(21). Premise: alts pumping
 * while BTC is weak is a strong relative-momentum signal (decorrelation).
 */
class LongBtcInvertedScanner extends LongScannerBase
{
    private const BTC_CACHE_TTL_SECONDS = 60;
    private ?array $btcRegimeCache = null;

    public function strategyKey(): string
    {
        return 'long_btc_inverted';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 10.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 30.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function preCandidateGate(): bool
    {
        return $this->btcRegimeDown();
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
        return [true, null, ['btcRegimeDown' => true]];
    }

    /** BTC 1h close < BTC 4h EMA(21). Cached 60s; fails-CLOSED on data errors. */
    protected function btcRegimeDown(): bool
    {
        $now = \Illuminate\Support\Carbon::now()->getTimestamp();
        if ($this->btcRegimeCache !== null && ($now - $this->btcRegimeCache['at']) < self::BTC_CACHE_TTL_SECONDS) {
            return $this->btcRegimeCache['down'];
        }

        $period = (int) ($this->setting('btc_regime_ema_period') ?: 21);
        try {
            $h1 = $this->exchange->getKlines('BTCUSDT', '1h', 1);
            $h4 = $this->exchange->getKlines('BTCUSDT', '4h', $period + 5);
        } catch (\Throwable $e) {
            // Fails CLOSED: if we don't know the regime, don't trade. Inverse
            // policy from btc_aligned (which fails OPEN).
            Log::debug('BTC regime fetch failed — fails closed for inverted', ['error' => $e->getMessage()]);
            $this->btcRegimeCache = ['at' => $now, 'down' => false];
            return false;
        }
        if (count($h4) < $period + 1 || empty($h1)) {
            $this->btcRegimeCache = ['at' => $now, 'down' => false];
            return false;
        }
        $btcClose = (float) end($h1)['close'];
        $h4Closes = array_column($h4, 'close');
        $emaSeries = $this->ta->calculateEMA($h4Closes, $period);
        $btcEma = (float) end($emaSeries);
        $down = $btcClose < $btcEma;
        $this->btcRegimeCache = ['at' => $now, 'down' => $down];
        return $down;
    }
}
