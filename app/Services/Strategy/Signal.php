<?php

namespace App\Services\Strategy;

use App\Services\ShortSignal;

/**
 * Generic entry-signal DTO consumed by TradingEngine::open(). Replaces
 * ShortSignal (kept as a deprecated shim through Phase 4). The strategy's
 * key is recorded so Position/Trade rows can be attributed back to it.
 */
class Signal
{
    /**
     * @param  array<string, mixed>  $meta  Free-form pass-through (e.g. analysis fields the engine should log).
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $side,           // 'SHORT' | 'LONG'
        public readonly float $priceChangePct,
        public readonly string $reason,
        public readonly float $atr,
        public readonly string $strategyKey,
        public readonly array $meta = [],
    ) {}

    /**
     * Adapter for the legacy ShortSignal shape. Used by the ShortScalp
     * strategy wrapper while the rest of the codebase migrates off
     * ShortSignal incrementally.
     */
    public static function fromShortSignal(ShortSignal $s, string $strategyKey = 'short_scalp'): self
    {
        return new self(
            symbol: $s->symbol,
            side: 'SHORT',
            priceChangePct: $s->priceChangePct,
            reason: $s->reason,
            atr: $s->atr,
            strategyKey: $strategyKey,
        );
    }
}
