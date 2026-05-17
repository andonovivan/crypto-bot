# Long-Strategy Variant Catalog

A reference for the 18 long-side entry-signal variants kept in `app/Services/Strategy/Long*/` (originally 20, 2 deleted post Phase 4A — see the strikethrough sections below). Built during the long-strategy overhaul (2026-05-16 → 17), Phase 4A sweep results captured 2026-05-17.

This file is the durable record of WHY each variant exists, WHAT it tries to do, and HOW it performed in the cross-regime sweep — so that variants not picked for Round B can be revisited later without having to re-derive their design.

## Sweep methodology (Phase 4A)

- **Bear week:** 2025-11-10 → 2025-11-17 (BTC -11.34%)
- **Bull week:** 2026-04-20 → 2026-04-27 (BTC +5.91%)
- **Exit profile (inherited from short_scalp via Settings::ALIASES):** trailing TP enabled (arm +1.5%, trail 1.0%), no fixed TP, 2.5% SL, leverage 10x, position_size 10% of $10k wallet, `--fixed-sizing` (no compounding), `--use-1m` intra-bar probes.
- **Caveat 1:** trailing TP exit profile means wins are unbounded and losses are capped → bear-week R:R values 5-30 are partly trailing-TP catching altcoin vol-rally pumps, not pure entry-signal edge. Bull-week R:R 0.6-1.4 is the more realistic baseline.
- **Caveat 2:** CLAUDE.md notes that `--use-1m` trailing TP captures favorable wicks more aggressively than live (~2× overestimate). Expect live R:R ~50% of these numbers.

Raw CSVs:
- Bear: `storage/perf-snapshots/long-sweep-20260517T015409Z/round-a.csv`
- Bull: `storage/perf-snapshots/long-sweep-20260517T075606Z/round-a.csv`

## Catalog

Each variant has:
- A short ID, file path, signal design.
- Phase-4A sweep stats (bear / bull P&L, WR, R:R).
- Status: **active** (Round B candidate), **shelved** (interesting but not promoted), **kill** (clearly broken or unviable).

### Mean-Reversion / Dump Bounces

These all enter LONG on a 24h dump, betting on mean-reversion. They differ on the dump-band severity and what 15m signal confirms the bounce.

#### `long_microdump` — Active ⭐
- **Path:** [app/Services/Strategy/LongMicrodump/](../app/Services/Strategy/LongMicrodump/LongMicrodumpScanner.php)
- **Signal:** 24h band -2% to -5%, vol 10M-50M, EMA fast crossing up (fast > slow now, fast ≤ slow on prior) + first green candle, body cap.
- **Hypothesis:** Tightest dump band catches the most setups; the EMA-just-crossed gate filters for fresh reversal momentum.
- **Phase 4A:** Bear $2.44M (52.7% WR, R:R 28.96, 309 trades) · Bull $31,858 (73.75% WR, R:R 1.14, 339 trades).
- **Status:** **Top Round B pick.** The clearest all-rounder — strong in both regimes. Bear $2.44M is inflated by trailing TP catching vol-rally pumps, but the bull $31k with 74% WR is the genuine edge.

#### `long_milddump` — Active (alt 7th-slot pick)
- **Path:** [app/Services/Strategy/LongMilddump/](../app/Services/Strategy/LongMilddump/LongMilddumpScanner.php)
- **Signal:** 24h band -5% to -10%, vol 10M-50M, requires 2 reds preceding a green + EMA fast > slow turning.
- **Hypothesis:** Deeper dumps with a clearer reversal pattern (2-reds-then-green) should have higher-quality bounces than microdump's "any green candle".
- **Phase 4A:** Bear $708,045 (22.2% WR, R:R 28.25, 468 trades) · Bull $11,662 (69.4% WR, R:R 0.66, 343 trades).
- **Status:** Marginal — strong bear, modest bull. The 22% bear WR is suspicious (many SLs, but trailing TP catches the few winners). Less consistent than microdump.

