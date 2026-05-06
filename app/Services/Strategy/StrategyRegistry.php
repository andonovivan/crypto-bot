<?php

namespace App\Services\Strategy;

/**
 * In-memory registry of trading strategies, populated at boot from
 * config/strategies.php via StrategyServiceProvider. Bound as a singleton
 * so BotRun, BotBacktest, and the dashboard see the same set.
 *
 * `inOrder()` returns strategies in the order declared in the config —
 * relevant when two strategies fire on the same symbol in one cycle
 * (first-in-order wins under the cross-strategy dedupe rule).
 */
class StrategyRegistry
{
    /**
     * @param  array<string, StrategyInterface>  $strategies  Keyed by strategy key.
     * @param  array<int, string>  $order  Ordered list of strategy keys.
     */
    public function __construct(
        private readonly array $strategies,
        private readonly array $order,
    ) {}

    /** @return array<string, StrategyInterface> */
    public function all(): array
    {
        return $this->strategies;
    }

    /** @return StrategyInterface[] strategies in the configured order. */
    public function inOrder(): array
    {
        $out = [];
        foreach ($this->order as $key) {
            if (isset($this->strategies[$key])) {
                $out[] = $this->strategies[$key];
            }
        }
        return $out;
    }

    /** @return StrategyInterface[] enabled strategies in configured order. */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->inOrder(),
            fn (StrategyInterface $s) => $s->isEnabled(),
        ));
    }

    public function find(string $key): ?StrategyInterface
    {
        return $this->strategies[$key] ?? null;
    }

    /** @return string[] */
    public function keys(): array
    {
        return $this->order;
    }
}
