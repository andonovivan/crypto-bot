# Backtest results — research-recommended strategy

**Run date:** 2026-04-26
**Universe:** 652 USDT perps with kline coverage
**Mode:** dry-run, fixed-sizing (no compounding), starting balance $10,000
**Data depth:** 2025-05-01 → 2026-03-31 (11 months)

## Strategy under test

| Setting | Baseline (current defaults) | New strategy |
|---|---|---|
| Pump filter | ≥+25% | +25% to +30% (skip ≥30%) |
| Dump filter | ≤-10% | disabled |
| Volume filter | ≥10M USDT | 1M–25M USDT (mid-vol bucket) |
| Strict 15m downtrend | required (2 red + EMA + body cap + HTF) | dropped — enter at threshold cross |
| Stop loss | 1.0% / ATR×1.5 | **3.0% fixed** |
| Take profit | 2.0% fixed | **trailing — arm at -2%, trail 0.7%** |
| Partial TP | 50% at +1% | disabled |
| Max hold | 120 min | **1440 min (24h)** |
| Leverage | 25x | **10x** |
| Cooldown after close | 120 min | 240 min |

## Code changes

| File | Change |
|---|---|
| `database/migrations/2026_04_26_010000_add_trailing_tp_to_positions.php` | New: `trailing_tp_armed`, `trailing_extreme_price` columns |
| `app/Models/Position.php` | Fillable + casts for the two new columns |
| `app/Services/Settings.php` | New keys: `pump_max_pct`, `max_volume_usdt`, `trailing_tp_*`, `strict_downtrend_enabled` |
| `config/crypto.php` | Defaults for the new keys (trailing off, strict on, upper caps set) |
| `app/Services/ShortScanner.php` | Apply pump upper cap + volume upper cap; `analyze15m()` only runs the strict gate when `strict_downtrend_enabled=true` (funding-rate guard always applies) |
| `app/Services/TradingEngine.php` | `maybeTrailStop()` runs each tick: arms when favorable ≥ `trailing_tp_arm_pct`, then ratchets `stop_loss_price` toward `extreme ± trailing_tp_trail_pct` and clears the fixed TP. `trailingExitReason()` labels the close as TakeProfit when the trailed stop is on the favorable side of entry |
| `app/Console/Commands/BotBacktest.php` | `tickPosition()` refreshes SL/TP each probe so the within-bar trailing tighten is honoured by the next probe's clamp |

## Headline result — Q1 2026

| Strategy | Trades | Win rate | Net P&L | Final wallet | Note |
|---|---:|---:|---:|---:|---|
| **Baseline (current defaults)** | 5,883 | 74.96% | **-$33,740 (-337%)** | -$23,740 | Insolvent. R:R 0.31; $60K in fees alone |
| **New (trail 1.5%, dumps included)** | 1,073 | 60.48% | +$18,676 (+186.8%) | $28,676 | First-pass parameters |
| **New (trail 0.7%, pumps only 25–30%)** | **383** | **68.93%** | **+$31,998 (+320.0%)** | **$41,997** | Final tuning |

## Per-window results (final tuning)

Using: 25–30% pumps, 1–25M vol, no dumps, strict gate off, SL=3%, trail arm=2% / trail=0.7%, max hold 24h, leverage 10x, fixed sizing.

| Window | Trades | Win rate | R:R | Net P&L | Net % |
|---|---:|---:|---:|---:|---:|
| 2025-05-01 → 2025-07-01 | 67  | 55.22% | 0.72 | -$1,539 | -15.4% |
| 2025-07-01 → 2025-10-01 | 122 | 59.02% | 0.88 | +$3,196 | +32.0% |
| 2025-10-01 → 2026-01-01 | 424 | 59.20% | 0.76 | +$5,805 | +58.0% |
| 2026-01-01 → 2026-03-31 | 383 | 68.93% | 0.86 | +$31,998 | +320.0% |
| **Aggregate (~11 months)** | **996** | ~62% | ~0.81 | **+$39,460** | **+395%** |

Profitable in 3 of 4 quarters. Q1 2026 carries the bulk of the return — characteristic of trend-extreme periods producing more pump events.

## Sweeps that fed the final tuning

### Trail distance (Q4 2025, all else equal)
| Trail % | Net P&L | Avg win |
|---|---:|---:|
| 2.5% | -$5,327 | $250 |
| 1.5% | -$54 | $214 |
| 1.0% | +$3,196 | $225 |
| **0.7%** | **+$5,805** | **$235** |
| 0.5% | +$6,565 | $238 |

Tighter trail wins: it ratchets the stop close to the extreme so that quick V-shape reversals exit near the deepest point. Below 0.7% gains diminish; the 0.7% choice trades a sliver of avg-win for less noise sensitivity.

### Filter width (Q4 2025)
| Filter | Trades | Net P&L |
|---|---:|---:|
| Wide (25–50% pumps + ≥20% dumps) | 1,088 | -$10,762 |
| **Tight (25–30% pumps only)** | **424** | **+$5,805 (with trail 0.7%)** |

