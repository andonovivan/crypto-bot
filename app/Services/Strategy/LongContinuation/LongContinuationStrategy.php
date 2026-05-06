<?php

namespace App\Services\Strategy\LongContinuation;

use App\Services\Strategy\AbstractStrategy;
use App\Services\Strategy\Analysis;
use App\Services\Strategy\Candidate;
use App\Services\Strategy\Signal;

/**
 * Long-continuation strategy. Rides the +50–100% 24h pump band that the
 * short strategy explicitly avoids (research showed pumps ≥50% have a
 * continuation pattern with median 24h close +12.6% further). Uses the
 * standard pump_continuation reason on Signal so the engine logs a clear
 * attribution trail.
 */
class LongContinuationStrategy extends AbstractStrategy
{
    public function __construct(private readonly LongContinuationScanner $scanner) {}

    public function key(): string
    {
        return 'long_continuation';
    }

    public function label(): string
    {
        return 'Long-Continuation (rides +50–100% 24h pumps)';
    }

    public function side(): string
    {
        return 'LONG';
    }

    /** @return Candidate[] */
    public function getCandidates(): array
    {
        return $this->scanner->getCandidates();
    }

    public function analyze(string $symbol): ?Analysis
    {
        return $this->scanner->analyze($symbol);
    }

    public function buildSignal(Candidate $candidate, Analysis $analysis): Signal
    {
        return new Signal(
            symbol: $candidate->symbol,
            side: 'LONG',
            priceChangePct: $candidate->priceChangePct,
            reason: $candidate->reason,
            atr: $analysis->atr,
            strategyKey: $this->key(),
        );
    }
}
