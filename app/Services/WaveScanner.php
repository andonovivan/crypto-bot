<?php

namespace App\Services;

use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Facades\Log;

/**
 * Wave Rider scanner — detects short-term price waves using fast EMAs on configurable candle interval (default 15m).
 * Scans every 30 seconds. Signals are ephemeral (not stored in DB).
 */
class WaveScanner
{
    /**
     * In-memory kline cache to avoid duplicate API calls within the same loop iteration.
     * Structure: ['BTCUSDT' => ['data' => [...], 'at' => microtime(true)]]
     */
    private array $klineCache = [];

    private const CACHE_TTL_SECONDS = 15;

    public function __construct(
        private ExchangeInterface $exchange,
        private TechnicalAnalysis $ta,
    ) {}

    /**
     * Analyze a symbol for wave state. Returns null if data insufficient or market dead.
     */
    public function analyze(string $symbol, bool $skipRsiFilter = false): ?WaveAnalysis
    {
        $emaFast = (int) Settings::get('wave_ema_fast');
        $emaSlow = (int) Settings::get('wave_ema_slow');
        $rsiPeriod = (int) Settings::get('wave_rsi_period');
        $atrPeriod = (int) Settings::get('wave_atr_period') ?: 14;
        $rsiOverbought = (int) Settings::get('wave_rsi_overbought');
        $rsiOversold = (int) Settings::get('wave_rsi_oversold');

        $klines = $this->getCachedKlines($symbol);
        if ($klines === null) {
            return null;
        }

        $closes = array_column($klines, 'close');
        $minCandles = $emaSlow + 5;

        if (count($closes) < $minCandles) {
            return null;
        }

        $last = count($closes) - 1;
        $prev = $last - 1;

        // Calculate indicators
        $emaFastValues = $this->ta->calculateEMA($closes, $emaFast);
        $emaSlowValues = $this->ta->calculateEMA($closes, $emaSlow);
        $rsiValues = $this->ta->calculateRSI($closes, $rsiPeriod);
        $atrValues = $this->ta->calculateATR($klines, $atrPeriod);

        $emaFastNow = $emaFastValues[$last];
        $emaSlowNow = $emaSlowValues[$last];
        $emaFastPrev = $emaFastValues[$prev];
        $emaSlowPrev = $emaSlowValues[$prev];
        $rsiValue = $rsiValues[$last];
        $atrValue = end($atrValues);
        $currentPrice = $closes[$last];

        // ATR sanity check — reject dead markets
        if ($atrValue <= 0 || ($atrValue / $currentPrice) * 100 < 0.05) {
            return null;
        }

        // Direction from EMA alignment
        $direction = $emaFastNow > $emaSlowNow ? 'LONG' : 'SHORT';

        // Fresh cross detection
        $freshCross = ($emaFastNow > $emaSlowNow && $emaFastPrev <= $emaSlowPrev)
            || ($emaFastNow < $emaSlowNow && $emaFastPrev >= $emaSlowPrev);

        // RSI filter — reject overbought for LONG, oversold for SHORT
        if (! $skipRsiFilter) {
            if ($direction === 'LONG' && $rsiValue > $rsiOverbought) {
                return null;
            }
            if ($direction === 'SHORT' && $rsiValue < $rsiOversold) {
                return null;
            }
        }

        // Classify wave state
        $waveState = $this->classifyWave($emaFastValues, $emaSlowValues, $last, $freshCross);

        // EMA gap as percentage of price
        $emaGap = $currentPrice > 0 ? abs($emaFastNow - $emaSlowNow) / $currentPrice * 100 : 0;

        return new WaveAnalysis(
            direction: $direction,
            freshCross: $freshCross,
            rsi: round($rsiValue, 2),
            atr: $atrValue,
            currentPrice: $currentPrice,
            waveState: $waveState,
            emaGap: round($emaGap, 4),
        );
    }

    /**
     * Check if the wave is still intact for a given direction.
     * Used for DCA validation and wave-break exit detection.
     */
    public function isWaveIntact(string $symbol, string $direction): bool
    {
        $emaFast = (int) Settings::get('wave_ema_fast');
        $emaSlow = (int) Settings::get('wave_ema_slow');

        $klines = $this->getCachedKlines($symbol);
        if ($klines === null) {
            return false; // Can't verify, assume broken
        }

        $closes = array_column($klines, 'close');
        if (count($closes) < $emaSlow + 2) {
            return false;
        }

        $last = count($closes) - 1;
        $emaFastValues = $this->ta->calculateEMA($closes, $emaFast);
        $emaSlowValues = $this->ta->calculateEMA($closes, $emaSlow);

        if ($direction === 'LONG') {
            return $emaFastValues[$last] > $emaSlowValues[$last];
        }

        return $emaFastValues[$last] < $emaSlowValues[$last];
    }

    /**
     * Get the watchlist of symbols to scan.
     */
    public function getWatchlist(): array
    {
        $watchlist = (string) Settings::get('watchlist') ?: config('crypto.trading.watchlist');

        return array_filter(array_map('trim', explode(',', $watchlist)));
    }

    /**
     * Classify the current wave state based on EMA momentum.
     */
    private function classifyWave(array $emaFast, array $emaSlow, int $last, bool $freshCross): string
    {
        if ($freshCross) {
            return 'new_wave';
        }

        // Check if EMA gap is shrinking (wave weakening)
        if ($last >= 3) {
            $gaps = [];
            for ($i = $last - 2; $i <= $last; $i++) {
                $gaps[] = abs($emaFast[$i] - $emaSlow[$i]);
            }

            // All 3 gaps decreasing = weakening
            if ($gaps[2] < $gaps[1] && $gaps[1] < $gaps[0]) {
                return 'weakening';
            }
        }

        return 'riding';
    }

    /**
     * Get klines with in-memory caching to avoid duplicate API calls.
     */
    private function getCachedKlines(string $symbol): ?array
    {
        $now = microtime(true);

        if (isset($this->klineCache[$symbol])) {
            $cached = $this->klineCache[$symbol];
            if ($now - $cached['at'] < self::CACHE_TTL_SECONDS) {
                return $cached['data'];
            }
        }

        try {
            $limit = (int) Settings::get('wave_kline_limit') ?: 30;
            $interval = (string) Settings::get('wave_kline_interval') ?: '15m';
            $klines = $this->exchange->getKlines($symbol, $interval, $limit);

            $this->klineCache[$symbol] = [
                'data' => $klines,
                'at' => $now,
            ];

            return $klines;
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch klines for wave analysis', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