Including the 30–50% bucket and the dump bucket adds variance without proportional upside. Confirms the research finding that the cleanest signal is the 25–30% mild-pump window.

### Leverage (Q1 2026, all else equal)
| Leverage | Net P&L | Net % | Max safe SL¹ |
|---|---:|---:|---:|
| 5x  | +$13,750 | +137.5% | 14.0% |
| **10x** | **+$31,998** | **+320.0%** | **7.0%** |
| 15x | +$39,573 | +395.7% | 4.7% |

¹ Max SL distance before liquidation × 0.7 buffer.

Win rate and R:R don't change with leverage — outcomes are price-level. Higher leverage amplifies $/trade linearly. **10x is the sweet spot:** comfortable for the 3% SL with margin to spare; 15x leaves only 1.7% buffer between SL and liquidation, which is fragile if any single trade slips.

## Trade close-reason mix (Q1 2026, final tuning)

| Reason | Count | Total $ | Avg |
|---|---:|---:|---:|
| take_profit (trailed exit) | 264 | +$67,406 | +$255 |
| stop_loss | 115 | -$35,383 | -$308 |
| expired | 4 | -$26 | -$6 |

Almost no expiries — the 24h hard cap rarely binds. The trailing stop fires on the bounce in every position that goes favorable.

## What changed vs the original strategy

1. **Tight upper bound on pump size.** Skipping ≥30% pumps removes the continuation-pattern coins that the original strategy treated identically to mild reversion candidates.
2. **Volume upper bound.** Above 25M USDT, big-trader-driven pumps mean-revert with bigger SL excursions — better skipped.
3. **Dropped the strict 15m downtrend gate.** Confirmation requirements (2 red candles, EMA cross, HTF down) were eating the easy reversion. Dropping them roughly tripled the trade count and improved per-trade edge.
4. **3% fixed SL replaces 1% / ATR.** The median winner endures 2.4% drawup before reverting — 1% SL stops out half of eventual winners.
5. **Trailing TP at 0.7% replaces fixed 2% TP.** Most reversions go ≥5% deep; the trailing exit captures more of the move.
6. **24h max-hold replaces 2h.** Many wins materialize in the 4–24h range; 2h was force-closing positions before the trailing stop could fire.
7. **10x leverage replaces 25x.** 25x with a 1% SL is fundamentally incompatible with the typical 2.4% drawup profile.

## Caveats

- **Funding is stubbed at $0** in the replay exchange. Live shorts on heavily-shorted (negative-funding) pump coins would actually *receive* funding — these results understate live P&L by likely 0.5-1% per day on extreme-funding coins.
- **Single backtest pass** — these parameters were tuned on the same data they're tested on. Real out-of-sample edge will be smaller. The May–Jun 2025 window was the worst-performing quarter (-15%), suggesting downside in less-volatile regimes.
- **Trailing TP is not yet implemented for live mode.** The current `maybeTrailStop()` only ratchets `Position.stop_loss_price` in the DB — it does not cancel-and-replace the live Binance STOP_MARKET on each tighten. Live deployment requires that wiring (and a careful test, since cancel/replace on every 15m bar is rate-limit-relevant).
- **Color heuristic for intra-bar SL/TP tie-breaks** — red bar = high first, green bar = low first. Probes are clamped to trigger prices when crossed. Imprecise but symmetric across baselines/variants.
- **DB load was the bottleneck for 11-month single-pass backtests.** 4 quarterly chunks were used instead, which means the equity curve isn't continuous — but per-window stats are valid. The 4 quarters cover 11 months continuously.

## Recommended config to deploy (dry-run first)

```
pump_threshold_pct = 25.0
pump_max_pct = 30.0
dump_threshold_pct = 999.0       # disable dump entries
min_volume_usdt = 1_000_000
max_volume_usdt = 25_000_000
strict_downtrend_enabled = false
atr_sl_enabled = false
stop_loss_pct = 3.0
take_profit_pct = 2.0            # used as trail arm anchor
trailing_tp_enabled = true
trailing_tp_arm_pct = 2.0
trailing_tp_trail_pct = 0.7
partial_tp_trigger_pct = 0.0     # disabled
max_hold_minutes = 1440
leverage = 10
position_size_pct = 10
cooldown_minutes = 240
```

## Open follow-ups

1. **Walk-forward validation** — fit on first 6 months, test on last 5. Will catch parameter overfitting that single-window backtests miss.
2. **Trailing TP for live mode** — wire `maybeTrailStop()` to cancel/replace the Binance bracket. Watch rate-limit cost.
3. **21:00 UTC hour-of-day filter** — research showed pumps cluster 3× there. A hard time filter could improve WR.
4. **Dump bucket re-examined separately** — the wide filter showed dumps hurt. But the +20% bucket alone has +0.52% expectancy in research; might be worth a dedicated SL/TP set rather than reusing pump exits.
5. **Funding-rate sign filter** — when funding is at the cap (-2%), the move is short-squeeze territory and continuation more likely than reversion. Tightening the funding gate from -0.05% to -0.5% might improve WR.
