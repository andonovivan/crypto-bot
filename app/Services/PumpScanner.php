<?php

namespace App\Services;

use App\Enums\SignalStatus;
use App\Models\PumpSignal;
use App\Models\ScannedCoin;
use App\Services\Exchange\ExchangeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PumpScanner
{
    public function __construct(
        private ExchangeInterface $exchange,
    ) {}

    /**
     * Scan all futures pairs for pump signals.
     *
     * @return Collection<int, PumpSignal>
     */
    public function scan(): Collection
    {
        $minPriceChange = Settings::get('min_price_change_pct');
        $minVolumeMultiplier = Settings::get('min_volume_multiplier');
        $signals = collect();

        Log::info('Starting pump scan', [
            'min_price_change' => $minPriceChange,
            'min_volume_multiplier' => $minVolumeMultiplier,
        ]);

        $tickers = $this->exchange->getFuturesTickers();

        foreach ($tickers as $ticker) {
            $this->updateScannedCoin($ticker);

            if ($ticker['priceChangePct'] < $minPriceChange) {
                continue;
            }

            $volumeMultiplier = $this->calculateVolumeMultiplier($ticker);

            if ($volumeMultiplier < $minVolumeMultiplier) {
                continue;
            }

            // Check if we already have an active signal for this symbol
            $existingSignal = PumpSignal::where('symbol', $ticker['symbol'])
                ->whereIn('status', [SignalStatus::Detected, SignalStatus::ReversalConfirmed])
                ->where('created_at', '>=', now()->subHours(24))
                ->first();

            if ($existingSignal) {
                $this->updateExistingSignal($existingSignal, $ticker);
                $signals->push($existingSignal);
                continue;
            }

            $signal = PumpSignal::create([
                'symbol' => $ticker['symbol'],
                'pump_price' => $ticker['price'],
                'peak_price' => $ticker['high'],
                'current_price' => $ticker['price'],
                'price_change_pct' => $ticker['priceChangePct'],
                'volume_multiplier' => $volumeMultiplier,
                'drop_from_peak_pct' => $this->calculateDropFromPeak($ticker['price'], $ticker['high']),
                'status' => SignalStatus::Detected,
            ]);

            Log::info('Pump detected', [
                'symbol' => $ticker['symbol'],
                'price_change' => $ticker['priceChangePct'] . '%',
                'volume_multiplier' => $volumeMultiplier . 'x',
                'price' => $ticker['price'],
                'high_24h' => $ticker['high'],
            ]);

            $signals->push($signal);
        }

        Log::info('Pump scan complete', ['signals_found' => $signals->count()]);

        return $signals;
    }

    /**
     * Check detected signals for reversal confirmation.
     *
     * @return Collection<int, PumpSignal>
     */
    public function checkReversals(): Collection
    {
        $reversalDropPct = Settings::get('reversal_drop_pct');
        $confirmedSignals = collect();

        $activeSignals = PumpSignal::where('status', SignalStatus::Detected)
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        foreach ($activeSignals as $signal) {
            try {
                $currentPrice = $this->exchange->getPrice($signal->symbol);
                $dropFromPeak = $this->calculateDropFromPeak($currentPrice, $signal->peak_price);

                $signal->update([
                    'current_price' => $currentPrice,
                    'drop_from_peak_pct' => $dropFromPeak,
                ]);

                // Update peak if price went higher
                if ($currentPrice > $signal->peak_price) {
                    $signal->update(['peak_price' => $currentPrice]);
                    continue;
                }

                // Check if reversal is confirmed
                if ($dropFromPeak >= $reversalDropPct) {
                    $signal->update(['status' => SignalStatus::ReversalConfirmed]);

                    Log::info('Reversal confirmed', [
                        'symbol' => $signal->symbol,
                        'peak' => $signal->peak_price,
                        'current' => $currentPrice,
                        'drop' => $dropFromPeak . '%',
                    ]);

                    $confirmedSignals->push($signal->fresh());
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to check reversal', [
                    'symbol' => $signal->symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $confirmedSignals;
    }

    /**
     * Expire old signals that never confirmed.
     */
    public function expireStaleSignals(): int
    {
        return PumpSignal::where('status', SignalStatus::Detected)
            ->where('created_at', '<', now()->subHours(24))
            ->update(['status' => SignalStatus::Expired]);
    }

    private function updateScannedCoin(array $ticker): void
    {
        $coin = ScannedCoin::firstOrNew(['symbol' => $ticker['symbol']]);

        // Update rolling average volume using exponential moving average.
        // Weight factor 1/7 approximates a 7-day average that converges over time.
        if ($coin->avg_volume_7d > 0) {
            $alpha = 1 / 7;
            $coin->avg_volume_7d = ($alpha * $ticker['volume']) + ((1 - $alpha) * $coin->avg_volume_7d);
        } else {
            $coin->avg_volume_7d = $ticker['volume'];
        }

        $coin->fill([
            'price' => $ticker['price'],
            'price_change_pct_24h' => $ticker['priceChangePct'],
            'volume_24h' => $ticker['volume'],
            'high_24h' => $ticker['high'],
            'low_24h' => $ticker['low'],
            'volume_multiplier' => $coin->avg_volume_7d > 0
                ? $ticker['volume'] / $coin->avg_volume_7d
                : 1.0,
            'last_scanned_at' => now(),
        ]);

        $coin->save();
    }

    private function updateExistingSignal(PumpSignal $signal, array $ticker): void
    {
        $updates = [
            'current_price' => $ticker['price'],
            'drop_from_peak_pct' => $this->calculateDropFromPeak($ticker['price'], $signal->peak_price),
        ];

        if ($ticker['price'] > $signal->peak_price) {
            $updates['peak_price'] = $ticker['price'];
        }

        $signal->update($updates);
    }

    private function calculateVolumeMultiplier(array $ticker): float
    {
        $coin = ScannedCoin::where('symbol', $ticker['symbol'])->first();

        if ($coin && $coin->avg_volume_7d > 0) {
            return $ticker['volume'] / $coin->avg_volume_7d;
        }

        // First scan for this coin — no historical data yet.
        // Be conservative: return 1.0 so it won't trigger a false signal.
        // The average will build up over subsequent scans.
        return 1.0;
    }

    private function calculateDropFromPeak(float $currentPrice, float $peakPrice): float
    {
        if ($peakPrice <= 0) {
            return 0;
        }

        return round((($peakPrice - $currentPrice) / $peakPrice) * 100, 4);
    }
}
