<?php

namespace App\Services\Strategy\ShortScalp;

use App\Services\ShortScanner;
use App\Services\Strategy\AbstractStrategy;
use App\Services\Strategy\Analysis;
use App\Services\Strategy\Candidate;
use App\Services\Strategy\Signal;

/**
 * Adapter that wraps the existing ShortScanner behind StrategyInterface.
 * Phase 1: pure delegation — same API calls, same DB queries, same
 * thresholds. Maps ShortCandidate→Candidate, ShortAnalysis→Analysis,
 * Signal carries strategyKey='short_scalp' + side='SHORT'.
 *
 * Behaviour change: zero. Byte-identity regression test must pass.
 */
class ShortScalpStrategy extends AbstractStrategy
{
    public function __construct(private readonly ShortScanner $scanner) {}

    public function key(): string
    {
        return 'short_scalp';
    }

    public function label(): string
    {
        return 'Short-Scalp (24h pump/dump reversion)';
    }

    public function side(): string
    {
        return 'SHORT';
    }

    /** @return Candidate[] */
    public function getCandidates(): array
    {
        $out = [];
        foreach ($this->scanner->getCandidates() as $sc) {
            $out[] = new Candidate(
                symbol: $sc->symbol,
                price: $sc->price,
                priceChangePct: $sc->priceChangePct,
                volume: $sc->volume,
                reason: $sc->reason,
            );
        }
        return $out;
    }

    public function analyze(string $symbol): ?Analysis
    {
        $sa = $this->scanner->analyze15m($symbol);
        if (! $sa) {
            return null;
        }

        // Surface short-side fields under `fields` so the dashboard can
        // render them. The orchestrator only consumes `ok` and `atr`.
        return new Analysis(
            ok: $sa->downtrendOk,
            blockedReason: $sa->blockedReason,
            atr: $sa->atr,
            fields: [
                'currentPrice' => $sa->currentPrice,
                'emaFast' => $sa->emaFast,
                'emaSlow' => $sa->emaSlow,
                'candleBodyPct' => $sa->candleBodyPct,
                'lastCandleRed' => $sa->lastCandleRed,
                'priorCandleRed' => $sa->priorCandleRed,
                'fundingRate' => $sa->fundingRate,
                'higherTfDowntrendOk' => $sa->higherTfDowntrendOk,
            ],
        );
    }

    public function buildSignal(Candidate $candidate, Analysis $analysis): Signal
    {
        return new Signal(
            symbol: $candidate->symbol,
            side: 'SHORT',
            priceChangePct: $candidate->priceChangePct,
            reason: $candidate->reason,
            atr: $analysis->atr,
            strategyKey: $this->key(),
        );
    }
}
