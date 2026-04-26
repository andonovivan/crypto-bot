# Pump/Dump Pattern Research

**Data**: 15.9M × 15m klines for 652 USDT-perp symbols, 2025-05-01 to 2026-03-31 (11 months).
**Event clustering**: Counted as one event if the same symbol crossed the threshold within a 48h window.

| Bucket | Pumps (≥+25%/24h) | Dumps (≤-10%/24h) |
|---|---|---|
| Total events | 2,784 | 12,885 |
| Unique symbols | 535 | 577 |

---

## What's mutual across pump/dump events

### 1. Move-size distribution (the headline number)
Most "pumps" the bot detects are barely qualifying:
- **73% of pumps are 25–30%** (just over the threshold)
- 88% are <40%; only 4% are ≥75%; <2% exceed 100%

Most "dumps" are also barely qualifying:
- **85% of dumps are 10–12%**
- 96% are <15%; only 1% exceed 20%

**Takeaway**: the bot's threshold catches mostly small/marginal moves. The "extreme" examples on the screenshots (ORCA +58%, KAT -44%) are statistical outliers. They behave very differently from the bread-and-butter events (see §6 below).

### 2. Listing age: not as predictive as expected
| Age bucket | Pumps | Dumps |
|---|---|---|
| <14 days | 14% | 7% |
| <30 days | 21% | 15% |
| <60 days | 31% | 27% |
| <120 days | 51% | 48% |
| ≥120 days | 49% | 52% |

About a quarter of pumps come from coins <30 days old — modestly skewed young, but **not the dominant pattern.** Established coins make up half of all pumps.

### 3. Hour-of-day (UTC): one strong spike for pumps
| Time | Pump % | What happens then |
|---|---|---|
| **21:00 UTC** | **14.4%** | End of US trading day — retail-driven pumps |
| 16:00 UTC | 5.5% | NY market open continuation |
| 15:00 UTC | 5.1% | NY pre-market |
| All other hours | 2.4–5.0% | flat |

**Pumps cluster heavily at 21:00 UTC** (3× baseline). Dumps are more diffuse — small bumps at 00:00 (UTC midnight liquidations), 06:00 (Asia close), 15:00 (US open).

### 4. Volume profile (15m bar quote volume at signal)
- 67% of pumps happen with <5M USDT 15m volume (low-liquidity coins)
- 13% have ≥25M (highly liquid, often "real" trends)
- The bot's `min_volume_usdt=10M` 24h filter already removes most of the thin-volume pumps, but those are exactly the ones that **continue running**, not revert

### 5. Funding rate signal (today's screenshots)
- Top 3 pumpers had funding **at the cap of -2%** (ORCA, ENSO) or close to it (MIRA -1.6%) — heavy short-squeeze territory. Crowded shorts paying through the nose.
- Top dumpers had typical positive/zero funding (most around +0.005%) — no extreme funding signal preceded the dump.

### 6. **The most important finding: forward-return profile by move size**

This is what matters for a strategy. Forward returns measured from signal time (24h-change first crosses threshold):

#### Pumps — median forward return
| Move size | n | 4h close | 24h close | 24h **peak** (SL risk) | 24h **trough** (TP target) |
|---|---|---|---|---|---|
| Mild 25–30% | 2040 | -1.31% | **-4.76%** | +8.98% | **-10.18%** |
| Mod 30–50%  | 536  | -0.92% | -1.59% | +14.27% | -11.28% |
| Big 50–100% | 168  | +0.25% | **+12.56%** | +16.34% | -1.77% |
| Extreme >100% | 40 | +0.26% | +10.20% | +14.97% | -2.98% |

**Mild pumps (25–50%) reliably mean-revert. Big pumps (≥50%) keep running.** This is a clean, durable signal.

#### Pumps by 15m volume
| Vol bucket | n | 24h trough median |
|---|---|---|
| <1M (thin) | 712 | -4.88% (weak reversion) |
| 1–5M | 1159 | -11.14% |
| 5–25M | 790 | **-12.21%** (best) |
| ≥25M | 123 | -13.14% |

**Counter-intuitive**: super-thin pumps don't revert as much as mid-volume ones. They keep running because float is small.

#### Dumps — almost no edge except in extremes
| Move size | n | 24h close (LONG bounce) | 24h trough (SHORT continuation) |
|---|---|---|---|
| Medium 10–20% | 12,713 | +0.03% | -4.66% |
| **Large ≥20%** | **172** | **-2.28%** | **-5.94%** (continues down) |

Medium dumps are noise — both LONG-the-bounce and SHORT-the-continuation lose money after fees. **Only the extreme dumps (>20%) have a usable edge, and the edge is SHORT continuation, not LONG bounce.**

---

## Why the current strategy bleeds

### Drawup before the eventual TP triggers (median trade winner)
| Percentile | drawup before TP=2% wins |
|---|---|
| p25 | 0.99% |
| **p50 (median)** | **2.39%** |
| p75 | 5.28% |
| p90 | 11.38% |
| p95 | 17.42% |

