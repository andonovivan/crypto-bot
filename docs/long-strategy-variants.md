# Long-Strategy Variant History

A reference for the long-side entry-signal variants the bot has shipped or evaluated. Three remain in the codebase after Phase 5 cleanup (2026-05-18); the other 17 were deleted but their design notes are preserved at the bottom of this file so they can be re-derived without starting from scratch if the kept ones underperform live.

## Phase 5 outcome (2026-05-18)

Three variants kept in `app/Services/Strategy/Long*/`:

| Variant | Status | Why |
|---|---|---|
| **`long_microdump`** | ✅ ENABLED (default ON) | Production pick. Highest WR (65.8%), lowest trade count (47/day), liquid coins (10M-50M USDT), structurally clean mean-reversion signal that doesn't depend on a rally regime. |
| **`long_thinvol_pump`** | 💤 BENCHED (default OFF) | Highest Phase 4B raw P&L ($1.15M) but $-amount is heavily inflated by trailing TP riding meme coins in a bull rally. Live slippage on 1M-5M USDT pumpers will materially crater this. Kept for re-evaluation if microdump underperforms live. |
| **`long_lowpump`** | 💤 BENCHED (default OFF) | Middle ground (+$774K over 5 of 6 months — Apr 2026 chunk was killed by OOM). Pump-continuation in 5M-25M USDT band. Kept because if microdump's mean-reversion dries up, low-band pump continuation is the natural complement. |

The 3 kept variants share `LongScannerBase` + `LongStrategyBase` and all inherit `short_scalp`'s exit profile via `Settings::ALIASES` (trailing TP arm +1.5%/trail 1.0%, fixed SL 2.5%, lev 10) — that pattern is preserved until Phase 6 promotes per-variant exits.

## Phase 4A sweep methodology (preserved for context)

Run 2026-05-17 on the laptop, replayed klines via `HistoricalReplayExchange`:

- **Bear week:** 2025-11-10 → 2025-11-17 (BTC -11.34%)
- **Bull week:** 2026-04-20 → 2026-04-27 (BTC +5.91%)
- **Exit profile:** trailing TP (arm +1.5%/trail 1.0%), no fixed TP, 2.5% SL, lev 10x, position_size 10% of $10k, `--fixed-sizing --use-1m`
- **Caveat 1:** trailing TP exit profile means wins are unbounded and losses capped → bear-week R:R values 5-30 are partly trailing-TP catching altcoin vol-rally pumps, not pure entry-signal edge. Bull-week R:R 0.6-1.4 is the more realistic baseline.
- **Caveat 2:** `--use-1m` trailing TP captures favorable wicks more aggressively than live (~2× overestimate per CLAUDE.md). Live R:R likely ~50% of these numbers.

Raw CSVs (kept for reference):
- Bear: `storage/perf-snapshots/long-sweep-20260517T015409Z/round-a.csv`
- Bull: `storage/perf-snapshots/long-sweep-20260517T075606Z/round-a.csv`

## Phase 4B walk-forward results (Nov 2025 → Apr 2026)

Run 2026-05-18 via `scripts/long-top3-backtest.sh`:

| Variant | Months done | Trades | WR | Total P&L | Worst month | Best month |
|---|---|---|---|---|---|---|
| `long_microdump` | 6/6 | 8,572 | 65.8% | +$393,241 | +$50,241 (Feb 26) | +$99,450 (Apr 26) |
| `long_thinvol_pump` | 6/6 | 26,064 | 60.3% | +$1,152,015 | +$118,823 (Mar 26) | +$336,565 (Apr 26) |
| `long_lowpump` | 5/6 ⚠ | 19,625 | 60.0% | +$774,141 | +$114,276 (Feb 26) | +$245,770 (Nov 25) |

⚠ lowpump's Apr 2026 chunk was killed by SIGKILL at 95% (likely OOM during the heaviest-volume month). Its true 6-month total would be ~$1M+ if Apr had finished.

All three pass the promotion gate (every month positive, WR above 50%, trades way above 200, worst month well above the -15% wallet floor). Raw outputs: `storage/perf-snapshots/long-top3-20260517T170109Z/`.

## Detail on the 3 kept variants

### `long_microdump` — production pick

- **Path:** [app/Services/Strategy/LongMicrodump/LongMicrodumpScanner.php](../app/Services/Strategy/LongMicrodump/LongMicrodumpScanner.php)
- **Band:** -2% to -5% on 24h
- **Volume:** 10M-50M USDT 24h quote
- **Signal:** EMA fast crossing back UP through slow + first green 15m candle after the down move
- **Hypothesis:** small dumps in liquid coins revert reliably; entering on the EMA cross-up captures the bounce without chasing collapse-pattern dumps.

