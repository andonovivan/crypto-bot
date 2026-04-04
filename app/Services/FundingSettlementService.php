<?php

namespace App\Services;

use App\Enums\PositionStatus;
use App\Models\Position;
use App\Services\Exchange\ExchangeInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handles funding rate settlement for open positions.
 *
 * Binance perpetual futures settle funding every 8 hours (00:00, 08:00, 16:00 UTC).
 * Positive funding rate: longs pay shorts. Negative: shorts pay longs.
 * This service simulates funding settlement for both live and dry-run modes.
 */
class FundingSettlementService
{
    public function __construct(
        private ExchangeInterface $exchange,
    ) {}

    /**
     * Check if a funding settlement boundary has passed and apply funding to open positions.
     * Designed to be called every bot tick (30s). Returns immediately if no settlement needed.
     */
    public function settleFunding(): void
    {
        if (! Settings::get('funding_tracking_enabled')) {
            return;
        }

        $lastSettlement = $this->getLastSettlementTime();

        // Find positions that haven't been settled for the current window
        $positions = Position::where('status', PositionStatus::Open)
            ->where(function ($query) use ($lastSettlement) {
                $query->whereNull('last_funding_at')
                    ->orWhere('last_funding_at', '<', $lastSettlement);
            })
            ->get();

        if ($positions->isEmpty()) {
            return;
        }

        // Fetch funding rates (cached 60s, weight 10 for all symbols)
        try {
            $fundingRates = $this->exchange->getFundingRates();
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch funding rates for settlement', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($positions as $position) {
            $rate = $fundingRates[$position->symbol]['fundingRate'] ?? null;

            if ($rate === null) {
                continue;
            }

            // Walk through any missed settlement windows
            $settlementTime = $position->last_funding_at
                ? $this->getNextSettlementAfter($position->last_funding_at)
                : $lastSettlement;

            while ($settlementTime <= $lastSettlement) {
                $this->applyFunding($position, $rate, $settlementTime);
                $settlementTime = $settlementTime->copy()->addHours(8);
            }
        }
    }

    /**
     * Apply a single funding settlement to a position.
     */
    private function applyFunding(Position $position, float $rate, Carbon $settlementTime): void
    {
        $notional = ($position->current_price ?? $position->entry_price) * $position->quantity;

        // Sign convention:
        // Positive rate: longs pay (-), shorts receive (+)
        // Negative rate: longs receive (+), shorts pay (-)
        $payment = $position->side === 'LONG'
            ? -($notional * $rate)
            : +($notional * $rate);

        $payment = round($payment, 8);
        $cumulative = round(($position->funding_fee ?? 0) + $payment, 8);

        $position->update([
            'funding_fee' => $cumulative,
            'last_funding_at' => $settlementTime,
        ]);

        Log::info('Funding settled', [
            'symbol' => $position->symbol,
            'side' => $position->side,
            'rate' => $rate,
            'payment' => $payment,
            'cumulative' => $cumulative,
            'settlement' => $settlementTime->toDateTimeString(),
        ]);
    }

    /**
     * Get the most recent 8-hour funding settlement boundary (UTC).
     * Binance settles at 00:00, 08:00, 16:00 UTC.
     */
    private function getLastSettlementTime(): Carbon
    {
        $now = Carbon::now('UTC');
        $hour = (int) floor($now->hour / 8) * 8;

        $settlement = $now->copy()->setTime($hour, 0, 0);

        // If we somehow landed in the future (shouldn't happen), go back 8h
        if ($settlement->isFuture()) {
            $settlement->subHours(8);
        }

        return $settlement;
    }

    /**
     * Get the next 8-hour settlement boundary after a given time.
     */
    private function getNextSettlementAfter(Carbon $time): Carbon
    {
        $utc = $time->copy()->setTimezone('UTC');
        $hour = (int) (floor($utc->hour / 8) + 1) * 8;

        if ($hour >= 24) {
            return $utc->copy()->addDay()->setTime($hour - 24, 0, 0);
        }

        return $utc->copy()->setTime($hour, 0, 0);
    }
}
