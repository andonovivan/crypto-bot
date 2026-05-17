<?php

namespace App\Services\Strategy;

/**
 * Contract for a pluggable trading strategy. Each strategy owns its own
 * candidate discovery and per-symbol analysis; the orchestrator (BotRun /
 * BotBacktest) iterates registered+enabled strategies, asks them for
 * candidates, applies shared gates (per-symbol open guard, cooldowns,
 * max_positions), and routes the resulting Signal to TradingEngine::open().
 *
 * The contract intentionally mirrors how ShortScanner already works
 * (getCandidates → analyze15m → openShort) so existing code paths can be
 * wrapped with zero behavioural change.
 */
interface StrategyInterface
{
    /** Stable machine-readable identifier (e.g. 'short_scalp', 'long_bounce'). */
    public function key(): string;

    /** Human-readable label for the dashboard. */
    public function label(): string;

    /** 'SHORT' | 'LONG' | 'BOTH' — used by UI hints (manual entry button label, etc.). */
    public function side(): string;

    /** Reads strategy.<key>.enabled. False = orchestrator skips this strategy. */
    public function isEnabled(): bool;

    /**
     * Discover candidate symbols for this cycle. Generally one external API
     * call (24h ticker scan). Pre-filtered by the strategy's own thresholds.
     *
     * @return Candidate[]
     */
    public function getCandidates(): array;

    /**
     * Per-symbol technical analysis. Reads klines, computes EMA/ATR/etc.,
     * returns null when the symbol has insufficient data. The Analysis DTO's
     * `ok` flag is the strategy-specific entry decision; orchestrator only
     * proceeds when `ok=true`.
     */
    public function analyze(string $symbol): ?Analysis;

    /**
     * Compose the final Signal once gates have passed. Strategy fills in
     * side, strategyKey, ATR, and any meta the engine should record.
     */
    public function buildSignal(Candidate $candidate, Analysis $analysis): Signal;
}
