<?php

namespace App\Services\Strategy;

/**
 * Generic analysis DTO returned by StrategyInterface::analyze(). The
 * orchestrator only uses two fields directly: `ok` (proceed?) and `atr`
 * (passed to the engine for ATR-based SL sizing). Everything else lives
 * under `fields` as a free-form keyed array — the dashboard / scanner
 * page can render whatever the strategy chose to expose.
 *
 * This intentionally generalises ShortAnalysis: short-specific keys like
 * `lastCandleRed` / `downtrendOk` move into `fields` so a long strategy
 * can expose `lastCandleGreen` / `uptrendOk` without DTO branching.
 */
class Analysis
{
    /**
     * @param  array<string, mixed>  $fields  Arbitrary metadata for UI / debugging.
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $blockedReason = null,
        public readonly float $atr = 0.0,
        public readonly array $fields = [],
    ) {}
}