#### `long_bigdump` — Shelved
- **Path:** [app/Services/Strategy/LongBigdump/](../app/Services/Strategy/LongBigdump/LongBigdumpScanner.php)
- **Signal:** 24h band -10% to -25%, RSI(14)<30 + first green candle, no HTF gate.
- **Hypothesis:** Genuine oversold (RSI<30) + dump should be a strong bounce candidate.
- **Phase 4A:** Bear $643,046 (37.4% WR, R:R 20.21, 321 trades) · Bull **-$6,193** (51.2% WR, R:R 0.66, 164 trades).
- **Status:** Bear-only. RSI<30 gate is too restrictive in calm bull markets — the variant catches genuine collapses (still falling) rather than tradeable mean-reversion bounces. Revisit if we add a regime-aware RSI threshold.

#### `long_extremedump` — Shelved
- **Path:** [app/Services/Strategy/LongExtremedump/](../app/Services/Strategy/LongExtremedump/LongExtremedumpScanner.php)
- **Signal:** 24h band -25% to -50%, vol 1M-50M, ANY green candle (no EMA, no HTF, no RSI).
- **Hypothesis:** Extreme dumps have so much downside-exhaustion that even a weak bounce signal works.
- **Phase 4A:** Bear $82,877 (50.0% WR, R:R 5.32, 135 trades) · Bull $5,534 (57.3% WR, R:R 1.13, 96 trades).
- **Status:** Modest both ways. Best bear R:R among dump-bounce family but low absolute return. Revisit if we want a "tail-event catcher" alongside microdump.

#### `long_oversold_strict` — Shelved (regime-fragile, interesting)
- **Path:** [app/Services/Strategy/LongOversoldStrict/](../app/Services/Strategy/LongOversoldStrict/LongOversoldStrictScanner.php)
- **Signal:** Any 24h band, RSI(14)<20 + green candle + volume > avg×1.5.
- **Hypothesis:** Pure RSI-based oversold setup, regardless of 24h band.
- **Phase 4A:** Bear $835,723 (**74.4% WR**, R:R 22.52, 20 trades) · Bull **-$5,228** (**20.4% WR**, R:R 0.93, 44 trades).
- **Status:** 🚨 **Strongest regime-fragility observed.** 74% WR collapses to 20% WR between regimes. The variant catches genuine bottoms in extreme bear sell-offs but **false bottoms** in calm bull markets. Revisit if we add a regime detector to gate when it activates.

### Pullback / Trend Continuation

Enter LONG during a pullback in an established uptrend (EMA fast > slow). Differ on how the pullback is detected.

#### `long_shallowpull` — Active
- **Path:** [app/Services/Strategy/LongShallowpull/](../app/Services/Strategy/LongShallowpull/LongShallowpullScanner.php)
- **Signal:** 24h band +5% to +15%, EMA fast > slow + last closed candle is a shallow red ≤ 0.5% body, price still > EMA slow.
- **Hypothesis:** Buy a tiny dip in a confirmed uptrend (the classic "Wyckoff-style buy the breakout retest" idea).
- **Phase 4A:** Bear $14,121 (59.7% WR, R:R 0.81, 699 trades) · Bull $28,739 (65.7% WR, R:R 0.74, 851 trades).
- **Status:** Bull-favored, consistently profitable both ways. Round B candidate.

#### `long_deeppull` — Shelved
- **Path:** [app/Services/Strategy/LongDeeppull/](../app/Services/Strategy/LongDeeppull/LongDeeppullScanner.php)
- **Signal:** 24h band +5% to +25%, EMA fast > slow + 2-3 consecutive reds totaling 2-5% retracement.
- **Hypothesis:** Bigger pullback in a stronger uptrend = better entry.
- **Phase 4A:** Bear $8,459 (60.6% WR, R:R 0.77, 469 trades) · Bull $16,559 (62.3% WR, R:R 0.75, ~500 trades).
- **Status:** Consistent positive both ways but smaller absolute return than shallowpull. Revisit if shallowpull turns out to be regime-fragile.

