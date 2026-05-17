<?php

namespace App\Services\Strategy\LongBtcAligned;

use App\Services\Strategy\LongBase\LongScannerBase;
use Illuminate\Support\Facades\Log;

/**
 * long_btc_aligned: 0% to +25% 24h band, gated by BTC regime — only enters
 * when BTC 1h close > BTC 4h EMA(21). Premise: alts in modest pumps + a
 * supportive BTC regime is the cleanest go-long setup.
 *
 * Cross-symbol gate fires once per cycle via preCandidateGate() and caches
 * for 60s so 500+ symbol candidates don't re-fetch BTC each one.
 */
class LongBtcAlignedScanner extends LongScannerBase
{
    private const BTC_CACHE_TTL_SECONDS = 60;
    private ?array $btcRegimeCache = null;

    public function strategyKey(): string
    {
        return 'long_btc_aligned';
    }

    protected function bandPass(float $changePct): bool
    {
        $min = (float) ($this->setting('pump_min_pct') ?: 0.0);
        $max = (float) ($this->setting('pump_max_pct') ?: 25.0);
        return $changePct >= $min && $changePct <= $max;
    }

    protected function preCandidateGate(): bool
    {
        return $this->btcRegimeUp();
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
        return [true, null, ['btcRegimeUp' => true]];
    }

    /** BTC 1h close > BTC 4h EMA(21). Cached 60s; fails-open on data errors. */
    protected function btcRegimeUp(): bool
    {
        $now = \Illuminate\Support\Carbon::now()->getTimestamp();
        if ($this->btcRegimeCache !== null && ($now - $this->btcRegimeCache['at']) < self::BTC_CACHE_TTL_SECONDS) {
            return $this->btcRegimeCache['up'];
        }

        $period = (int) ($this->setting('btc_regime_ema_period') ?: 21);
        try {
            $h1 = $this->exchange->getKlines('BTCUSDT', '1h', 1);
            $h4 = $this->exchange->getKlines('BTCUSDT', '4h', $period + 5);
        } catch (\Throwable $e) {
            Log::debug('BTC regime fetch failed — fails open', ['error' => $e->getMessage()]);
            $this->btcRegimeCache = ['at' => $now, 'up' => true];
            return true;
        }
        if (count($h4) < $period + 1 || empty($h1)) {
            $this->btcRegimeCache = ['at' => $now, 'up' => true];
            return true;
        }
        $btcClose = (float) end($h1)['close'];
        $h4Closes = array_column($h4, 'close');
        $emaSeries = $this->ta->calculateEMA($h4Closes, $period);
        $btcEma = (float) end($emaSeries);
        $up = $btcClose > $btcEma;
        $this->btcRegimeCache = ['at' => $now, 'up' => $up];
        return $up;
    }
}
