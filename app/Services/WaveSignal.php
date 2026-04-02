<?php

namespace App\Services;

/**
 * Lightweight DTO that satisfies the TradingEngine::openPosition(object $signal, ...) interface.
 * Not an Eloquent model — never persisted to DB.
 */
class WaveSignal
{
    public readonly int $score;

    public function __construct(
        public readonly string $symbol,
        public readonly string $direction,
        public readonly float $atr_value,
        public readonly float $currentPrice,
        public readonly float $rsi,
    ) {
        $this->score = 0; // Wave signals don't use scoring
    }

    /**
     * No-op update — TradingEngine calls $signal->update() after opening a position.
     * WaveSignals are ephemeral and don't need status tracking.
     */
    public function update(array $attributes = []): bool
    {
        return true;
    }
}
