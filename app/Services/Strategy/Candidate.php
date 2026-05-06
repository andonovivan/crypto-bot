<?php

namespace App\Services\Strategy;

/**
 * Generic candidate DTO emitted by strategy scanners. A candidate is a
 * symbol that passed the cheap 24h-ticker filters (price-change band,
 * volume window) but has not yet been analysed against per-symbol klines.
 *
 * Mirrors the shape of the legacy ShortCandidate so the wrapper strategy
 * can map 1:1 without losing fidelity.
 */
class Candidate
{
    public function __construct(
        public readonly string $symbol,
        public readonly float $price,
        public readonly float $priceChangePct,
        public readonly float $volume,
        public readonly string $reason,
    ) {}
}