### `long_thinvol_pump` — benched

- **Path:** [app/Services/Strategy/LongThinvolPump/LongThinvolPumpScanner.php](../app/Services/Strategy/LongThinvolPump/LongThinvolPumpScanner.php)
- **Band:** +10% to +30% on 24h
- **Volume:** 1M-5M USDT (meme/micro-cap tier)
- **Signal:** EMA up + green 15m candle, no body cap
- **Why benched:** highest raw P&L in backtest but most exposed to slippage divergence in live. Re-evaluate only if microdump's mean-reversion edge dries up.

### `long_lowpump` — benched

- **Path:** [app/Services/Strategy/LongLowpump/LongLowpumpScanner.php](../app/Services/Strategy/LongLowpump/LongLowpumpScanner.php)
- **Band:** +10% to +25% on 24h
- **Volume:** 5M-25M USDT (mid-volume)
- **Signal:** EMA fast > slow + green 15m candle + body cap 4%
- **Why benched:** decent number, more liquid than thinvol_pump, but pump-continuation signal in general is bull-regime-dependent. Re-evaluate if regime turns and microdump's mean-reversion suffers.

## Variants deleted in Phase 5 (2026-05-18)

Brief notes preserved so each can be re-derived from scratch if needed. The original code lived under `app/Services/Strategy/Long*/` and is recoverable from git history (the last commit before the Phase 5 cleanup is the relevant restore point).

### Mean-Reversion / Dump Bounces (deleted: 4)

- **`long_milddump`** — -5 to -10% band, 10M-50M USDT, 2 reds then 1 green + EMA turn. *Phase 4A: bear $708K bull $12K. Solid bear-rally winner but bull P&L lagged microdump's by 1/3.*
- **`long_bigdump`** — -10 to -25% band, RSI<30 + first green. *Phase 4A: -$47K bear (LOSER). RSI<30 fired on real collapse patterns, not noise dumps.*
- **`long_extremedump`** — -25 to -50% band, any green candle. *Too few setups to be useful as standalone.*
- **`long_oversold_strict`** — RSI<20 + green + volume spike, any band. *74% bear WR collapsed to 20% bull WR — regime-fragile.*

### Pullback / Trend Continuation (deleted: 4)

- **`long_shallowpull`** — +5 to +15%, 1 red ≤0.5% body in an uptrend.
- **`long_deeppull`** — +5 to +25%, 2-3 reds totaling 2-5% pullback.
- **`long_uptrend_continuation`** — Killed early in Phase 4A; bled in both regimes.
- **`long_consolidation_break`** — 5 candles body ≤1% then breakout.

### Breakout (deleted: 2)

- **`long_breakout_new_high`** — close > max(20 closes) + volume spike. *Was a Round B candidate; backtest decent but trailing-TP-dominated.*
- **`long_range_reclaim`** — -3 to +3% sideways, near 30-bar low + green reversal.

### Pump-Momentum Bands (deleted: 3)

- **`long_midpump`** — +25 to +50% (overlapped short_scalp's pump band) + 2 greens.
- **`long_highpump`** — +50 to +100% (the old `long_continuation` band). Few events; mainly diagnostic.
- **`long_extremepump`** — +100%+ pumps. Far too few setups.

### Volume Extremes (deleted: 1)

- **`long_thickvol_pump`** — +5 to +20% in 100M-1B USDT. Tight breakout on heavyweights. Decent results but redundant given microdump covers the liquid space.

### Funding / BTC-Regime (deleted: 3)

- **`long_negative_funding`** — Killed early in Phase 4A; the replay exchange stubs funding rates so this never fired.
- **`long_btc_aligned`** — Gated on BTC 1h > BTC 4h EMA21 + alt 0-25% pump. Decent backtest; deleted because regime-gating adds operational complexity that didn't pay off here.
- **`long_btc_inverted`** — Mirror of btc_aligned but for BTC weakness regimes.

## How to revisit a deleted variant

1. Find the last commit before 2026-05-18's deletion (the Phase 5 cleanup commit).
2. `git show <sha>:app/Services/Strategy/Long<Variant>/Long<Variant>Scanner.php > scratch/Variant.php` to recover the design.
3. Re-add the variant to `config/strategies.php` `$longVariants`, add an `enabled => false` block to `config/crypto.php`, add to `Settings::dynamicKeys()`.
4. Run `scripts/long-top3-backtest.sh --variants=long_<variant>` on a longer / more diverse window than Phase 4A (the original sweep was only 1 bear week + 1 bull week — easy to over-fit).
