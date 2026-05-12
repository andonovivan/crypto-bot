# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) **multi-strategy trading bot** for Binance Futures, built around a plug-in strategy architecture. Two strategies ship today, both registered in `config/strategies.php`:

- **`short_scalp`** (default ON) — shorts pump/dump candidates in the +25% to +50% / ≤-10% 24h band when the 15m chart confirms a downtrend. The historical workhorse; T8 settings (trailing TP arm 1.0% / trail 0.5%, fixed 2.5% SL, 8h hold) backtested ~65-70% win rate at R:R 0.85.
- **`long_continuation`** — longs the +50–100% 24h pump band that `short_scalp` explicitly avoids (research showed pumps ≥50% have a continuation pattern, median +12.6% further over 24h). Currently enabled in dry-run after L1 baseline showed +$1.68/trade edge on March 2026 (29 trades, WR 58.6%, R:R 1.81). The 2-month dual-strategy backtest (Mar-Apr 2026, both strategies on, fixed-sizing $100) confirmed the strategies don't interfere: short_scalp + long_continuation summed to +$2,245 total (short ~78%, long ~22% of P&L); long_continuation kept R:R ≈ 2.34 vs short's 0.84. **Still awaiting full L2-L7 backtest matrix + Sep'25→Apr'26 walk-forward before live deployment** — disable via `Settings::set('strategy.long_continuation.enabled', false)` if regime-shift detector trips.