#### `long_uptrend_continuation` — **DELETED** ❌
- **Path:** ~~app/Services/Strategy/LongUptrendContinuation/~~ (deleted 2026-05-17)
- **Signal:** 24h band +10% to +30%, EMA fast > slow + price returning to (but still above) fast EMA, green candle.
- **Hypothesis:** "Buy near support" — price bouncing off the fast EMA = continuation entry.
- **Phase 4A:** Bear **-$33,584** (43.8% WR, R:R 0.67, 479 trades) · Bull **-$36,075** (50.1% WR, R:R 0.66, ~500 trades).
- **Status:** **Worst variant in BOTH regimes.** The "near fast EMA" proximity check produces too many false signals — every minor pullback triggers, and the trailing TP can't recover the losses. Don't revisit without redesigning the entry trigger. Recreate from scratch with a different entry semantics if you want to revisit; this code was removed.

#### `long_consolidation_break` — Shelved
- **Path:** [app/Services/Strategy/LongConsolidationBreak/](../app/Services/Strategy/LongConsolidationBreak/LongConsolidationBreakScanner.php)
- **Signal:** 24h band +5% to +25%, 5 consecutive candles with body ≤ 1% (accumulation), then a breakout candle closing > the consolidation range high.
- **Hypothesis:** Classic Wyckoff accumulation→breakout — tight range followed by a decisive close above resistance.
- **Phase 4A:** Bear -$1,432 (54.2% WR, R:R 0.82, 509 trades) · Bull $7,105 (60.6% WR, R:R 0.71, ~500 trades).
- **Status:** Marginal — bull-positive, bear-flat. Worth a closer look with different `consolidation_bars` / `consolidation_body_max_pct` tuning.

### Breakout

#### `long_breakout_new_high` — Active
- **Path:** [app/Services/Strategy/LongBreakoutNewHigh/](../app/Services/Strategy/LongBreakoutNewHigh/LongBreakoutNewHighScanner.php)
- **Signal:** 24h band 0 to +25%, close > max(last 20 closes) + volume > avg×1.5 + green candle.
- **Hypothesis:** Classic 20-bar breakout with volume confirmation.
- **Phase 4A:** Bear $31,038 (52.7% WR, R:R 1.13, 1024 trades) · Bull $25,449 (58.4% WR, R:R 0.87, ~1000 trades).
- **Status:** **Most balanced variant.** Modest in both regimes, R:R close to 1, healthy trade count. Round B candidate.

#### `long_range_reclaim` — Shelved
- **Path:** [app/Services/Strategy/LongRangeReclaim/](../app/Services/Strategy/LongRangeReclaim/LongRangeReclaimScanner.php)
- **Signal:** 24h band -3% to +3% (sideways only), price near 30-bar low + green bullish-reversal candle (close > open AND > prior open).
- **Hypothesis:** Catch the bounce off range support in sideways markets.
- **Phase 4A:** Bear **-$10,545** (50.3% WR, R:R 0.74, 443 trades) · Bull $1,837 (52.3% WR, R:R 0.98, ~500 trades).
- **Status:** Bear-loser, bull-marginal. The "sideways" 24h band rarely aligns with the 30-bar low pattern in trending markets. Revisit if we add a "chop detector" to gate when this fires.

### Pump-Momentum Bands

Long-side momentum-continuation variants in increasing pump severity. Each tests whether trailing TP captures continuation moves in a specific 24h band.

#### `long_lowpump` — Active
- **Path:** [app/Services/Strategy/LongLowpump/](../app/Services/Strategy/LongLowpump/LongLowpumpScanner.php)
- **Signal:** 24h band +10% to +25%, vol 5M-25M, EMA fast > slow + green candle, body cap 4%.
- **Hypothesis:** Mid-volume small pumps continue; tight bands keep risk bounded.
- **Phase 4A:** Bear $32,347 (58.0% WR, R:R 0.97, 867 trades) · Bull $53,544 (61.5% WR, R:R 0.88, ~900 trades).
- **Status:** Bull champion's runner-up. Strong in both regimes, high trade count, predictable R:R. Round B candidate.