**Half of eventual winners go ≥2.39% adverse before reverting.** With a 1% SL, you stop yourself out of half the wins.

### Time-to-target
- Median time-to-TP=2%: **1 bar (15 min)** — when the reversion comes, it's fast
- Median time-to-SL=1%: 2 bars (30 min) — losses also fast
- The current `Strict downtrend` filter (2 red 15m candles + EMA cross + HTF down) **delays entry by 30–60 min** past the signal. By then, the easy reversion has often already happened, and we enter near the local bottom.

### Strategy comparison (bar-by-bar simulation, color heuristic for tie-break, all 2,784 pumps)
| Strategy | Avg price PnL | Win rate |
|---|---|---|
| TP=2 / SL=1 (current) | **-0.090%** | 30.3% |
| TP=3 / SL=2 | -0.163% | 36.7% |
| TP=5 / SL=3 | -0.348% | 33.2% |
| TP=5 / SL=5 | -0.436% | 45.8% |
| **Trail (arm at -2%, trail 1.5%) + SL=3%** | **+0.225%** | **55.6%** |

On the **filtered** subset (25–50% pumps with 1–25M volume, n=1,901):
| Strategy | Avg price PnL | Win rate |
|---|---|---|
| TP=2 / SL=1 | -0.014% | 32.9% |
| TP=5 / SL=5 | +0.009% | 50.2% |
| **Trail (arm -2%, 1.5%) + SL=3%** | **+0.512%** | **58.0%** |

---

## Leverage recommendation

The required SL of ~3% to survive the typical drawup imposes a hard upper bound on leverage:

| Leverage | Max safe SL¹ | Margin EV per trade² | 5-loss drawdown³ |
|---|---|---|---|
| 25x (current) | 2.8% | (3% SL would liquidate) | 12.5% wallet |
| 15x | 4.7% | +4.19% | 7.5% wallet |
| **10x** | **7.0%** | **+2.79%** | **5.0% wallet** |
| 8x | 8.8% | +2.23% | 4.0% wallet |
| 5x | 14.0% | +1.40% | 2.5% wallet |
| 3x | 23.3% | +0.84% | 1.5% wallet |

¹ Max SL before liquidation × 0.7 buffer.
² On filtered B (25–30% pumps, 1–25M vol), best strategy `arm2 tr1.5 SL3`, after 0.10% round-trip fees, before funding.
³ At 10% margin sizing.

**Recommended: 10x leverage.** Gives the highest margin-EV-per-trade among configurations that can comfortably hold a 3% SL and tolerate a normal drawdown streak. 25x is fundamentally incompatible with the SL distance the data demands.

---

## Recommended strategy hypothesis to backtest

**Entry signal:**
1. 24h change crossed **+25% to +50%** (skip ≥50% — continuation patterns)
2. 15m bar quote volume **between 1M and 25M USDT** (skip both extremes)
3. Optional: prefer 21:00 UTC ±1h window (the retail-pump window) — needs a separate backtest pass to confirm
4. **Drop the `Strict downtrend` confirmation gate** (2 red candles + EMA cross + HTF down). Hypothesis: it costs more in delayed entry than it saves in false signals.
5. Keep the failed-entry cooldown.

**Position management:**
- Direction: SHORT
- **Stop loss: 3% above entry** (fixed pct, drop ATR sizing for now)
- **Take profit: trailing — armed at -2% favorable, trailing 1.5% above the running low**
- Max hold: 24h (vs current 2h — many wins materialize 4–24h after the signal)
- Leverage: **10x** (down from 25x)
- Position sizing: 5–10% margin (down from 10% to compensate for wider SL)

**Skip:**
- Pumps ≥50% (continuation territory, ORCA/ENSO/AGT-class moves)
- Sub-1M volume coins (too thin, keep running)
- Dumps in the 10–20% range (no edge)

**Optional add:** for **large dumps (≥20%)** like the screenshot examples (KAT -44%, TRADOOR -32%), open SHORT-continuation with TP=3% / SL=2%. Smaller sample (172 events over 11 months ≈ ~16/month), but n=172 with +0.524% per-trade expectancy and 50.6% win rate is the cleanest dump edge in the data.

---

## What this does NOT solve

- **Funding cost over a 24h hold** can erode 0.5-1% of price PnL per day on extreme-funding coins. The strategy needs a `funding_rate > -0.05%` guard (already present) but also a max-hold trigger if funding flips heavily against us mid-hold.
- **Slippage on entry**: post-only LIMIT entry assumes immediate fill. On a still-pumping coin, the LIMIT may sit at the ask while price runs up further. The fallback to MARKET is fine, but the simulation here assumed mid-price entry.
- **The 21:00 UTC clustering** is suggestive but not yet tested as a hard filter — would need a separate backtest pass.
- **Color heuristic for intra-bar SL/TP tie-break** is conservative-ish but still imprecise. A 1m kline backfill on the event windows would tighten this.
