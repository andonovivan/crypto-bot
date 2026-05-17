<?php

namespace App\Services\Strategy\LongBase;

use App\Services\Strategy\AbstractStrategy;
use App\Services\Strategy\Analysis;
use App\Services\Strategy\Candidate;
use App\Services\Strategy\Signal;

/**
 * Transient shared adapter base for the 20 long-strategy variants. Wraps a
 * variant-specific LongScannerBase implementation behind StrategyInterface.
 *
 * Phase 5 flatten plan: when the winning variant is promoted, inline this
 * adapter's methods into the winner's Strategy class and remove this base.
 *
 * Each concrete variant subclass declares:
 *   • the scanner type via the constructor (it gets DI'd)
 *   • key(): the registry/Settings/cache namespace
 *   • label(): human-readable name for the dashboard
 */
abstract class LongStrategyBase extends AbstractStrategy
{
    public function __construct(protected readonly LongScannerBase $scanner) {}

    abstract public function key(): string;

    abstract public function label(): string;

    public function side(): string
    {
        return 'LONG';
    }

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