Both strategies coexist behind one global `max_positions` cap with optional per-strategy sub-caps, share the same wallet, and run in lockstep on the same scanner gate (one scan per closed 1m candle). Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck). Started with `--innodb-buffer-pool-size=2147483648` (2 GiB). The default 128 MB pool's lock table overflows during 1m-mode backtests when `cursor()` streams ~50M kline rows while transactions commit (error 1206 "lock table size"). 2 GiB gives plenty of headroom for current dataset sizes — bump if `kline_history` ever exceeds ~150M rows.
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, configurable scan interval, default 30s)
- **scheduler** — Laravel scheduler (`schedule:run` loop). Currently drives `bot:snapshot-balance` every 5 minutes for the equity-curve widget.
- **ws-worker** — Long-running WebSocket worker (`bot:ws-prices`) that subscribes to Binance `!markPrice@arr` and writes to the `binance:prices` cache key (30s TTL). `BinanceExchange::getPrice()` reads that cache, so this replaces 10s REST polling with sub-second push updates. Also drives **event-driven position management**: on each ws frame it loads open positions and calls `TradingEngine::checkPosition` with the fresh price, giving ~1s TP/SL reaction time in dry-run mode (vs 30s via BotRun's cycle). Has `extra_hosts: fstream.binance.com:18.178.11.87` to bypass ISP DNS hijacking (host `/etc/hosts` propagation only covers `fapi.binance.com`). If the worker dies, cache entries expire in 30s, `getPrice()` falls back to REST, and BotRun's 30s cycle takes over position checks — degraded but still functional.
- **ws-user-data** — Long-running WebSocket worker (`bot:ws-user-data`) for the Binance user-data stream. Creates a listenKey (`POST /fapi/v1/listenKey`), connects to `wss://fstream.binance.com/ws/{listenKey}`, and keeps it alive every 30 min (`PUT /fapi/v1/listenKey`). On `ORDER_TRADE_UPDATE` events with `X=FILLED` whose `o.i` matches a `Position.sl_order_id` or `Position.tp_order_id`, it calls `TradingEngine::reconcileFillFromStream()` to cancel the sibling bracket and record the `Trade` row. This is the **primary close-reconciliation path in live mode** since SL/TP are handled by Binance directly. Same DNS bypass via `extra_hosts`. On disconnect, reconnects with exponential backoff (1→60s) and always requests a fresh listenKey. Dry-run mode returns a fake listenKey so the ws connect fails quietly in a loop — that's benign since dry-run positions close via `checkPosition`'s price-trigger path and never emit real fills.
- App runs migrations; bot/scheduler/ws-worker/ws-user-data wait for app to start first

### Exchange Abstraction
- `ExchangeInterface` — contract for all exchange operations. Covers orders/price/kline reads (`getPrice`, `getKlines`, `getFuturesTickers`, `getOrderBookTop`), orders (`openShort`/`openShortLimit`/`openLong`/close variants, `setStopLoss`, `setTakeProfit`, `openTrailingStop` for native Binance TRAILING_STOP_MARKET), order lifecycle (`getOrderStatus`, `cancelOrder`, `cancelOrders`, `cancelAlgoOrder`, `getAlgoOrderStatus`), account state (`getAccountData`, `getBalance`, `getOpenPositions`, `getCommissionRate`, `getFundingRates`, `getMaxLeverage`, `getUserTrades`), and the user-data listenKey lifecycle (`createListenKey`, `keepAliveListenKey`, `closeListenKey`).
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB. **Models four sources of real-money execution friction** (added 2026-05) so paper-trading PnL forecasts what live would actually realize:
  1. **Market-order slippage** — `openShort` / `closeShort` / `openLong` / `closeLong` apply an adverse `dry_run_market_slippage_bps` (default 3 bps) via a private `applyMarketSlippage()` helper. SELL fills below mark, BUY fills above. Affects entries that take MARKET (including post-only MARKET fallbacks) plus every close path — SL trigger, TP trigger, trailing stop fire, expiry, manual close — since `closePosition` always routes through `closeShort`/`closeLong`.
  2. **MIN_NOTIONAL rejection** — `calculateQuantity()` reads `minNotional` from the wrapped `BinanceExchange`'s cached `getExchangeInfo()` and returns 0 when `quantity × price < minNotional`. `TradingEngine` already records a `Failed` position when quantity is zero, so this surfaces in the dashboard exactly like a live `-4164` order rejection.
  3. **Post-only fill rate** — `openShortLimit()` rolls `dry_run_maker_fill_rate` (default 0.6 = 60%) after the existing "would-cross" guard. On the 40% miss the order returns `status='NEW'` with `executedQty=0`, the bot's `placeEntryOrder` polling loop sits on it until `limit_order_timeout_seconds` elapses, then cancels and falls back to MARKET (which takes slippage). Approximates the empirical fill rate of tight post-only limits on Binance Futures. Only applies to SHORT entries — LONG entries always go MARKET (no `openLongLimit` exists in the interface).
  4. **Bracket placement failure** — `setStopLoss` / `setTakeProfit` / `openTrailingStop` each call `maybeFailBracket()` which throws `RuntimeException` with probability `dry_run_bracket_fail_rate` (default 0.01). `TradingEngine::placeBrackets` catches the throw, retries the failed bracket once after a 1s sleep, and on persistent failure triggers the UNPROTECTED fail-safe (cancels sibling brackets, closes the position via `closeShort`/`closeLong` which pays slippage). **Effective post-retry failure rate per entry ≈ 2 × rate² ≈ 0.02% at default 0.01** — bump to 0.05+ if you want to exercise the fail-safe path more often during testing.
- These knobs only affect dry-run; `BinanceExchange` is unchanged.
- `HistoricalReplayExchange` — offline backtest exchange. Loads `kline_history` rows into memory, services `getPrice` / `getKlines` / `getFuturesTickers` against a movable clock (`setClock($unixMs)`), synthesizes order-book top from the current price (±1bp), and stubs out order placement / `getOrderStatus` / listenKey as no-ops. Uses the same DB `Position`/`Trade` rows (with `is_dry_run=true`) so account balance and open-position tracking match DryRunExchange. **Only used during `bot:backtest`** — bound into the container via `app()->instance(ExchangeInterface::class, $replay)`. See the Backtest Harness section.
- `ExchangeDispatcher` — implements `ExchangeInterface` and is the binding for `ExchangeInterface::class` in the container. On every call it reads `Settings::get('dry_run')` and delegates to either the live or dry instance. This makes the dashboard `dry_run` toggle effective at the next bot cycle without a container restart.
- **CAUTION**: flipping `dry_run` while positions are open will route their closes to the newly-active exchange (which won't know about them). **Close all open positions before toggling.**
- **Account data**: `getAccountData()` returns wallet balance, available balance, unrealized profit, margin balance, position margin, maintenance margin
  - BinanceExchange: calls `/fapi/v2/account` (cached 10s)
  - DryRunExchange: calculates from DB — margin = `position_size_usdt / leverage` (not full notional). Wallet balance includes realized P&L + closed funding + open funding.
- **Commission rates**: `getCommissionRate($symbol)` returns maker/taker rates (cached 1h per symbol in live mode; uses `dry_run_fee_rate` in dry-run)
- **Funding rates**: `getFundingRates($symbol)` returns current funding rate, next funding time, mark price per symbol (cached 60s)
- **Futures tickers**: `getFuturesTickers()` returns 24h stats for all USDT perpetuals in one call — the key API used by the scanner (1 API weight)

### Core Flow (multi-strategy)
1. **BotRun** — continuous loop, default 30s interval. Constructor-injects `StrategyRegistry`; iterates registered+enabled strategies in the order declared by `config/strategies.php['order']`.
2. **Per cycle**:
   - Settle funding fees at 8h UTC boundaries via `FundingSettlementService`
   - **Manage open positions** (strategy-agnostic): for each open position regardless of strategy, fetch latest price, call `checkPosition()` to update unrealized P&L and check TP/SL/expiry. `checkPosition()` behavior differs by mode — see "SL/TP close reconciliation" below.
   - **Safety reconcile** (live only): call `getOpenPositions()` once and for any DB `Position::open()` missing from the exchange list, invoke `TradingEngine::reconcileMissingPosition()` which probes bracket order status via `getOrderStatus` and records the close. The reverse check also fires: any Binance-side position with no matching DB row is logged as a warning (throttled to once per 15 min per symbol). These untracked positions are **not adopted** — the bot has no way to know the intended SL/TP — so the operator is expected to investigate.
   - **Scanner gate**: `runCycle` returns before invoking the entry loop unless a new closed 1m candle has appeared since the last scan (tracked via `BotRun::$lastScannedCandleOpenTime`). Position management + safety reconcile above always run; only the entry-side scanner is gated. This makes live entry cadence match `bot:backtest --use-1m` (1 scan per closed 1m bar) regardless of `scan_interval`. With the default 30s loop, the scan fires within ~30s of each minute boundary; tighten `scan_interval` to reduce that lag.
   - **Entry loop** (per enabled strategy, in declared order):
     - `$strategy->getCandidates()` returns its own `Candidate[]` (one ticker call per strategy via direction-specific filters)
     - foreach candidate: apply cross-strategy invariant (one open position per symbol globally), per-`(symbol, strategy_key)` cooldown + failed-entry cooldown, per-strategy `max_positions` sub-cap if set, then `$strategy->analyze($symbol)` and `$strategy->buildSignal($candidate, $analysis)` if `analysis->ok`. Open via `$engine->open($signal)`.
     - **Cross-cycle dedupe**: a symbol opened by strategy A this cycle is skipped by strategy B in the same cycle (first-in-`order` wins). The bands of the two shipped strategies don't overlap, so this rarely fires.
3. **Cycle summary** logged per strategy + total: `strategy=short_scalp candidates=4 analyzed=2 opened=0` then `Cycle: total candidates=4 analyzed=2 opened=0 openPositions=2`.

**Boundary-aligned wake-up**: BotRun's sleep math targets `ceil(now / scan_interval) * scan_interval` so cycles wake at predictable boundaries — e.g. with `scan_interval=30`, the loop fires at `:00` and `:30` of each minute (epoch-aligned). Combined with the 1m candle gate above, the scanner block runs within milliseconds of each `:00` boundary instead of drifting with cycle duration. This makes live entry timing deterministic and matches the backtest's exact-boundary tick.
4. **Idempotency**: `TradingEngine::checkPosition`, `closePosition`, `reconcileFillFromStream`, and `reconcileMissingPosition` all refresh the row and bail if `status !== Open`. Multiple paths (ws-prices ticks, ws-user-data fills, BotRun cycle, manual dashboard actions) can race without double-counting.

### Plug-in Strategy Architecture

All strategies implement [`StrategyInterface`](app/Services/Strategy/StrategyInterface.php) (`key()`, `label()`, `side()`, `isEnabled()`, `getCandidates()`, `analyze()`, `buildSignal()`). Concrete classes live under `app/Services/Strategy/<Key>/` and are registered in [`config/strategies.php`](config/strategies.php) — both the `classes` map (key → class) and the `order` array (priority for symbol-collision dedupe). The [`StrategyServiceProvider`](app/Providers/StrategyServiceProvider.php) builds a `StrategyRegistry` singleton at boot.

**Generic DTOs** under `app/Services/Strategy/`:
- `Candidate {symbol, price, priceChangePct, volume, reason}`
- `Analysis {ok, blockedReason, atr, fields[]}` — `fields` is a free-form keyed array; the dashboard renders side-aware pills based on `lastCandleRed`/`lastCandleGreen` etc.
- `Signal {symbol, side, priceChangePct, reason, atr, strategyKey, meta}`

**TradingEngine entry**: `open(Signal): ?Position` is the canonical entry point. Routes by `signal->side` to private `openShortInternal` / `openLongInternal`. Persists `strategy_key` on every `Position::create` and every `Trade::create` site (`closePosition`, `finalizeClose`, `takePartialTp`, `recordFailsafeCloseAsTrade`, `recordFailedEntry`). Honors optional per-strategy `max_positions` sub-cap (`strategy.<key>.max_positions`) on top of the global cap.

`openShort(ShortSignal)` survives as a thin shim (`return $this->open(Signal::fromShortSignal($s))`) — deprecated, scheduled for removal once any remaining external callers migrate.

**Cross-strategy invariants**:
- One open position per symbol globally — enforced by `Position::open()->where('symbol', X)->exists()` check in both BotRun and TradingEngine.
- Cooldowns scope per `(symbol, strategy_key)` — strategy A closing a symbol does not block strategy B from re-entering that symbol immediately (subject to its own cooldown).
- `max_positions` is a global cap; per-strategy sub-caps are additive (null = no sub-cap).
- The two shipped strategies have non-overlapping 24h bands by design (short: pump 25–50% or dump ≤-10%; long: pump >50–100%), so they never compete for the same symbol within a single scan.

### SL/TP close reconciliation (mode-dependent)

- **Dry-run mode**: `checkPosition()` compares live price to `stop_loss_price` / `take_profit_price` and triggers `closePosition()` directly. No bracket orders exist on a real exchange, so there is nothing to reconcile.
- **Live mode**: `checkPosition()` does **not** price-check SL/TP. Binance owns the close via the `STOP_MARKET` SL bracket plus either a fixed `TAKE_PROFIT_MARKET` or a native `TRAILING_STOP_MARKET` (when `trailing_tp_enabled=true`) — all `reduceOnly=true`. The bot reconciles via three paths, in priority order:
  1. **ws-user-data** (primary, ~real-time): `ORDER_TRADE_UPDATE` with `X=FILLED` → `reconcileFillFromStream()` cancels the sibling bracket and writes the `Trade` row using the fill's `ap`/`L`/`z` fields.
  2. **BotRun safety reconcile** (30s fallback): if a DB position is flat on Binance but still `Open` locally (ws missed the event), `reconcileMissingPosition()` queries `getOrderStatus` on both brackets and reconciles.
  3. **Manual close idempotency**: if a user-initiated close races with a Binance SL/TP fill, `closePosition()` catches Binance error codes `-2022` / `-4046` ("already flat") and delegates to `reconcileFromBrackets()` so the Trade row still reflects the true SL/TP exit rather than a spurious Manual close.
- **Expiry** (`max_hold_minutes`) is bot-driven in both modes — Binance has no time-based close order.

### Short-Scalp Strategy

**Entry criteria (all must pass):**
1. **24h mover**: `pump_threshold_pct <= priceChangePct <= pump_max_pct` (default +25% to +50%, `pump_max_pct=0` disables the upper cap) OR `priceChangePct <= -dump_threshold_pct` (default -10%). The pump upper cap exists because research showed pumps ≥50% have a continuation pattern (median 24h close +12.6%), not the mean-reversion edge that mild pumps offer.
2. **Liquidity window**: `min_volume_usdt <= 24h quote volume <= max_volume_usdt` (defaults 10M / 25M USDT, `max_volume_usdt=0` disables the upper cap). Mid-volume coins mean-revert more reliably than super-thin (small float keeps running) or super-thick (high-liquidity continuation moves) ones.
3. **15m downtrend (Strict rule)** — only when `strict_downtrend_enabled=true` (default true). When false, this entire block is skipped and only the funding-rate guard below applies. Dropping the strict gate is the wide-SL trailing strategy's entry mode (research showed the strict confirmation delays entry past the easy reversion).
   - EMA fast (default 9) < EMA slow (default 21) on BOTH current and prior 15m candle
   - Current price (close of last closed candle) < EMA fast
   - **Last N CLOSED 15m candles are red** (close < open) — where N = `min_red_candles` (default 2). Checks `candles[last-1]` and `candles[last-2]`, not the in-progress candle. Filters dead-cat-bounce setups.
   - Candle body of last closed candle <= `max_candle_body_pct` (default 3%) — skips frenzied volatility
4. **Higher-timeframe confirmation** (conditional, `htf_filter_enabled=true` by default, only checked when strict gate is on): 1h close < 1h EMA (period `htf_ema_period`, default 21). Fetches `htf_ema_period + 5` 1h klines via `checkHigherTfDowntrend()`. **Fails open** — if the 1h fetch errors or returns too few bars, the filter passes. Rationale: a 15m setup that looks weak on a coin still in a 1h uptrend tends to be a bounce-top that gets bought back into.
5. **Funding guard**: current funding rate >= -0.05% (avoid paying heavy funding against us). Always checked, regardless of `strict_downtrend_enabled`.
6. **Capacity gates**: trading not paused, global `max_positions` not hit, no open position on this symbol, post-close cooldown cleared (`cooldown_minutes`), post-failure cooldown cleared (`failed_entry_cooldown_minutes`, default 360 = 6h — prevents re-probing a symbol whose last entry was rejected by Binance)
7. **Circuit breaker** (conditional, `circuit_breaker_enabled=false` by default): if tripped, block new entries until cooldown expires. See the Risk Controls section below.

**Exit criteria:**
- **Fixed TP** (when `trailing_tp_enabled=false`, default): `entry × (1 - take_profit_pct / 100)` — for SHORT, TP is BELOW entry (default 2% below). Placed at entry as a Binance `TAKE_PROFIT_MARKET` algo bracket.
- **Trailing TP** (when `trailing_tp_enabled=true`): replaces the fixed TP with a Binance native `TRAILING_STOP_MARKET` algo order. `activationPrice = entry × (1 ± trailing_tp_arm_pct / 100)`; `callbackRate = trailing_tp_trail_pct` (clamped to Binance's 0.1–5.0% range). For SHORT closes, Binance arms the trail when the lowest price reaches activationPrice and fires when latest price ≥ lowest × (1 + callbackRate/100). The order ID is stored in `Position.tp_order_id` so cancellation and reconciliation paths treat it identically to a fixed TP. **Dry-run mirror**: `TradingEngine::maybeTrailStop()` re-implements the same trigger logic against the cached price stream — arms the trail when favorable >= `trailing_tp_arm_pct`, ratchets `Position.stop_loss_price` toward `extreme ± trailing_tp_trail_pct`, and clears `take_profit_price=0`. The slHit branch then fires on the trailing stop, with `trailingExitReason()` labelling it `TakeProfit` when the trailed stop sits on the favorable side of entry.
- SL hit: `entry × (1 + stop_loss_pct / 100)` — for SHORT, SL is ABOVE entry (default 1% above). When `atr_sl_enabled=true` (default), SL distance uses `atr_sl_multiplier × ATR14` instead of a fixed pct, clamped to `(entry / leverage) × 0.70` so the stop can't sit past liquidation. TP stays pct-based regardless. SL stays in place when trailing TP is on — the trailing stop only ratchets in the favorable direction; the fixed SL anchors the worst-case loss.
- **Partial take-profit** (conditional, `partial_tp_trigger_pct > 0`, default 1%): once the position is favorable by `partial_tp_trigger_pct`, `TradingEngine::maybeTakePartialTp()` closes `partial_tp_size_pct` (default 50%) at MARKET, sets `Position.partial_tp_taken=true` so it can't re-fire, and leaves the remainder under the existing SL/TP brackets (`reduceOnly=true` auto-scales to remaining qty). Runs on every `checkPosition()` tick. Disable when running the trailing-TP variant — the two compete for the favorable-side exit.
- Expiry: `max_hold_minutes` (default 120 = 2h)

**Risk sizing at 25x leverage (default):**
- Margin per trade = `position_size_pct` (default 10%) × wallet balance
- Notional = margin × leverage = 250% of wallet per trade
- SL 1% adverse = 25% margin loss = 2.5% wallet loss per bad trade
- TP 2% favorable = 50% margin gain = 5% wallet gain per good trade
- Practical concurrent cap: ~9-10 positions at 10% margin each exhausts available balance; `max_positions=10` is effectively the ceiling
- The engine's `availableBalance >= margin` guard prevents over-commit

**Cooldown:** `cooldown_minutes` (default 120 = 2h) after any close on same symbol. `failed_entry_cooldown_minutes` (default 0 = disabled) after any `status=Failed` row on same `(symbol, short_scalp)`.

### Long-Continuation Strategy

The complement to short-scalp: longs the +50–100% 24h pump band that short_scalp explicitly avoids. Research showed pumps in this band have a continuation pattern (median 24h close +12.6% further), so this strategy rides the move instead of fighting it. Off by default (`strategy.long_continuation.enabled=false`) until full L2-L7 + walk-forward validation.

**Entry criteria (all must pass):**
1. **24h mover (strict-greater on lower bound)**: `priceChangePct > pump_threshold_pct` (default +50%) AND `priceChangePct <= pump_max_pct` (default +100%, `pump_max_pct=0` disables the upper cap). The strict-greater on the lower bound means the +50% boundary belongs to short_scalp (`pump_max_pct=50` inclusive there) — bands therefore never overlap by construction.
2. **Liquidity window**: `min_volume_usdt <= 24h quote volume <= max_volume_usdt` (defaults 5M / 100M USDT, `max_volume_usdt=0` disables the upper cap). Wider than short's 10M-25M because pump-continuation coins tend to be heavier.
3. **15m uptrend (Strict rule)** — only when `strict_uptrend_enabled=true` (default true). When false, this entire block is skipped and only the funding-rate guard below applies.
   - EMA fast (default 9) > EMA slow (default 21) on BOTH current and prior 15m candle
   - Current price (close of last closed candle) > EMA fast
   - **Last N CLOSED 15m candles are green** (close > open) — where N = `min_green_candles` (default 2). Checks `candles[last-1]` and `candles[last-2]`. Mirror of short's red-candle gate.
   - Candle body of last closed candle <= `max_candle_body_pct` (default 5% — wider than short's 3% because pump-continuation candles are inherently fat)
4. **Higher-timeframe confirmation** (conditional, `htf_filter_enabled=true` by default, only checked when strict gate is on): 1h close > 1h EMA (period `htf_ema_period`, default 21). Fetches `htf_ema_period + 5` 1h klines via `checkHigherTfUptrend()`. **Fails open** — if the 1h fetch errors or returns too few bars, the filter passes.
5. **Funding guard (INVERTED vs short)**: current funding rate `<= funding_max_rate` (default +0.10% per 8h). LONGs *pay* funding when positive — skipping rates above the cap avoids joining a crowded squeeze (positive funding means longs are paying shorts; very high rates indicate over-crowded long side).
6. **Capacity gates**: trading not paused, global `max_positions` not hit, per-strategy `max_positions` sub-cap not hit (default `strategy.long_continuation.max_positions=3` — pump events are rare; >3 simultaneous setups is likely noise), no open position on this symbol globally (cross-strategy invariant), post-close cooldown cleared (`cooldown_minutes`, default 240 = 4h), post-failure cooldown cleared (`failed_entry_cooldown_minutes`, default 0 = disabled).
7. **Circuit breaker**: same shared breaker as short_scalp.

**Exit criteria:**
- **Fixed TP** (when `trailing_tp_enabled=false`): `entry × (1 + take_profit_pct / 100)` — for LONG, TP is ABOVE entry (default 3% above). Placed at entry as a `TAKE_PROFIT_MARKET` algo bracket.
- **Trailing TP** (when `trailing_tp_enabled=true`, **default**): native `TRAILING_STOP_MARKET` algo. `activationPrice = entry × (1 + trailing_tp_arm_pct / 100)` (default arm 1.5% favorable — tighter than short's 2.0% because continuations exhaust faster); `callbackRate = trailing_tp_trail_pct` (default 1.0% — wider than short's 0.7% because pump-noise has more give). Binance arms when highest price reaches activationPrice and fires when latest ≤ highest × (1 - callbackRate/100). Mirror of short's logic with the side flipped.
- SL hit: `entry × (1 - stop_loss_pct / 100)` — for LONG, SL is BELOW entry (default 3% below). `atr_sl_enabled=false` by default for v1 — deterministic for backtesting; flip true to use `atr_sl_multiplier × ATR14` instead. SL stays in place when trailing TP is on.
- **Partial TP**: disabled by default (`partial_tp_trigger_pct=0`) — competes with trailing TP for the favorable-side exit. Leave at 0 unless you've explicitly disabled trailing.
- Expiry: `max_hold_minutes` (default 720 = 12h — half of short's recommended 1440, since the continuation tail mostly lands in the first 12h after signal).

**Risk sizing (current dry-run config: 10x leverage, $1000 wallet):**
- Margin per trade = `position_size_pct` (default 10%) × wallet balance = $100
- Notional = margin × leverage = $1000 per trade (100% wallet)
- SL 3% adverse = 30% margin loss = 3% wallet loss per bad trade
- Backtest-validated edge (L1 baseline, March 2026 fixed-sizing $100): 29 trades, WR 58.6%, R:R 1.81, +$48.82 net (per-trade edge $1.68 — about 3× short's $0.65/trade on the same window). April 2026 was pumpier: 122 trades, WR 58.2%, R:R 2.34, +$299.

**Cooldown:** `cooldown_minutes` (default 240 = 4h) after any close on same `(symbol, long_continuation)`. Longer than short's 120m because pump events are once-per-pump — re-entering the same symbol within 4h of a previous close means the second pump is unrelated.

**Why this strategy exists** (research justification): the original short-scalp design discovered that pumps ≥50% had a *continuation* pattern (median +12.6% further over the next 24h, p25 of -1.77% drawdown), unlike mild pumps which mean-revert (-10% trough). Short avoids this band via `pump_max_pct=50`; long_continuation rides it. The two strategies are complementary by construction — they share the same wallet and `max_positions` ceiling but never compete for the same symbol within a single scan.

### Risk Controls

**Drawdown circuit breaker** (`circuit_breaker_enabled`, default `false`):

Evaluated at every `TradingEngine::open()` call (both SHORT and LONG paths) — gates entries only, already-open positions continue to be managed normally.

- **Equity** = `walletBalance + sum(unrealized_pnl on open positions)`.
- **Peak** = rolling high-water mark, persisted in cache key `circuit_breaker:equity_peak`. Updated on every check; anchored to the trough when the breaker trips.
- **Drawdown** = `(peak - equity) / peak × 100`.
- **Trip condition**: `drawdown >= circuit_breaker_drawdown_pct` (default 25%) → write `circuit_breaker:cooldown_until = now + circuit_breaker_cooldown_hours` (default 24h) and block new entries.
- **Short-circuit**: while `cooldown_until > now()`, both `openShortInternal()` and `openLongInternal()` return early.
- **Clock reset**: cache key `circuit_breaker:measurement_start` is a v1 remnant — set by older code paths but not consulted by the current detector; safe to ignore but not yet removed.
- `circuit_breaker_window_hours` exists in `Settings::KEYS` for backwards compat but is **not read** by the v2 detector. Only the drawdown-and-cooldown pair matters.
- `bot:backtest --truncate` clears all three cache keys so the backtest starts with a clean risk-control slate.

### Scanners

Each strategy ships its own scanner under `app/Services/Strategy/<Key>/`. They share a common contract via the strategy class (`getCandidates()` / `analyze()`) but their internal helpers are direction-specific.

**`ShortScanner`** ([app/Services/ShortScanner.php](app/Services/ShortScanner.php), wrapped by `ShortScalpStrategy`):
- One-shot ticker scan: `getCandidates()` calls `getFuturesTickers()` once per cycle, filters by pump/dump thresholds (with optional `pump_max_pct` upper cap) + volume window, returns `ShortCandidate[]` sorted by absolute 24h change.
- Per-candidate klines: `analyze15m(symbol)` fetches 30 15m candles (with 15s in-memory cache), returns `ShortAnalysis` with EMA fast/slow, candle state (red), funding rate, ATR14, 1h HTF confirmation, `downtrendOk` flag.
- HTF confirmation: when `strict_downtrend_enabled=true` AND `htf_filter_enabled=true`, requires 1h close < 1h EMA.
- The `ShortScalpStrategy` adapter maps these legacy DTOs to the generic `Candidate` / `Analysis` / `Signal` types.

**`LongContinuationScanner`** ([app/Services/Strategy/LongContinuation/LongContinuationScanner.php](app/Services/Strategy/LongContinuation/LongContinuationScanner.php), used directly by `LongContinuationStrategy`):
- Mirror of `ShortScanner` for the +50–100% pump band (boundary-strict on the lower bound so the +50 boundary belongs to short).
- Strict 15m uptrend gate (when enabled): EMA fast > slow on current+prior, last N green candles, body cap, price > EMA fast.
- HTF: 1h close > 1h EMA.
- Funding guard is INVERTED vs short: longs PAY funding when positive, so this scanner skips rates ABOVE `funding_max_rate` (default +0.10%) — opposite of short's "skip rates too negative".

Both scanners share:
- **Kline cache**: 15s TTL in-memory cache, keyed on `Carbon::now()->getTimestamp()` (sim-time aware) so backtests are deterministic. Without this, two identical backtest runs could serve different klines for the same sim-tick.
- **TechnicalAnalysis**: `calculateEMA`, `calculateATR(klines, 14)`, etc. — completely direction-agnostic.

### Position Management (both sides)
- **Entry**: `TradingEngine::open(Signal): ?Position` — routes to `openShortInternal` or `openLongInternal` based on `signal->side`. Both internals share `placeEntryOrder()`, `placeBrackets()`, `calculateSlTp()` — all already side-parameterized.
- **Bracket geometry**:
  - SHORT: SL above entry (`entry × (1 + sl_pct/100)`), TP below entry; trailing-TP arms below entry (favorable).
  - LONG: SL below entry (`entry × (1 - sl_pct/100)`), TP above entry; trailing-TP arms above entry (favorable).
- **Entry flow (`placeEntryOrder`)**: if `use_post_only_entry` is true, attempt a LIMIT (SELL for SHORT, BUY for LONG) at best ask/bid with `timeInForce=GTX` (post-only, maker-only). Poll every 500ms for `limit_order_timeout_seconds` (default 3s). On full fill → `entry_type=LIMIT_MAKER`. On partial fill + timeout → cancel, MARKET the remainder → `entry_type=MIXED`. On timeout/no-fill or post-only rejection → MARKET full qty → `entry_type=MARKET_FALLBACK`. If `use_post_only_entry=false`, straight MARKET.
- **Maker fee discount**: When `entry_type=LIMIT_MAKER`, entry-side fees use the maker rate instead of taker.
- **One position per symbol (invariant)**: enforced via `Position::open()->where('symbol', X)->exists()` — applies globally across strategies.
- **Per-strategy `max_positions` sub-cap**: optional. `Settings::get('strategy.<key>.max_positions')` non-null → counted against `Position::open()->where('strategy_key', $key)->count()`.
- **SL/TP order tracking**: Each position stores `sl_order_id` and `tp_order_id`. On close, only that position's orders are cancelled.
- **SL/TP fail-safe**: If SL or TP order placement fails after opening, the position is immediately closed and any partial orders cancelled. No unprotected positions.
- **Margin check**: Before opening, verifies `availableBalance >= margin`.
- **P&L**: `(entry - current) × qty` for SHORT, `(current - entry) × qty` for LONG.
- **Fees**: Deducted on close — entry fee + exit fee. Exit is always taker (MARKET close). Entry is maker or taker depending on `entry_type`. Stored in `Trade.fees`.
- **Funding fees**: Accumulated per position via `FundingSettlementService` at 8h boundaries. Snapshotted into `Trade.funding_fee` on close.
- **Strategy attribution**: Position+Trade rows carry `strategy_key`. Dashboard `by_strategy` stats and per-strategy cooldown queries use this column.
- **Manual actions** (dashboard): `addToPosition` averages entry with new margin; `reversePosition` closes one side and opens the opposite (the new position's `strategy_key` is preserved).

### Funding Rate Tracking
- **Mechanism**: Binance perpetual futures settle funding every 8h (00:00, 08:00, 16:00 UTC). Positive rate = longs pay shorts; negative = shorts pay longs.
- **FundingSettlementService**: Called each bot tick. Detects 8h boundary crossing, calculates `notional × rate` per open position, updates `Position.funding_fee`.
- **Sign convention**: LONG + positive rate = pays (negative); SHORT + positive rate = receives (positive)
- **DryRun simulation**: Uses real funding rates from `/fapi/v1/premiumIndex` to simulate settlements
- **P&L integration**: Open position net P&L = unrealized - estimated fees + funding. Closed trade snapshots funding into `Trade.funding_fee`.

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/Strategy/StrategyInterface.php` | Plug-in contract: `key()`, `label()`, `side()`, `isEnabled()`, `getCandidates()`, `analyze()`, `buildSignal()`. |
| `app/Services/Strategy/StrategyRegistry.php` | Container singleton holding registered strategies; `enabled()`, `inOrder()`, `find($key)`. |
| `app/Services/Strategy/AbstractStrategy.php` | Optional base providing `isEnabled()` (reads `strategy.<key>.enabled`) and `setting($k)` namespace helper. |
| `app/Services/Strategy/{Signal,Candidate,Analysis}.php` | Generic DTOs replacing the legacy `Short*` types (which still exist as adapters). |
| `app/Services/Strategy/ShortScalp/ShortScalpStrategy.php` | Adapter wrapping `ShortScanner` behind `StrategyInterface`. Maps DTOs. |
| `app/Services/Strategy/LongContinuation/LongContinuationStrategy.php` | Long-continuation strategy implementation. |
| `app/Services/Strategy/LongContinuation/LongContinuationScanner.php` | Scanner for the +50–100% pump band: green-candle gate, EMA fast > slow, 1h HTF up, inverted funding guard. |
| `app/Providers/StrategyServiceProvider.php` | Builds `StrategyRegistry` from `config/strategies.php` at boot. |
| `config/strategies.php` | `classes` map (key → class), `order` (priority for symbol-collision dedupe), `enabled` defaults. |
| `app/Services/ShortScanner.php` | Original short-scalp scanner (still used; wrapped by `ShortScalpStrategy`). |
| `app/Services/ShortCandidate.php` / `ShortAnalysis.php` / `ShortSignal.php` | Legacy DTOs — kept for backwards compat through Phase 4 cleanup. |
| `app/Services/TechnicalAnalysis.php` | Direction-agnostic indicators: EMA, ATR14, RSI, MACD. |
| `app/Services/TradingEngine.php` | Canonical entry: `open(Signal)` routes by side to `openShortInternal` / `openLongInternal`. Persists `strategy_key` on every Position+Trade row. Honors per-strategy `max_positions` sub-cap. `openShort(ShortSignal)` shim is `@deprecated`. Shared helpers: `placeEntryOrder`, `placeBrackets`, `calculateSlTp` (all side-aware); `maybeTakePartialTp`, `maybeTrailStop`, `trailingExitReason`; reconciliation: `reconcileFillFromStream`, `reconcileMissingPosition`, `reconcileFromBrackets`, `finalizeClose`. |
| `app/Services/Settings.php` | DB-first settings with config fallback. `override($key, $value)` / `clearOverrides()` shadow the DB for this PHP process only — used by `bot:backtest` to flip `dry_run=true` and apply per-run tuning without stomping on live shared state. |
| `app/Services/FundingSettlementService.php` | 8h funding rate settlement for open positions |
| `app/Services/Exchange/ExchangeInterface.php` | Contract for all exchange operations |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation |
| `app/Services/Exchange/HistoricalReplayExchange.php` | Offline replay exchange for `bot:backtest` — serves `kline_history` rows against a movable clock, stubs out order APIs, writes DB rows with `is_dry_run=true`. Supports fixed-sizing mode (`setFixedSizing`) and symbol-scoped probe prices (`setProbePrice`/`clearProbePrice`) for intra-bar SL/TP simulation. |
| `app/Services/Exchange/ExchangeDispatcher.php` | Runtime router: reads `Settings::get('dry_run')` on each call, delegates to live or dry |
| `app/Http/Controllers/DashboardController.php` | All API endpoints. `stats()` returns combined-account fields + a `by_strategy` array (per-strategy P&L, win rate, exposure). `scanNow()` / `scannerData()` iterate all enabled strategies and emit a flat list of candidates each tagged with `strategy_key`/`side` (optional `?strategy=KEY` filter — force-shows even disabled strategies for preview). `openPosition()` accepts a `strategy_key` body field; defaults to the first enabled strategy. `data()` powers positions + topbar; `settings()` returns grouped metadata via `Settings::groups()`. |
| `resources/views/dashboard.blade.php` | Thin shell — extends `layouts/dashboard` and `@include`s the page partial named by the route's `defaults('page', ...)` |
| `resources/views/layouts/dashboard.blade.php` | Sidebar + topbar shell, loads `@vite(...)` |
| `resources/views/pages/{overview,positions,scanner,history,failed,risk,settings}.blade.php` | Per-route page partials |
| `resources/views/components/{card,chart-card,kpi-tile,pagination,range-pills,sidebar,table-shell,topbar}.blade.php` | Reusable Blade components |
| `resources/js/dashboard/` | Per-page scheduler (`polling.js`), API client, formatters, toast bus, table renderers, dynamically-imported chart factories (`charts/equity.js`, `charts/aggregates.js`, `charts/theme.js` — ECharts is code-split into its own chunk) |
| `config/settings_meta.php` | Settings UI metadata: 12 functional groups (Generic Trading, 7 Short-Scalp groups, 3 Long-Continuation groups, Risk Controls), per-key descriptions, numeric `min/max/step` constraints. All strategy keys are namespaced as `strategy.<key>.*`. |
| `routes/web.php` | Route definitions — 7 dashboard page routes (all served by `index()`, distinguished via `defaults('page', ...)`) plus the JSON API |
| `app/Console/Commands/BotRun.php` | Continuous scan+monitor loop with graceful shutdown |
| `app/Console/Commands/BotWsPrices.php` | Long-running WebSocket worker for `!markPrice@arr` → `binance:prices` cache |
| `app/Console/Commands/BotWsUserData.php` | Long-running WebSocket worker for Binance user-data stream — reconciles SL/TP fills via `ORDER_TRADE_UPDATE` events |
| `app/Console/Commands/BotSnapshotBalance.php` | One-shot command that writes a `BalanceSnapshot` row from `getAccountData()`. Scheduled every 5 min for the equity-curve widget. |
| `app/Console/Commands/BotStatus.php` | CLI status overview |
| `app/Console/Commands/BotBacktest.php` | Replays `kline_history` rows through the live multi-strategy stack (`StrategyRegistry` → each strategy's scanner → `TradingEngine::open()`) using `Carbon::setTestNow` + `HistoricalReplayExchange`. Same code path as live, so backtest results track live behavior. Supports `--strategies=KEY1,KEY2` to filter. |
| `app/Console/Commands/BotDownloadHistory.php` | Downloads monthly **or daily** 15m + 1h (+ optional 1m) kline zips from `data.binance.vision`, upserts into `kline_history`. Prerequisite for `bot:backtest`. Daily mode (`--days` / `--end-day`) is for backtesting recent windows that the monthly archive hasn't published yet. |
| `app/Models/BalanceSnapshot.php` | Equity-curve sample rows: `wallet_balance`, `available_balance`, `margin_balance`, `position_margin`, `open_positions`, `is_dry_run`, `created_at`. `$timestamps=false` (explicit `created_at` only). |
| `bootstrap/app.php` | CSRF exemption for `api/*` routes |
| `config/crypto.php` | Default config values |

## Settings (runtime-configurable from dashboard)

**Naming convention**: shared/global keys are flat at the root (`max_positions`, `dry_run`, etc.); strategy-owned keys are namespaced as `strategy.<key>.<setting>`. Legacy flat keys (e.g. `pump_threshold_pct`) still resolve via aliases in `Settings::ALIASES` for backwards compatibility — `Settings::get('pump_threshold_pct')` and `Settings::get('strategy.short_scalp.pump_threshold_pct')` return the same value.

### Generic Trading (shared across strategies)
| Key | Default | Description |
|-----|---------|-------------|
| `position_size_pct` | 10.0 | Margin as % of wallet (aggressive sizing) |
| `max_positions` | 10 | Global cap on simultaneously open positions across all strategies |
| `leverage` | 25 | Futures leverage |
| `dry_run` | true | Paper trading mode (runtime toggle via `ExchangeDispatcher` — close positions before flipping) |
| `starting_balance` | 10000 | Simulated starting balance |
| `dry_run_fee_rate` | 0.0005 | Dry-run simulated taker fee rate (0.05%); maker uses half |
| `dry_run_market_slippage_bps` | 3.0 | Dry-run only. Adverse slippage in basis points applied to every MARKET fill (entry + exit + SL/TP trigger + trail fire). SELL fills `mark × (1 - bps/10000)`, BUY fills `mark × (1 + bps/10000)`. Set 0 to disable. |
| `dry_run_maker_fill_rate` | 0.6 | Dry-run only. Probability that a non-crossing post-only LIMIT entry actually fills before timeout. The 40% miss returns `status='NEW'` so `placeEntryOrder` times out and falls back to MARKET (which pays the slippage above). Only affects SHORT entries (no `openLongLimit`). Set 1.0 to disable misses. |
| `dry_run_bracket_fail_rate` | 0.01 | Dry-run only. Per-bracket placement failure probability. `placeBrackets` retries once after a 1s sleep, so the post-retry per-bracket fail rate is `rate²` and the per-entry fail rate is roughly `2 × rate²` (≈0.02% at default). Bump to 0.05 to exercise the UNPROTECTED fail-safe path more often during testing. Set 0 to disable. |
| `trading_paused` | false | Pause new-position opening (existing positions still managed) |
| `funding_tracking_enabled` | true | Track funding fees per position |
| `ws_prices_enabled` | true | WebSocket price stream (documentation toggle; worker is process-level) |

### Short-Scalp Strategy (`strategy.short_scalp.*`)
| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | true | Master toggle for this strategy |
| `scan_interval` | 30 | Seconds between scan cycles |
| `pump_threshold_pct` | 25.0 | 24h gain lower bound (`>=`) |
| `pump_max_pct` | 50.0 | 24h gain upper bound (`<=`). Above this is continuation territory — handed off to `long_continuation`. `0` disables the upper cap. |
| `dump_threshold_pct` | 10.0 | 24h loss threshold to qualify (stored positive, compared to -value) |
| `min_volume_usdt` | 10_000_000 | Minimum 24h quote volume |
| `max_volume_usdt` | 25_000_000 | Skip pumps with volume above this. `0` disables the upper cap. |
| `ema_fast` / `ema_slow` | 9 / 21 | 15m EMA periods |
| `take_profit_pct` | 2.0 | TP distance below entry (used when trailing TP off) |
| `stop_loss_pct` | 1.0 | SL distance above entry (fallback when ATR SL off) |
| `max_hold_minutes` | 120 | Hard expiry |
| `cooldown_minutes` | 120 | Post-close wait — scoped per-`(symbol, short_scalp)`; another strategy can re-enter the symbol immediately |
| `failed_entry_cooldown_minutes` | 0 | Post-failure cooldown — `0` disables |
| `max_candle_body_pct` | 3.0 | Reject if last 15m candle body exceeds this |
| `min_red_candles` | 2 | Minimum consecutive closed 15m red candles required |
| `strict_downtrend_enabled` | true | When false, skip the entire 15m confirmation block; only funding-rate guard remains. Used by the wide-SL trailing strategy (T8). |
| `use_post_only_entry` | true | LIMIT maker entry first, MARKET fallback |
| `limit_order_timeout_seconds` | 3 | Poll window for post-only fill before MARKET fallback |
| `htf_filter_enabled` | true | Require 1h close below 1h EMA |
| `htf_ema_period` | 21 | EMA period on the 1h timeframe |
| `atr_sl_enabled` | true | Use `atr_sl_multiplier × ATR14` instead of `stop_loss_pct` |
| `atr_sl_multiplier` | 1.5 | ATR multiplier for SL offset |
| `partial_tp_trigger_pct` | 1.0 | Scale-out trigger (% favorable). `0` disables. |
| `partial_tp_size_pct` | 50.0 | % of position to close at partial trigger |
| `trailing_tp_enabled` | false | Replace fixed TP with TRAILING_STOP_MARKET. Disable partial TP when on (they compete) |
| `trailing_tp_arm_pct` | 2.0 | Favorable % at which trail arms |
| `trailing_tp_trail_pct` | 1.5 | Trail distance / Binance callback rate. Clamped to 0.1–5.0% live. |

### Long-Continuation Strategy (`strategy.long_continuation.*`)
| Key | Default | Description |
|-----|---------|-------------|
| `enabled` | false | Master toggle. Off until backtest validation completes. |
| `pump_threshold_pct` | 50.0 | 24h gain lower bound (strict-greater so the +50% boundary belongs to short_scalp) |
| `pump_max_pct` | 100.0 | 24h gain upper bound. Above 100% is the "Extreme" pump bucket — fewer events, more outlier-driven. `0` disables the upper cap. |
| `min_volume_usdt` / `max_volume_usdt` | 5M / 100M | Liquidity window. Wider than short's 10M-25M because pump-continuation coins tend to be heavier. |
| `ema_fast` / `ema_slow` | 9 / 21 | 15m EMA periods |
| `min_green_candles` | 2 | Minimum consecutive closed 15m green candles required (mirror of short's `min_red_candles`) |
| `max_candle_body_pct` | 5.0 | Wider than short's 3% — pump-continuation candles are fat by nature |
| `funding_max_rate` | 0.001 | LONGs *pay* funding when positive — skip rates ABOVE this cap (default +0.10% per 8h). Inverted vs short's "skip too negative". |
| `strict_uptrend_enabled` | true | Require EMA fast > slow on current+prior, last N green, body cap, 1h HTF up. Mirror of short's `strict_downtrend_enabled`. |
| `htf_filter_enabled` | true | Require 1h close above 1h EMA |
| `htf_ema_period` | 21 | EMA period on the 1h timeframe |
| `stop_loss_pct` | 3.0 | SL distance below entry. Wider than short's 2.5%. |
| `atr_sl_enabled` | false | Deterministic for v1; flip to true for ATR-based SL |
| `atr_sl_multiplier` | 1.5 | |
| `take_profit_pct` | 3.0 | Fallback TP (used only when trailing TP is off) |
| `partial_tp_trigger_pct` / `partial_tp_size_pct` | 0 / 50 | Disabled by default — competes with trailing |
| `trailing_tp_enabled` | true | Native TRAILING_STOP_MARKET above entry |
| `trailing_tp_arm_pct` | 1.5 | Tighter than short's 2.0 — continuations exhaust fast |
| `trailing_tp_trail_pct` | 1.0 | Wider than short's 0.7 — pump-noise tolerance |
| `max_hold_minutes` | 720 | 12h (half of short's recommended 1440) |
| `cooldown_minutes` | 240 | 4h post-close — pump events are once-per-pump |
| `failed_entry_cooldown_minutes` | 0 | Inherits same semantics as short |
| `use_post_only_entry` | false | Pumping markets disfavor maker fills — straight MARKET by default |
| `limit_order_timeout_seconds` | 3 | |
| `max_positions` | 3 | Per-strategy sub-cap. Pump-continuation events are rare (~15/month from research); >3 simultaneous setups is likely noise. Counted against the global `max_positions` ceiling. |

### Risk Controls (shared)
| Key | Default | Description |
|-----|---------|-------------|
| `circuit_breaker_enabled` | false | Gate new entries when realized+unrealized drawdown breaches the threshold |
| `circuit_breaker_drawdown_pct` | 25.0 | Peak-to-trough drawdown % that trips the breaker |
| `circuit_breaker_cooldown_hours` | 24 | How long to block new entries after a trip |
| `circuit_breaker_window_hours` | 24 | Legacy v1 setting — present in `Settings::KEYS` for backwards compat, not read by the current detector |

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions (with estimated fees & net P&L), trades, summary JSON |
| GET | `/api/stats` | Aggregated metrics for Overview KPIs + Risk page (combined-account view) **plus** a `by_strategy` array (per-strategy P&L, win rate, avg duration, exposure, today P&L). Combined view fields are unchanged — `by_strategy` is additive. |
| GET | `/api/trades` | Paginated trade history `{page, per_page, sort_by, sort_dir}` |
| GET | `/api/trades/aggregates` | Rollups for the Overview charts: P&L by symbol (top 10), close-reason mix (30d), trades per day (30d, densified) |
| GET | `/api/failed-entries` | Paginated `Position` rows with `status=Failed` |
| GET | `/api/settings` | Current settings + grouping metadata (`Settings::groups()`) for the Settings UI. Includes per-strategy enable toggles via the namespaced `strategy.<key>.enabled` keys. |
| GET | `/api/scanner` | Iterates ALL enabled strategies and emits a flat list of candidate rows, each tagged with `strategy_key`/`side`/`strategy_label`. Top-level `strategies[]` block lists every registered strategy and its enabled state. Optional `?strategy=KEY` filter — force-shows even disabled strategies for preview (rows then carry "Strategy disabled" in `blocked_reasons`). |
| GET | `/api/balance-history` | Equity-curve points `{range: 1h\|6h\|24h\|7d\|30d\|all}` — filters by current `dry_run` |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` (accepts both legacy flat keys and namespaced `strategy.<key>.<setting>` form) |
| POST | `/api/scan` | Scan + auto-trade across all enabled strategies `{auto_trade: bool, strategy?: string}`. Returns `trades_opened: [{symbol, side, strategy_key}]`. |
| POST | `/api/open-position` | Manually open `{symbol: string, strategy_key?: string}`. `strategy_key` defaults to the first enabled strategy if omitted; the strategy's `side()` determines direction. |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/close-all` | Close every open position at market |
| POST | `/api/add-margin` | Add to position `{position_id: int, amount_usdt: float}` |
| POST | `/api/reverse` | Reverse position direction `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions |

## Database (MariaDB 11)

**Tables**: `positions`, `trades`, `balance_snapshots`, `bot_settings`, `kline_history`, `cache`, `jobs`, `users`

**`positions` / `trades` strategy attribution**: both tables have a `strategy_key` column (varchar nullable, indexed). Every new Position+Trade row carries the originating strategy's key (e.g. `'short_scalp'`, `'long_continuation'`). The Phase-1 multi-strategy migration backfilled `'short_scalp'` for all pre-existing rows. Composite indexes `(strategy_key, status)` on positions and `(strategy_key, created_at)` on trades keep per-strategy queries fast as the tables grow. Cooldown checks scope per `(symbol, strategy_key)`; the global one-position-per-symbol invariant remains enforced via `Position::open()->where('symbol', X)->exists()`.

**`bot_settings`**: settings storage. Phase-1 migration renamed all short-scalp keys from flat (e.g. `pump_threshold_pct`) to namespaced (`strategy.short_scalp.pump_threshold_pct`). Legacy flat keys still resolve via the alias map in `Settings::ALIASES` for backwards compatibility.

**`kline_history`** (populated by `bot:download-history`, read by `HistoricalReplayExchange`): composite PK `(symbol, interval, open_time)`; columns `open`/`high`/`low`/`close` as `DECIMAL(25,12)`, `volume`/`quote_volume` as `DECIMAL(30,12)`, plus `close_time` (bigint ms) and `trade_count` (int). `interval` is stored as the Binance string literal (`15m`, `1h`, `1m`) to match the API. Upserted in 1000-row batches during download. Storing 15m + 1h + 1m rows in one table keeps the replay loader trivial.

**Trailing TP columns on `positions`**: `trailing_tp_armed` (bool, default false) and `trailing_extreme_price` (decimal nullable). Only used by `maybeTrailStop` in dry-run; live mode tracks the extreme server-side inside Binance and never writes these fields. The trailing order's algoId still goes in `tp_order_id` so cancellation / reconciliation paths are uniform.

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut, Failed
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual, Reversed

**Failed positions**: `Position` rows with `status=Failed` + `error_message` surface entry rejections (Binance refused the order, bracket placement failed, etc.) so they're visible in the dashboard rather than log-only. `Position::open()` scope excludes them, so they don't count toward `max_positions` or the one-per-symbol invariant. Written by `TradingEngine::recordFailedEntry()` from both the `placeEntryOrder` exception path and the post-entry/bracket-failure path (including the "UNPROTECTED — close also failed" case). A Failed row also blocks new entries on the same `(symbol, strategy_key)` for `strategy.<key>.failed_entry_cooldown_minutes` (default 0 = disabled).

## Development Commands

```bash
./develop up -d          # Start containers
./develop down           # Stop containers
./develop build          # Rebuild containers
./develop logs [-f]      # View logs
./develop art <cmd>      # Run artisan command
./develop bash           # Shell into app container
./develop status         # Show bot status
```

Backtest workflow (see the Backtest Harness section for details):

```bash
# 1. Populate kline_history (monthly OR daily zips from data.binance.vision)
./develop art bot:download-history --months=6                    # all USDT perps, last 6 completed months
./develop art bot:download-history --months=1 --symbols=BTCUSDT  # single symbol
./develop art bot:download-history --months=6 --skip-existing    # resume without re-downloading
./develop art bot:download-history --days=8 --intervals=15m,1h,1m  # daily mode for the last 8 completed days

# 2. Run a backtest
./develop art bot:backtest --from=2026-01-01 --to=2026-04-01 --truncate
./develop art bot:backtest --from=2026-01-01 --fixed-sizing       # flat sizing, no compounding
./develop art bot:backtest --from=2026-01-01 \
    --override=stop_loss_pct=1.5 --override=atr_sl_enabled=false  # per-run tuning
```

## Performance & Reliability

- **Ticker scan**: `getFuturesTickers()` — 1 API weight per cycle, returns 24h stats for ALL USDT perps
- **Per-candidate klines**: `getKlines(symbol, '15m', 30)` — 1 weight per candidate, 15s in-memory cache dedupes within cycle
- **Price cache** (`binance:prices`): populated every ~1s by `ws-worker` via WebSocket `!markPrice@arr` stream (30s TTL). `BinanceExchange::getPrice()` reads from here first; falls back to REST (10s TTL) if the key is missing.
- **Account data cache**: 10s TTL (`binance:account_data`)
- **Commission rate cache**: 1h TTL per symbol (`binance:commission:{symbol}`)
- **Funding rate cache**: 60s TTL (`binance:funding_rates`)
- **Exchange info cache**: 1h TTL. Used by `isTradable()` and LOT_SIZE rounding in `calculateQuantity()` / `formatQuantity()`
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: Default 30s cycle. One `getFuturesTickers` call + N klines calls (where N = candidates passing cheap gates). Responsive shutdown via sub-second sleep chunks.
- **ws-worker**: independent process, exponential reconnect backoff (1s→60s cap), SIGTERM/SIGINT graceful shutdown. Cache key is shared so `bot` benefits transparently.

## Fee, Funding & Balance Tracking

- **Trading fees**: Calculated on position close. Entry rate is maker when `entry_type=LIMIT_MAKER`, taker otherwise; exit is always taker (MARKET close). Fee = `price × quantity × rate` per side. Deducted from realized P&L. Stored in `Trade.fees`.
- **Slippage (dry-run)**: in addition to fees, dry-run market fills shift adversely by `dry_run_market_slippage_bps` (default 3 bps per side) so the realized entry/exit price differs from `getPrice($symbol)`. The slippage is baked into the price returned by `openShort`/`closeShort`/`openLong`/`closeLong` rather than tracked separately, so it lands directly in `Trade.pnl` (smaller wins, bigger losses) without needing a separate column. Live mode has no slippage simulation — the real fill price is whatever Binance returns.
- **Funding fees**: Accumulated per position at 8h settlement boundaries. Stored in `Position.funding_fee` (cumulative). Snapshotted to `Trade.funding_fee` on close. Positive = received, negative = paid.
- **Estimated fees for open positions**: Dashboard computes `estimated_fees` (entry + projected exit at current price) and `net_pnl` (unrealized P&L minus estimated fees plus funding) per position.
- **Net P&L formula**: `Trade.pnl` = rawPnl - tradingFees. Dashboard total: `Trade::sum('pnl') + Trade::sum('funding_fee') + open positions net P&L`.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun balance**: `walletBalance = starting_balance + Trade::sum('pnl') + Trade::sum('funding_fee') + open Position::sum('funding_fee')`. Margin = `position_size_usdt / leverage`.
- **Dynamic sizing**: `openShort()` calculates notional from `walletBalance × (position_size_pct / 100) × leverage` at trade time. Verifies `availableBalance >= margin` before placing orders.

## Backtest Harness

Replays historical 15m + 1h (+ optional 1m) klines through the **actual** strategy code (`StrategyRegistry` → each strategy's scanner → `TradingEngine::open()`) — no reimplementation. This keeps backtest results honest to live behavior: if a change passes backtest, the same code is what runs in production.

### Pipeline

1. **`bot:download-history`** populates `kline_history` from `data.binance.vision` monthly **or** daily zips.
   - **Monthly mode** (default): `--months=N` (default 1, fetches most recent N completed calendar months), `--end-month=YYYY-MM` (anchor walking backwards; default = last completed month).
   - **Daily mode**: `--days=N` (most recent N completed days, default anchor = yesterday) or `--end-day=YYYY-MM-DD` (anchor walking backwards). Either flag triggers daily mode and overrides `--months`/`--end-month`. URL switches from `…/monthly/klines/` to `…/daily/klines/`; CSV format is identical so `ingestCsv()` is unchanged. Use this for backtesting recent windows when the monthly archive hasn't published yet (Binance posts monthly zips a few days after month-end).
   - Shared flags: `--symbols=BTCUSDT,ETHUSDT` (default: all USDT perps from current exchangeInfo), `--intervals=15m,1h` (default; add `1m` for `--use-1m` runs), `--skip-existing` (skip `(symbol, interval, period)` combos that already have rows).
   - `15m` drives entry/exit decisions; `1h` drives the HTF filter; `1m` synthesises the 24h ticker for `--use-1m` runs.
   - Uses `unzip` shell-out on the downloaded zip, then batched (1000-row) DB upsert on `(symbol, interval, open_time)`. 404s on a `(symbol, period)` combo (symbol didn't exist yet, or daily zip not yet published) are counted as `missing` and don't error out.

2. **`bot:backtest`** runs the replay.
   - Flags: `--from=YYYY-MM-DD` (required), `--to=YYYY-MM-DD` (default from+30d, exclusive), `--symbols=...` (default: all loaded), `--starting-balance=10000`, `--fixed-sizing`, `--use-1m`, `--truncate`, `--no-force-close`, `--strategies=KEY1,KEY2` (default: all enabled), `--override=key=value` (repeatable; accepts any `Settings::KEYS` entry — including legacy aliases — with automatic type coercion).
   - `--truncate` wipes all `is_dry_run=true` `Position` + `Trade` rows and clears the three `circuit_breaker:*` cache keys so the run starts clean.
   - Always forces `Settings::override('dry_run', true)` + `trading_paused=false` + the supplied starting balance. DB `bot_settings` rows aren't touched — the live container keeps its own state.

3. **`bot:backtest-rolling`** chunks long runs by month to fit 1m-mode memory.
   - Adds `--from=YYYY-MM` / `--to=YYYY-MM` (exclusive), `--download-on-demand` (per-chunk fetch), `--cleanup-prior` (delete kline_history rows ≥2 chunks behind to keep disk bounded), `--strategies=` pass-through, `--summary-log=PATH` (per-month CSV).
   - **Pipelined downloads** (when `--download-on-demand` is on): chunk N+1's download starts in a background `Process::start()` while chunk N's backtest runs synchronously in the foreground. Saves ~`backtest_time` per chunk on the wall-clock total — the foreground backtest's progress bar stays visible; the background download's output is suppressed and only the final 3 lines are dumped on `wait()`. Chunk 0's downloads (lead-in + own month) are synchronous since there's no prior backtest to overlap with.
   - State carries across chunks via DB-resident Trade rows + cache; per-chunk subprocesses provide hard memory isolation.

**Memory limits**: `BotBacktest::handle()` and `BotDownloadHistory::handle()` both call `ini_set('memory_limit', '-1')` at the top. The default 128 MB CLI limit OOMs early during a `--use-1m` run (`HistoricalReplayExchange` loads ~1.5–2 GB of kline arrays for one month × 500+ symbols) or a multi-month download (transient buffers from unzip + bulk insert). Other long-running processes (bot, ws-worker) keep their tight default for safety; only the backtest/download paths override.

### Replay loop

- `HistoricalReplayExchange::__construct($fromMs, $toMs, $symbolFilter, $use1m)` loads all 15m + 1h rows into memory (and 1m if `$use1m=true`). Lead-in: **200 × 15m bars** (~50h) before `$fromMs` for EMA/ATR warm-up + 24h rolling aggregates; **50 × 1h bars** for the HTF EMA21; **1500 × 1m bars** in 1m mode for the synthesised 24h ticker. Per-symbol `open_times` arrays are pre-sorted for O(log n) binary search on clock lookup.
- The driver ticks the clock in 15-minute increments (or 60s in `--use-1m` mode): `$replay->setClock($ms)` + `Carbon::setTestNow($ts)`. The scanner's sim-time kline cache expires at tick boundaries, so repeated runs are deterministic.
- Each tick:
  1. `FundingSettlementService::settleFunding()` — but `HistoricalReplayExchange::getFundingRates()` returns `[]`, so funding is effectively zero during backtests (stubbed; noted as off-by-a-few-bps on long holds).
  2. For every open `is_dry_run=true` position, call `tickPosition()` to probe intra-bar SL/TP via the 15m bar's high/low.
  3. `scanForEntries()` — iterates the resolved strategy list (from `--strategies` or `StrategyRegistry::enabled()`), each calls `getCandidates()` + `analyze()` + `buildSignal()` → `TradingEngine::open()`. Per-strategy cooldown, max_positions sub-cap, and the cross-strategy one-position-per-symbol invariant all apply identically to the live code.

### Intra-bar SL/TP probe

A close-only exit check misses wicks that fill SL/TP mid-bar. `tickPosition()` walks three probe prices per bar using a color heuristic:
- **Red bar** (`c < o`): probe high → low → close (assumes the wick up happened before the sell-off).
- **Green bar** (`c >= o`): probe low → high → close.

Each probe price is clamped to the SL or TP trigger if it crosses — raw intra-bar extremes overshoot materially on volatile pumpers and would systematically exaggerate losses. `HistoricalReplayExchange::setProbePrice($symbol, $fillPrice)` installs a symbol-scoped override so any `closePosition()` triggered by `checkPosition()` executes at the trigger price; `clearProbePrice()` restores normal price lookup. Between probes the code re-reads the `Position` row and bails if it's no longer `Open` — the same idempotency guard used in live.

### Fixed-sizing vs compounding

- Default (compounding): wallet balance accumulates realized P&L + funding, so per-trade margin grows/shrinks with the equity curve — closer to live behavior but distorts per-trade-stats analysis (later trades in a winning run are sized larger).
- `--fixed-sizing`: `HistoricalReplayExchange::getAccountData()` returns `walletBalance = starting_balance` regardless of running P&L. `Trade.pnl` still accumulates normally, so the summary prints a correct realized total via `startingBalance + sum(pnl) + sum(funding)`. Use this when comparing strategy variants or measuring per-trade edge.

### End-of-run cleanup

Positions still open at the final simulated bar are force-closed via `forceCloseStragglers()` with `CloseReason::Expired`. Without this, the live bot container (which shares the DB and sees `is_dry_run=true` rows) would later close them at TODAY's price vs the backtest's entry price, skewing stats by orders of magnitude on any symbol that has since drifted. If a straggler has no price data at the final bar, it's marked Expired without a `Trade` row to keep the DB consistent.

### Leverage realism

`HistoricalReplayExchange::getMaxLeverage($symbol)` delegates to the real `BinanceExchange` (injected via `setRealExchange()`), so a backtest can't size 25x on symbols that Binance caps at 10x/20x. If the real lookup fails, it falls back to 20x. This keeps the replay trajectory from diverging from what live would actually accept.

### Known limitations

- Funding is zeroed (no historical funding data in `kline_history`).
- Post-only limit entries assume immediate fill at limit — no maker queue simulation.
- Order-book top is synthesized from the current price (±1bp), so post-only spreads are artificial.
- `calculateQuantity()` uses generic 6-decimal rounding instead of live LOT_SIZE.

## Dashboard

Component-driven Blade + Tailwind v4 + Alpine.js + ECharts (code-split). Sidebar navigation across 7 pages, each a separate Laravel route served by `DashboardController::index()` with the page name passed via `defaults('page', ...)`. Topbar (status pills, wallet, today P&L, last-update pulse, Pause toggle) is shared across pages and driven by Alpine subscribing to the central `polling` state.

**Pages:**

- **Overview** (`/`): top KPI strip (equity + 24h Δ, current drawdown, today P&L, open exposure) → equity curve with drawdown overlay (1h/6h/24h/7d/30d/all range pills) → performance band (profit factor 30d, rolling 20-trade win rate with sparkline, avg trade duration, max drawdown) → charts row (P&L by symbol top 10 bar, close-reason donut, trades-per-day histogram) → Open Positions table.
- **Positions** (`/positions`): wallet/available/margin/exposure KPIs + the same Open Positions table. Pull from `/api/data` only (no stats fetch).
- **Scanner** (`/scanner`): multi-strategy candidates table (auto-refreshes every 15s when tab visible). Each row carries a strategy badge (red for SHORT, green for LONG) and a side-aware "Short" / "Long" button. Trend pill, last-candle pill, and "favorable count" all flip based on `side`. Manual entry dropdown: `SYMBOL · strategy_key (SIDE)` composite — value is `strategy_key|symbol`. Top-of-table summary line: "Active: KEY1 · disabled: KEY2".
- **History** (`/history`): paginated closed-trade table.
- **Failed** (`/failed`): paginated `Position::status=Failed` rows with error messages.
- **Risk** (`/risk`): drawdown KPIs + circuit-breaker state card + recent-performance card (best/worst trade, current win/loss streak, 30d funding split) + Open Positions table.
- **Settings** (`/settings`): 12 grouped sections from `config/settings_meta.php` — Generic Trading, 7 Short-Scalp groups (`global` toggle + Entry / Exit / HTF / ATR / Partial TP / Trailing TP), 3 Long-Continuation groups (`global` toggle + Entry / Exit), Risk Controls — plus Danger Zone (reset). Each strategy group leads with its `strategy.<key>.enabled` toggle so on/off is the first thing you see. Sticky TOC, search filter (fuzzy on key + label + description), sticky save bar that surfaces only when fields are dirty. Toggles for booleans, number inputs with per-key `min/max/step`. ~70 keys total.

**Polling and lazy loading** ([resources/js/dashboard/polling.js](resources/js/dashboard/polling.js)): a tiny per-page scheduler registers only the pollers needed by the current page. Every page polls `/api/data` (10s) for the topbar; `/api/stats` (30s) is registered only when a `[id^="kpi-"]` element or risk panel exists; equity / aggregates pollers (60s each) are registered only when their chart elements are present. The scheduler pauses every poller while `document.hidden` is true and runs each due poller on visibility return.

**Code splitting**: `import * as echarts` is dynamic (`await import('./charts/equity.js')`), so Vite emits `theme-*.js` (ECharts, ~485 KB raw / ~162 KB gzip) as a separate chunk that is fetched only on pages that render a chart. Initial JS payload on chartless pages (Settings, History, Failed, Scanner) is ~126 KB raw / ~41 KB gzip.

**Visual conventions**: Dark Tailwind palette (`zinc-950` surface, `zinc-900` elevated, sky-400 accent, emerald/rose for P&L, amber for warnings); Inter UI font; tabular-numbers monospace for all P&L/price columns; subscript-zero notation for micro-prices (`$0.0₅1234`); side/leverage/DRY-RUN badges on every position row; toast notifications via Alpine bus; cursor-pointer restored on `button:not(:disabled)` (Tailwind v4 reset removes the default).

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Some ISPs hijack `*.binance.com` — Docker Desktop's host `/etc/hosts` entry handles `fapi.binance.com`, but `fstream.binance.com` (WebSocket) needs an explicit `extra_hosts` mapping on the `ws-worker` and `ws-user-data` services (resolved via `dig +short fstream.binance.com @1.1.1.1`). Without it the TLS handshake fails with a `*.ioh.co.id` certificate mismatch.
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After major changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `./develop down && ./develop build && ./develop up -d`
- **Mode-dependent SL/TP close path**: In **dry-run** mode, `checkPosition()` price-triggers SL/TP and calls `closePosition()`; `maybeTrailStop` ratchets the SL toward the running extreme on a favorable move when trailing TP is on. In **live** mode, Binance owns the close via `STOP_MARKET` / `TAKE_PROFIT_MARKET` (or `TRAILING_STOP_MARKET` when trailing is on) brackets; the bot's `checkPosition()` only updates P&L and handles expiry, and `maybeTrailStop` short-circuits because Binance trails the order server-side. Live close reconciliation happens via `ws-user-data` (primary), `BotRun` safety reconcile (fallback), or `closePosition()`'s idempotent error path (race with manual close). This avoids the previous race where a bot-issued MARKET close would error out against a Binance SL/TP fill that already flattened the position.
- **listenKey lifecycle**: Binance user-data listenKeys are valid 60 min and must be kept alive every 30 min or less via `PUT /fapi/v1/listenKey`. The `ws-user-data` worker schedules this; on any disconnect it requests a fresh listenKey (previous one may have silently expired). If the worker is down for longer than 60s, `BotRun`'s safety reconcile (every 30s cycle) picks up missed fills via `getOpenPositions` + `getOrderStatus`.
- **Live trading readiness**: Per-order cancellation ensures closing one position doesn't destroy other symbols' orders. Fail-safe on open ensures no unprotected positions. Reconciliation converges through multiple paths (ws stream, safety poll, manual close) — all guarded by `status !== Open` refresh checks so idempotency holds.
- **LONG positions**: opened only when an enabled strategy has `side()='LONG'` (currently `long_continuation`, default OFF) emits a Signal, **or** when the dashboard `Reverse` button flips a SHORT into a LONG. The cross-strategy invariant (one open position per symbol globally) prevents conflicting directions on the same coin.
- **`dry_run` toggle caveat**: `ExchangeDispatcher` reads the setting on every call, so flipping the dashboard toggle takes effect on the next bot cycle. **But open positions are tied to whichever exchange created them** (real orders on Binance vs DB-only rows with `is_dry_run=true`). Flipping mid-flight routes close orders to the wrong side. **Always close all open positions before toggling `dry_run`.**
- **Backtest shares the dry-run DB**: `bot:backtest` writes `is_dry_run=true` `Position` + `Trade` rows. If the `bot` container is also running in dry-run mode, it will see those rows and try to manage them — including closing backtest stragglers at today's price. Safest workflow: `./develop stop bot ws-worker ws-user-data` before a backtest, or always pass `--truncate` and let `forceCloseStragglers()` flatten everything at the final simulated bar. The bigger equity-curve widget on the dashboard will also mix live and backtest history (both are `is_dry_run=true` samples); the range pills or a manual `balance_snapshots` purge are the only separators.
- **HTF filter fails open**: if the 1h kline fetch errors or returns fewer than `htf_ema_period + 1` bars, both `ShortScanner::checkHigherTfDowntrend()` and `LongContinuationScanner::checkHigherTfUptrend()` return `true` — the filter is permissive on data gaps rather than restrictive. Backtests inherit this: a symbol with spotty 1h history will pass HTF even when the 15m state alone wouldn't justify an entry.
- **ATR SL default differs by strategy**: `strategy.short_scalp.atr_sl_enabled=true` out of the box, so the short strategy's `stop_loss_pct` is only consulted as a fallback. `strategy.long_continuation.atr_sl_enabled=false` so the long strategy uses its `stop_loss_pct=3.0` directly. When tuning the fixed-pct SL on the short strategy, flip `atr_sl_enabled=false` or the override will be silently ignored.
- **Trailing TP and partial TP are mutually exclusive in spirit**: both target the favorable side. Leaving `partial_tp_trigger_pct > 0` while `trailing_tp_enabled=true` will scale out half at the partial trigger and leave the remainder under the trailing stop, which mostly defeats the trailing TP's job of riding the move. The recommended trailing config sets `partial_tp_trigger_pct=0`.
- **`callbackRate` clamping**: Binance accepts 0.1–5.0%; values outside that band are rejected with `-2021/-1102` and would orphan the position with no favorable-side bracket. `BinanceExchange::openTrailingStop()` clamps before submission so a config typo can't kill an entry mid-flight. Backtest harness has no clamp (uses arbitrary `trailing_tp_trail_pct`), but live placement always respects the band.
- **Trailing-armed SL labelling**: in dry-run, when the trailing stop ratchets into the favorable side of entry and then fires, `trailingExitReason()` returns `CloseReason::TakeProfit` (not `StopLoss`), even though the close went through the slHit branch. The Trade row reflects "TakeProfit" so the close-reason mix and dashboard summaries treat trailed exits as wins, matching how live `TRAILING_STOP_MARKET` fills are reconciled (also as TakeProfit, via `tp_order_id` match in `ws-user-data`).
- **Dry-run trailing TP fires earlier than backtest predicts** (observed 2026-05): the May 7-9 live dry-run window matched the May 1-8 backtest on win rate (~56%) and on `stop_loss` exit prices (both clamp at -2.5%) — but live's average winning exit was ~1.36% favorable while the same-window backtest captured ~2.9% per win (R:R 0.59 live vs 1.20 backtest, normalized to the same notional). The gap is structural: live `maybeTrailStop()` reads from the `binance:prices` cache (~1s WebSocket cadence) and fires on the *first* price that retraces by `trailing_tp_trail_pct` from the running extreme; the backtest probe heuristic walks each 15m bar in 3 idealized steps (color-keyed high/low/close) so the trailing extreme ratchets to the deepest probe before any retrace check, capturing the full favorable range of the bar. Real markets wiggle more than 3 probes and the live trail consequently exits earlier on small bounces. Practical compensations:
    - Widen `trailing_tp_trail_pct` (0.5 → 1.0) and/or `trailing_tp_arm_pct` (1.0 → 1.5) to absorb sub-1s wiggle. May 1-8 backtest with arm 1.5 / trail 1.0 was ~equivalent net P&L (+156% vs +153%) but with R:R 1.14 vs 0.96 — slightly fewer trades and wins, materially bigger avg win, ~4 pp more breakeven safety margin.
    - Trust backtest WR more than backtest avg-win when forecasting live performance; the SL side ports faithfully but the favorable side is over-optimistic by roughly 2× on win size in the trailing-TP regime.
- **Funding guard semantics differ by side**: the short-scalp scanner skips a candidate when the funding rate is **too negative** (default `< -0.05%`) — shorts pay heavy funding when rates flip negative. The long-continuation scanner does the inverse: skips when the rate is **too positive** (default `> +0.10%`) — longs pay heavy funding when rates run hot. Don't apply one rule to the other strategy's setting key.
- **`placeBrackets` retries each bracket once with a 1s sleep**: the loop in `TradingEngine::placeBrackets` runs up to 2 attempts per bracket; if `setStopLoss`/`setTakeProfit`/`openTrailingStop` throws on attempt 1, it sleeps 1s and retries the failed bracket on attempt 2 (the succeeded one is skipped via the `$slOrderId === null` / `$tpOrderId === null` guards). Two consequences worth knowing:
    - Live transient Binance errors (rate-limit blip, brief 5xx) usually survive — most don't need operator action.
    - The dry-run `dry_run_bracket_fail_rate` is per-attempt, so the effective post-retry failure rate per bracket is `rate²` and per entry is roughly `2 × rate²` (≈0.02% at default 0.01). If you want the UNPROTECTED fail-safe path to fire during testing, bump to 0.05 (≈0.5% per entry post-retry) — going higher than ~0.1 starts force-closing too many positions to be useful for strategy validation.
- **Dry-run friction knobs only fire on the right code path**: `dry_run_market_slippage_bps` only applies to MARKET fills, not to post-only LIMIT fills (a successful maker fill returns the exact limit price unchanged). When `use_post_only_entry=true` and `dry_run_maker_fill_rate=0.6`, roughly 60% of SHORT entries enter at the limit price with no slippage and 40% fall back to MARKET with `bps` adverse — so the realized average entry slippage is ~`(1 - maker_fill_rate) × bps` ≈ 1.2 bps in the default config. All closes are MARKET so they always pay full slippage on the exit side, regardless of `use_post_only_entry`.
- **Per-strategy code deployment**: source isn't bind-mounted into the worker containers (only `storage/logs` is). After editing PHP files, deploy with `docker cp app/ crypto-bot-bot-1:/app/ && docker cp app/ crypto-bot-app-1:/app/ && docker compose restart bot ws-worker ws-user-data scheduler` — every container that reads from `/app/app/` needs the copy. After editing JS in `resources/js/dashboard/`, run `npm run build` and `docker cp public/build crypto-bot-app-1:/app/public/`.
- **Strategy `enabled` flag is per-process state**: BotRun reads it once at startup to print the "Enabled strategies" header, and once per cycle inside the entry loop. Toggling `strategy.<key>.enabled` from the dashboard takes effect on the next cycle (≤30s) without a worker restart, but the startup header won't refresh until the worker reboots.
- **Position TRUNCATE needs FK_CHECKS=0**: `trades.position_id` has a FK to `positions.id`, so naively `TRUNCATE TABLE positions` errors with 1701. The wipe pattern is `SET FOREIGN_KEY_CHECKS=0; TRUNCATE trades; TRUNCATE positions; TRUNCATE balance_snapshots; SET FOREIGN_KEY_CHECKS=1;` — gets all three tables back to auto-increment 1 in one go. Plain DELETE works without the FK trick but doesn't reset the sequence.
- **Backups before destructive operations**: `mariadb-dump bot_settings positions trades balance_snapshots > storage/backups/pre-wipe-{ts}.sql` before any wipe / settings-rename migration. The settings rename migration (Phase 1) is one-way (`down()` is intentionally a no-op), so a backup is the only rollback path.