#### `long_midpump` — Active
- **Path:** [app/Services/Strategy/LongMidpump/](../app/Services/Strategy/LongMidpump/LongMidpumpScanner.php)
- **Signal:** 24h band +25% to +50% (overlaps with short_scalp's pump band — short wins on tie), vol 25M-100M, EMA + 2 consecutive greens.
- **Hypothesis:** Stronger pump + 2-greens confirmation = continuation rather than mean-reversion (which short_scalp catches at this band).
- **Phase 4A:** Bear $7,901 (50.6% WR, R:R **1.34**, 162 trades) · Bull $26,351 (55.5% WR, R:R **1.40**, ~300 trades).
- **Status:** **Best R:R consistency across both regimes (1.34 / 1.40).** That's the cleanest sign of genuine entry-signal edge (not just trailing TP catching pumps). Round B candidate.

#### `long_highpump` — Shelved
- **Path:** [app/Services/Strategy/LongHighpump/](../app/Services/Strategy/LongHighpump/LongHighpumpScanner.php)
- **Signal:** 24h band +50% to +100% (deleted `long_continuation`'s old band), EMA + 2 greens + 1h HTF up.
- **Hypothesis:** Control variant — replicates the original `long_continuation` signal so we can compare against the new variants.
- **Phase 4A:** Bear $2,654 (47.4% WR, R:R 1.67, 38 trades) · Bull $5,922 (53.6% WR, R:R 1.37, ~50 trades).
- **Status:** Low trade count (38-50) confirms +50% pumps are rare events. R:R is fine but volume is too low to be a viable standalone strategy. Validates the deletion of `long_continuation` — its band is just too narrow.

#### `long_extremepump` — Shelved
- **Path:** [app/Services/Strategy/LongExtremepump/](../app/Services/Strategy/LongExtremepump/LongExtremepumpScanner.php)
- **Signal:** 24h band +100%+, no body cap, just EMA up + green.
- **Hypothesis:** Megapump territory — most signals break down, just demand immediate momentum.
- **Phase 4A:** Bear $4,019 (60.0% WR, R:R 3.22, 10 trades) · Bull $7,082 (54.2% WR, R:R 2.92, ~15 trades).
- **Status:** Highest R:R observed (~3) but tiny sample size (10-15 trades). Statistically unreliable. Revisit only as a tail-event catcher combined with other variants.

### Volume Extremes

Pump variants gated by extreme volume bands to test whether the volume profile is the discriminating factor.

#### `long_thinvol_pump` — Active ⭐
- **Path:** [app/Services/Strategy/LongThinvolPump/](../app/Services/Strategy/LongThinvolPump/LongThinvolPumpScanner.php)
- **Signal:** 24h band +10% to +30%, vol **1M-5M** (very thin, meme-coin territory), EMA up + green.
- **Hypothesis:** Small-float coins run hard once they pump; the thin volume signals retail / FOMO entry that continues.
- **Phase 4A:** Bear $35,438 (58.0% WR, R:R 0.99, 888 trades) · Bull **$60,753** (62.2% WR, R:R 0.88, ~900 trades).
- **Status:** **Top Round B pick by bull P&L** ($60k bull). Consistent in both regimes. Round B candidate.

#### `long_thickvol_pump` — Shelved
- **Path:** [app/Services/Strategy/LongThickvolPump/](../app/Services/Strategy/LongThickvolPump/LongThickvolPumpScanner.php)
- **Signal:** 24h band +5% to +20%, vol **100M-1B** (heavyweights), tight 10-bar breakout, body cap.
- **Hypothesis:** Heavyweight coins with strong volume have institutional momentum; tight bands keep risk bounded.
- **Phase 4A:** Bear $33,462 (56.0% WR, R:R 1.02, 987 trades) · Bull $17,310 (60.8% WR, R:R 0.73, ~1000 trades).
- **Status:** Bear-stronger than bull (opposite of thinvol). Heavyweight pumps generate fewer fast bounces. Revisit if we want a "low-volatility large-cap" complement to thinvol_pump.

### Funding / Regime Gates

Variants that use cross-symbol signals (funding rate, BTC regime) as the primary gate.

#### `long_negative_funding` — **DELETED** ❌ (untestable in backtest)
- **Path:** ~~app/Services/Strategy/LongNegativeFunding/~~ (deleted 2026-05-17)
- **Signal:** 24h band -15% to +25%, funding rate < -0.05% (shorts crowded, squeeze setup) + EMA neutral-or-up.
- **Hypothesis:** Negative funding = crowded shorts → expected squeeze when sentiment flips.
- **Phase 4A:** **0 trades both regimes.**
- **Status:** **Untestable.** `HistoricalReplayExchange::getFundingRates()` returns `[]` (funding data not loaded into replay exchange). To revisit: (a) add funding-rate history loading to `HistoricalReplayExchange` first, then re-implement this variant from the signal spec above; or (b) run a live dry-run with real funding rates.

#### `long_btc_aligned` — Active
- **Path:** [app/Services/Strategy/LongBtcAligned/](../app/Services/Strategy/LongBtcAligned/LongBtcAlignedScanner.php)
- **Signal:** 24h band 0 to +25%, BTC 1h close > BTC 4h EMA(21) (bullish regime), EMA fast > slow + green.
- **Hypothesis:** Modest alt pumps + supportive BTC regime is the cleanest go-long setup.
- **Phase 4A:** Bear $16,282 (57.8% WR, R:R 0.98, 434 trades) · Bull **$33,436** (62.3% WR, R:R 0.81, ~500 trades).
- **Status:** **Bull-favored** ($33k bull > $16k bear). Validates that the BTC regime gate works — bull regime delivers 2× the bear return. Round B candidate. (Note: needed 4h BTCUSDT klines added to `HistoricalReplayExchange` for this to work — the kline_history.4h interval must be populated for this variant.)

#### `long_btc_inverted` — Shelved
- **Path:** [app/Services/Strategy/LongBtcInverted/](../app/Services/Strategy/LongBtcInverted/LongBtcInvertedScanner.php)
- **Signal:** 24h band +10% to +30%, BTC 1h close **<** BTC 4h EMA(21) (BTC weakness), EMA + green.
- **Hypothesis:** Alts pumping while BTC is weak = strong relative-momentum signal (decorrelation).
- **Phase 4A:** Bear $15,434 (57.7% WR, R:R 0.91, 567 trades) · Bull $16,428 (60.0% WR, R:R 0.88, ~500 trades).
- **Status:** Surprisingly similar P&L in both regimes. The "BTC weakness" gate fired plenty in bull too (BTC had local pullbacks even in a +5.9% week). Less interesting than btc_aligned because no clear regime preference. Revisit if we add a stricter BTC-weakness threshold.

## Round B Picks (Top 7)

Going forward into the longer-window (Feb-Apr 2026) validation:

1. `long_microdump` — clearest all-rounder
2. `long_thinvol_pump` — bull champion
3. `long_lowpump` — bull runner-up, similar to thinvol but different volume band
4. `long_btc_aligned` — best regime-gated variant
5. `long_breakout_new_high` — most balanced
6. `long_shallowpull` — high-WR pullback
7. `long_midpump` — best R:R consistency

## How to revisit a shelved variant

1. **Confirm it's still registered:** check `config/strategies.php`. All 20 variants are kept in code (Phase 5 cleanup deferred — see CLAUDE.md plan for context).
2. **Enable it for a backtest:**
   ```bash
   ./develop art bot:backtest --strategies=long_<name> \
       --from=YYYY-MM-DD --to=YYYY-MM-DD \
       --use-1m --fixed-sizing --truncate \
       --starting-balance=10000 \
       --override=strategy.long_<name>.enabled=true
   ```
3. **Or sweep multiple variants:** use `scripts/long-variant-sweep.sh --variants=long_a,long_b,...`.
4. **To re-enable in production / live dry-run:** set `strategy.<name>.enabled=true` via the dashboard Settings page or `BotSetting::set()` in tinker.

## Future test ideas

- **Combined-signal variants:** `long_oversold_strict` (74% bear WR) gated by a bull-regime detector to avoid the 20% bull WR collapse.
- **Per-variant exit tuning:** all 20 variants currently inherit short_scalp's trailing TP profile. Test the winner with its own `take_profit_pct` / `stop_loss_pct` / `max_hold_minutes` once promoted.
- **`long_negative_funding`:** add funding-rate history to `HistoricalReplayExchange::loadKlines()` so the variant becomes testable.
- **Hybrid microdump+lowpump:** they catch different bands but both work in both regimes — could a single-strategy with broader band ranges outperform either alone?
- **`long_midpump` with longer hold:** R:R 1.34/1.40 is genuine edge; maybe extending `max_hold_minutes` lets the trailing TP capture more upside.
