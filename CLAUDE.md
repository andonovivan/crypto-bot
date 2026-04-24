# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) **short-scalp trading bot** for Binance Futures. Scans all USDT perpetuals every 30s, opens SHORT positions on coins that pumped >=25% or dumped <=-10% over 24h when the 15m chart confirms a downtrend, using 25x leverage for quick 2% profit exits. Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck)
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, configurable scan interval, default 30s)
- **scheduler** — Laravel scheduler (`schedule:run` loop). Currently drives `bot:snapshot-balance` every 5 minutes for the equity-curve widget.
- **ws-worker** — Long-running WebSocket worker (`bot:ws-prices`) that subscribes to Binance `!markPrice@arr` and writes to the `binance:prices` cache key (30s TTL). `BinanceExchange::getPrice()` reads that cache, so this replaces 10s REST polling with sub-second push updates. Also drives **event-driven position management**: on each ws frame it loads open positions and calls `TradingEngine::checkPosition` with the fresh price, giving ~1s TP/SL reaction time in dry-run mode (vs 30s via BotRun's cycle). Has `extra_hosts: fstream.binance.com:18.178.11.87` to bypass ISP DNS hijacking (host `/etc/hosts` propagation only covers `fapi.binance.com`). If the worker dies, cache entries expire in 30s, `getPrice()` falls back to REST, and BotRun's 30s cycle takes over position checks — degraded but still functional.
- **ws-user-data** — Long-running WebSocket worker (`bot:ws-user-data`) for the Binance user-data stream. Creates a listenKey (`POST /fapi/v1/listenKey`), connects to `wss://fstream.binance.com/ws/{listenKey}`, and keeps it alive every 30 min (`PUT /fapi/v1/listenKey`). On `ORDER_TRADE_UPDATE` events with `X=FILLED` whose `o.i` matches a `Position.sl_order_id` or `Position.tp_order_id`, it calls `TradingEngine::reconcileFillFromStream()` to cancel the sibling bracket and record the `Trade` row. This is the **primary close-reconciliation path in live mode** since SL/TP are handled by Binance directly. Same DNS bypass via `extra_hosts`. On disconnect, reconnects with exponential backoff (1→60s) and always requests a fresh listenKey. Dry-run mode returns a fake listenKey so the ws connect fails quietly in a loop — that's benign since dry-run positions close via `checkPosition`'s price-trigger path and never emit real fills.
- App runs migrations; bot/scheduler/ws-worker/ws-user-data wait for app to start first

### Exchange Abstraction
- `ExchangeInterface` — contract for all exchange operations. Covers orders/price/kline reads (`getPrice`, `getKlines`, `getFuturesTickers`, `getOrderBookTop`), orders (`openShort`/`openShortLimit`/`openLong`/close variants, `setStopLoss`, `setTakeProfit`), order lifecycle (`getOrderStatus`, `cancelOrder`, `cancelOrders`, `cancelAlgoOrder`, `getAlgoOrderStatus`), account state (`getAccountData`, `getBalance`, `getOpenPositions`, `getCommissionRate`, `getFundingRates`, `getMaxLeverage`, `getUserTrades`), and the user-data listenKey lifecycle (`createListenKey`, `keepAliveListenKey`, `closeListenKey`).
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB
- `HistoricalReplayExchange` — offline backtest exchange. Loads `kline_history` rows into memory, services `getPrice` / `getKlines` / `getFuturesTickers` against a movable clock (`setClock($unixMs)`), synthesizes order-book top from the current price (±1bp), and stubs out order placement / `getOrderStatus` / listenKey as no-ops. Uses the same DB `Position`/`Trade` rows (with `is_dry_run=true`) so account balance and open-position tracking match DryRunExchange. **Only used during `bot:backtest`** — bound into the container via `app()->instance(ExchangeInterface::class, $replay)`. See the Backtest Harness section.
- `ExchangeDispatcher` — implements `ExchangeInterface` and is the binding for `ExchangeInterface::class` in the container. On every call it reads `Settings::get('dry_run')` and delegates to either the live or dry instance. This makes the dashboard `dry_run` toggle effective at the next bot cycle without a container restart.
- **CAUTION**: flipping `dry_run` while positions are open will route their closes to the newly-active exchange (which won't know about them). **Close all open positions before toggling.**
- **Account data**: `getAccountData()` returns wallet balance, available balance, unrealized profit, margin balance, position margin, maintenance margin
  - BinanceExchange: calls `/fapi/v2/account` (cached 10s)
  - DryRunExchange: calculates from DB — margin = `position_size_usdt / leverage` (not full notional). Wallet balance includes realized P&L + closed funding + open funding.
- **Commission rates**: `getCommissionRate($symbol)` returns maker/taker rates (cached 1h per symbol in live mode; uses `dry_run_fee_rate` in dry-run)
- **Funding rates**: `getFundingRates($symbol)` returns current funding rate, next funding time, mark price per symbol (cached 60s)
- **Futures tickers**: `getFuturesTickers()` returns 24h stats for all USDT perpetuals in one call — the key API used by the scanner (1 API weight)

### Core Flow (Short-Scalp)
1. **BotRun** — continuous loop, default 30s interval
2. **Per cycle**:
   - Settle funding fees at 8h UTC boundaries via `FundingSettlementService`
   - **Manage open positions**: for each open position, fetch latest price, call `checkPosition()` to update unrealized P&L and check TP/SL/expiry. `checkPosition()` behavior differs by mode — see "SL/TP close reconciliation" below.
   - **Safety reconcile** (live only): call `getOpenPositions()` once and for any DB `Position::open()` missing from the exchange list, invoke `TradingEngine::reconcileMissingPosition()` which probes bracket order status via `getOrderStatus` and records the close. The reverse check also fires: any Binance-side position with no matching DB row is logged as a warning (throttled to once per 15 min per symbol). These untracked positions are **not adopted** — the bot has no way to know the intended SL/TP — so the operator is expected to investigate.
   - **Find new entries**: `ShortScanner::getCandidates()` returns pump/dump candidates (one API call for all tickers)
   - For each candidate: apply cheap gates (paused, max_positions, already-open-on-symbol, cooldown), then `analyze15m()` for the Strict downtrend check, then open SHORT via `TradingEngine::openShort()` if eligible
3. **Cycle summary** logged: `Cycle: candidates=N analyzed=M opened=V openPositions=X`
4. **Idempotency**: `TradingEngine::checkPosition`, `closePosition`, `reconcileFillFromStream`, and `reconcileMissingPosition` all refresh the row and bail if `status !== Open`. Multiple paths (ws-prices ticks, ws-user-data fills, BotRun cycle, manual dashboard actions) can race without double-counting.

### SL/TP close reconciliation (mode-dependent)

- **Dry-run mode**: `checkPosition()` compares live price to `stop_loss_price` / `take_profit_price` and triggers `closePosition()` directly. No bracket orders exist on a real exchange, so there is nothing to reconcile.
- **Live mode**: `checkPosition()` does **not** price-check SL/TP. Binance owns the close via the `STOP_MARKET` / `TAKE_PROFIT_MARKET` brackets placed at open (`reduceOnly=true`). The bot reconciles via three paths, in priority order:
  1. **ws-user-data** (primary, ~real-time): `ORDER_TRADE_UPDATE` with `X=FILLED` → `reconcileFillFromStream()` cancels the sibling bracket and writes the `Trade` row using the fill's `ap`/`L`/`z` fields.
  2. **BotRun safety reconcile** (30s fallback): if a DB position is flat on Binance but still `Open` locally (ws missed the event), `reconcileMissingPosition()` queries `getOrderStatus` on both brackets and reconciles.
  3. **Manual close idempotency**: if a user-initiated close races with a Binance SL/TP fill, `closePosition()` catches Binance error codes `-2022` / `-4046` ("already flat") and delegates to `reconcileFromBrackets()` so the Trade row still reflects the true SL/TP exit rather than a spurious Manual close.
- **Expiry** (`max_hold_minutes`) is bot-driven in both modes — Binance has no time-based close order.

### Short-Scalp Strategy

**Entry criteria (all must pass):**
1. **24h mover**: `priceChangePct >= pump_threshold_pct` (default +25%) OR `priceChangePct <= -dump_threshold_pct` (default -10%)
2. **Liquidity**: 24h quote volume >= `min_volume_usdt` (default 10M USDT)
3. **15m downtrend (Strict rule)**:
   - EMA fast (default 9) < EMA slow (default 21) on BOTH current and prior 15m candle
   - Current price (close of last closed candle) < EMA fast
   - **Last N CLOSED 15m candles are red** (close < open) — where N = `min_red_candles` (default 2). Checks `candles[last-1]` and `candles[last-2]`, not the in-progress candle. Filters dead-cat-bounce setups.
   - Candle body of last closed candle <= `max_candle_body_pct` (default 3%) — skips frenzied volatility
4. **Higher-timeframe confirmation** (conditional, `htf_filter_enabled=true` by default): 1h close < 1h EMA (period `htf_ema_period`, default 21). Fetches `htf_ema_period + 5` 1h klines via `checkHigherTfDowntrend()`. **Fails open** — if the 1h fetch errors or returns too few bars, the filter passes. Rationale: a 15m setup that looks weak on a coin still in a 1h uptrend tends to be a bounce-top that gets bought back into.
5. **Funding guard**: current funding rate >= -0.05% (avoid paying heavy funding against us)
6. **Capacity gates**: trading not paused, global `max_positions` not hit, no open position on this symbol, post-close cooldown cleared (`cooldown_minutes`), post-failure cooldown cleared (`failed_entry_cooldown_minutes`, default 360 = 6h — prevents re-probing a symbol whose last entry was rejected by Binance)
7. **Circuit breaker** (conditional, `circuit_breaker_enabled=false` by default): if tripped, block new entries until cooldown expires. See the Risk Controls section below.

**Exit criteria:**
- TP hit: `entry × (1 - take_profit_pct / 100)` — for SHORT, TP is BELOW entry (default 2% below)
- SL hit: `entry × (1 + stop_loss_pct / 100)` — for SHORT, SL is ABOVE entry (default 1% above). When `atr_sl_enabled=true` (default), SL distance uses `atr_sl_multiplier × ATR14` instead of a fixed pct, clamped to `(entry / leverage) × 0.70` so the stop can't sit past liquidation. TP stays pct-based regardless.
- **Partial take-profit** (conditional, `partial_tp_trigger_pct > 0`, default 1%): once the position is favorable by `partial_tp_trigger_pct`, `TradingEngine::maybeTakePartialTp()` closes `partial_tp_size_pct` (default 50%) at MARKET, sets `Position.partial_tp_taken=true` so it can't re-fire, and leaves the remainder under the existing SL/TP brackets (`reduceOnly=true` auto-scales to remaining qty). Runs on every `checkPosition()` tick.
- Expiry: `max_hold_minutes` (default 120 = 2h)

**Risk sizing at 25x leverage (default):**
- Margin per trade = `position_size_pct` (default 10%) × wallet balance
- Notional = margin × leverage = 250% of wallet per trade
- SL 1% adverse = 25% margin loss = 2.5% wallet loss per bad trade
- TP 2% favorable = 50% margin gain = 5% wallet gain per good trade
- Practical concurrent cap: ~9-10 positions at 10% margin each exhausts available balance; `max_positions=10` is effectively the ceiling
- The engine's `availableBalance >= margin` guard prevents over-commit

**Cooldown:** `cooldown_minutes` (default 120 = 2h) after any close on same symbol. `failed_entry_cooldown_minutes` (default 360 = 6h) after any `status=Failed` row on same symbol.

### Risk Controls

**Drawdown circuit breaker** (`circuit_breaker_enabled`, default `false`):

Evaluated at every `TradingEngine::openShort()` call — gates entries only, already-open positions continue to be managed normally.

- **Equity** = `walletBalance + sum(unrealized_pnl on open positions)`.
- **Peak** = rolling high-water mark, persisted in cache key `circuit_breaker:equity_peak`. Updated on every check; anchored to the trough when the breaker trips.
- **Drawdown** = `(peak - equity) / peak × 100`.
- **Trip condition**: `drawdown >= circuit_breaker_drawdown_pct` (default 25%) → write `circuit_breaker:cooldown_until = now + circuit_breaker_cooldown_hours` (default 24h) and block new entries.
- **Short-circuit**: while `cooldown_until > now()`, `openShort()` returns early.
- **Clock reset**: cache key `circuit_breaker:measurement_start` is a v1 remnant — set by older code paths but not consulted by the current detector; safe to ignore but not yet removed.
- `circuit_breaker_window_hours` exists in `Settings::KEYS` for backwards compat but is **not read** by the v2 detector. Only the drawdown-and-cooldown pair matters.
- `bot:backtest --truncate` clears all three cache keys so the backtest starts with a clean risk-control slate.

### Scanner (ShortScanner)
- **One-shot ticker scan**: `getCandidates()` calls `getFuturesTickers()` once per cycle, filters by pump/dump threshold + min_volume, returns `ShortCandidate[]` sorted by absolute 24h change
- **Per-candidate klines**: `analyze15m(symbol)` fetches 30 15m candles (with 15s in-memory cache), returns `ShortAnalysis` with EMA fast/slow, candle state, funding rate, ATR14 (for ATR-based SL sizing), 1h HTF confirmation, downtrendOk flag, and blocked reason
- **HTF confirmation**: when `htf_filter_enabled=true`, `checkHigherTfDowntrend()` fetches `htf_ema_period + 5` 1h klines and returns `close[last] < EMA[last]`. Fails open on fetch error
- **ATR calculation**: `TechnicalAnalysis::calculateATR(klines, 14)` is computed from the same 15m klines and attached to `ShortAnalysis.atr` — consumed by `TradingEngine::placeBrackets()` for ATR-based SL sizing
- **Kline cache**: 15-second TTL in-memory cache prevents duplicate API calls within same loop iteration. Keyed on `Carbon::now()` (sim-time aware) so the cache expires at deterministic tick boundaries during backtests instead of drifting with wall-clock.

### Position Management (SHORT-only)
- **SHORT entry**: `TradingEngine::openShort(ShortSignal)` — calculates qty from margin × leverage / entry price, places SHORT via `placeEntryOrder()`, then STOP_MARKET SL + TAKE_PROFIT_MARKET TP brackets
- **Entry flow (`placeEntryOrder`)**: if `use_post_only_entry` is true, attempt a LIMIT SELL at best ask with `timeInForce=GTX` (post-only, maker-only). Poll order status every 500ms for `limit_order_timeout_seconds` (default 3s). On full fill → return `entry_type=LIMIT_MAKER`. On partial fill + timeout → cancel, MARKET the remainder → `entry_type=MIXED`. On timeout with no fill, or post-only rejection (Binance -2021/-2010) → MARKET the full qty → `entry_type=MARKET_FALLBACK`. If `use_post_only_entry=false`, skip straight to MARKET (`entry_type=MARKET`). LONG entries (via `reversePosition`) always use MARKET.
- **Maker fee discount**: When `entry_type=LIMIT_MAKER`, entry-side fees use the maker rate instead of taker. MIXED/MARKET_FALLBACK/MARKET use taker.
- **One position per symbol (invariant)**: enforced in `openShort` via open-position existence check. Not a user setting.
- **SL/TP order tracking**: Each position stores `sl_order_id` and `tp_order_id`. On close, only that position's orders are cancelled.
- **SL/TP fail-safe**: If SL or TP order placement fails after opening, the position is immediately closed and any partial orders cancelled. No unprotected positions.
- **Margin check**: Before opening, verifies `availableBalance >= margin`
- **P&L (SHORT)**: `(entry - current) × qty`
- **Fees**: Deducted on close — entry fee + exit fee. Exit is always taker (MARKET close). Entry is maker or taker depending on `entry_type`. Stored in `Trade.fees`.
- **Funding fees**: Accumulated per position via `FundingSettlementService` at 8h boundaries. Snapshotted into `Trade.funding_fee` on close.
- **Manual actions** (dashboard): `addToPosition` averages entry with new margin (also routed through `placeEntryOrder`); `reversePosition` closes the SHORT and opens a LONG with the same USDT size (LONG positions created this way still get checked for TP/SL/expiry by the engine but are never auto-opened).

### Funding Rate Tracking
- **Mechanism**: Binance perpetual futures settle funding every 8h (00:00, 08:00, 16:00 UTC). Positive rate = longs pay shorts; negative = shorts pay longs.
- **FundingSettlementService**: Called each bot tick. Detects 8h boundary crossing, calculates `notional × rate` per open position, updates `Position.funding_fee`.
- **Sign convention**: LONG + positive rate = pays (negative); SHORT + positive rate = receives (positive)
- **DryRun simulation**: Uses real funding rates from `/fapi/v1/premiumIndex` to simulate settlements
- **P&L integration**: Open position net P&L = unrealized - estimated fees + funding. Closed trade snapshots funding into `Trade.funding_fee`.

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/ShortScanner.php` | Scans all USDT perps, filters pump/dump candidates, Strict 15m downtrend check |
| `app/Services/ShortCandidate.php` | Readonly DTO: `{symbol, price, priceChangePct, volume, reason}` (pump/dump) |
| `app/Services/ShortAnalysis.php` | Readonly DTO: EMA fast/slow, candle state, funding rate, ATR, HTF verdict, downtrendOk, blockedReason |
| `app/Services/ShortSignal.php` | Lightweight DTO for `TradingEngine::openShort` (includes `atr` for ATR-based SL sizing) |
| `app/Services/TechnicalAnalysis.php` | EMA + ATR14 calculations |
| `app/Services/TradingEngine.php` | SHORT open/close/checkPosition (dry-run SL/TP trigger + live expiry), `placeEntryOrder` (post-only → MARKET fallback), `placeBrackets` (ATR-based or pct-based SL), `maybeTakePartialTp` (scale-out at `partial_tp_trigger_pct`), `reconcileFillFromStream` / `reconcileMissingPosition` / `reconcileFromBrackets` / `finalizeClose` (live reconciliation paths), drawdown circuit-breaker check, manual add/reverse helpers |
| `app/Services/Settings.php` | DB-first settings with config fallback. `override($key, $value)` / `clearOverrides()` shadow the DB for this PHP process only — used by `bot:backtest` to flip `dry_run=true` and apply per-run tuning without stomping on live shared state. |
| `app/Services/FundingSettlementService.php` | 8h funding rate settlement for open positions |
| `app/Services/Exchange/ExchangeInterface.php` | Contract for all exchange operations |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation |
| `app/Services/Exchange/HistoricalReplayExchange.php` | Offline replay exchange for `bot:backtest` — serves `kline_history` rows against a movable clock, stubs out order APIs, writes DB rows with `is_dry_run=true`. Supports fixed-sizing mode (`setFixedSizing`) and symbol-scoped probe prices (`setProbePrice`/`clearProbePrice`) for intra-bar SL/TP simulation. |
| `app/Services/Exchange/ExchangeDispatcher.php` | Runtime router: reads `Settings::get('dry_run')` on each call, delegates to live or dry |
| `app/Http/Controllers/DashboardController.php` | All API endpoints |
| `resources/views/dashboard.blade.php` | Single-page dark theme dashboard |
| `routes/web.php` | Route definitions |
| `app/Console/Commands/BotRun.php` | Continuous scan+monitor loop with graceful shutdown |
| `app/Console/Commands/BotWsPrices.php` | Long-running WebSocket worker for `!markPrice@arr` → `binance:prices` cache |
| `app/Console/Commands/BotWsUserData.php` | Long-running WebSocket worker for Binance user-data stream — reconciles SL/TP fills via `ORDER_TRADE_UPDATE` events |
| `app/Console/Commands/BotSnapshotBalance.php` | One-shot command that writes a `BalanceSnapshot` row from `getAccountData()`. Scheduled every 5 min for the equity-curve widget. |
| `app/Console/Commands/BotStatus.php` | CLI status overview |
| `app/Console/Commands/BotBacktest.php` | Replays `kline_history` rows through the live `ShortScanner` + `TradingEngine` code using `Carbon::setTestNow` + `HistoricalReplayExchange`. Same code path as live, so backtest results track live behavior. |
| `app/Console/Commands/BotDownloadHistory.php` | Downloads monthly 15m + 1h kline zips from `data.binance.vision`, upserts into `kline_history`. Prerequisite for `bot:backtest`. |
| `app/Models/BalanceSnapshot.php` | Equity-curve sample rows: `wallet_balance`, `available_balance`, `margin_balance`, `position_margin`, `open_positions`, `is_dry_run`, `created_at`. `$timestamps=false` (explicit `created_at` only). |
| `bootstrap/app.php` | CSRF exemption for `api/*` routes |
| `config/crypto.php` | Default config values |

## Settings (runtime-configurable from dashboard)

### Generic Trading
| Key | Default | Description |
|-----|---------|-------------|
| `position_size_pct` | 10.0 | Margin as % of wallet (aggressive sizing) |
| `max_positions` | 10 | Max concurrent open positions across all symbols |
| `leverage` | 25 | Futures leverage |
| `dry_run` | true | Paper trading mode (runtime toggle via `ExchangeDispatcher` — close positions before flipping) |
| `starting_balance` | 10000 | Simulated starting balance |
| `dry_run_fee_rate` | 0.0005 | Dry-run simulated taker fee rate (0.05%); maker uses half |
| `trading_paused` | false | Pause new-position opening (existing positions still managed) |
| `funding_tracking_enabled` | true | Track funding fees per position |
| `ws_prices_enabled` | true | WebSocket price stream (documentation toggle; worker is process-level) |

### Short-Scalp Strategy
| Key | Default | Description |
|-----|---------|-------------|
| `scan_interval` | 30 | Seconds between scan cycles |
| `pump_threshold_pct` | 25.0 | 24h gain threshold to qualify (+%) |
| `dump_threshold_pct` | 10.0 | 24h loss threshold to qualify (stored positive, compared to -value) |
| `min_volume_usdt` | 10_000_000 | Minimum 24h quote volume |
| `ema_fast` | 9 | 15m EMA fast period |
| `ema_slow` | 21 | 15m EMA slow period |
| `take_profit_pct` | 2.0 | TP distance below entry |
| `stop_loss_pct` | 1.0 | SL distance above entry |
| `max_hold_minutes` | 120 | Hard expiry (2h) |
| `cooldown_minutes` | 120 | Post-close wait before re-entering same symbol (2h) |
| `max_candle_body_pct` | 3.0 | Reject if last 15m candle body exceeds this (frenzied volatility filter) |
| `min_red_candles` | 2 | Minimum consecutive closed 15m red candles required (1 = old behavior, 2 = default, 3 = conservative) |
| `use_post_only_entry` | true | Attempt LIMIT maker entry at best ask; fall back to MARKET on timeout/rejection |
| `limit_order_timeout_seconds` | 3 | Poll window for post-only fill before MARKET fallback |
| `failed_entry_cooldown_minutes` | 360 | Post-failure cooldown per symbol (6h) — skip symbols whose last entry was rejected by the exchange |

### Higher-Timeframe Filter
| Key | Default | Description |
|-----|---------|-------------|
| `htf_filter_enabled` | true | Require 1h close below 1h EMA before opening a SHORT |
| `htf_ema_period` | 21 | EMA period on the 1h timeframe |

### ATR-Based Stop Loss
| Key | Default | Description |
|-----|---------|-------------|
| `atr_sl_enabled` | true | Use `atr_sl_multiplier × ATR14` for SL distance instead of `stop_loss_pct`. Clamped to `(entry / leverage) × 0.70` to avoid liquidation-side stops. TP stays pct-based. |
| `atr_sl_multiplier` | 1.5 | ATR multiplier for SL offset |

### Partial Take-Profit
| Key | Default | Description |
|-----|---------|-------------|
| `partial_tp_trigger_pct` | 1.0 | Scale-out trigger (% favorable). `0` disables. |
| `partial_tp_size_pct` | 50.0 | % of position qty to close at the trigger (remainder stays under the original TP/SL brackets) |

### Risk Controls
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
| GET | `/api/settings` | Current settings |
| GET | `/api/scanner` | Scan candidates: pump/dump filter + 15m analysis + blocked reasons |
| GET | `/api/balance-history` | Equity-curve points `{range: 1h\|6h\|24h\|7d\|30d\|all}` — filters by current `dry_run` |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Scan + auto-trade `{auto_trade: bool}` |
| POST | `/api/open-position` | Manually open SHORT `{symbol: string}` (direction forced to SHORT) |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/add-margin` | Add to position `{position_id: int, amount_usdt: float}` |
| POST | `/api/reverse` | Reverse position direction `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions |

## Database (MariaDB 11)

**Tables**: `positions`, `trades`, `balance_snapshots`, `bot_settings`, `kline_history`, `cache`, `jobs`, `users`

**`kline_history`** (populated by `bot:download-history`, read by `HistoricalReplayExchange`): composite PK `(symbol, interval, open_time)`; columns `open`/`high`/`low`/`close` as `DECIMAL(25,12)`, `volume`/`quote_volume` as `DECIMAL(30,12)`, plus `close_time` (bigint ms) and `trade_count` (int). `interval` is stored as the Binance string literal (`15m`, `1h`) to match the API. Upserted in 1000-row batches during download. Storing both 15m (entry/exit timeframe) and 1h (HTF filter) rows in one table keeps the replay loader trivial.

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut, Failed
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual, Reversed

**Failed positions**: `Position` rows with `status=Failed` + `error_message` surface entry rejections (Binance refused the order, bracket placement failed, etc.) so they're visible in the dashboard rather than log-only. `Position::open()` scope excludes them, so they don't count toward `max_positions` or the one-per-symbol invariant. Written by `TradingEngine::recordFailedEntry()` from both the `placeEntryOrder` exception path and the post-entry/bracket-failure path (including the "UNPROTECTED — close also failed" case). A Failed row also blocks new entries on the same symbol for `failed_entry_cooldown_minutes` (default 6h).

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
# 1. Populate kline_history (monthly zips from data.binance.vision)
./develop art bot:download-history --months=6                    # all USDT perps, last 6 completed months
./develop art bot:download-history --months=1 --symbols=BTCUSDT  # single symbol
./develop art bot:download-history --months=6 --skip-existing    # resume without re-downloading

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

- **Trading fees**: Calculated on position close. Both entry and exit use taker rate (market orders). Fee = `price × quantity × takerRate` per side. Deducted from realized P&L. Stored in `Trade.fees`.
- **Funding fees**: Accumulated per position at 8h settlement boundaries. Stored in `Position.funding_fee` (cumulative). Snapshotted to `Trade.funding_fee` on close. Positive = received, negative = paid.
- **Estimated fees for open positions**: Dashboard computes `estimated_fees` (entry + projected exit at current price) and `net_pnl` (unrealized P&L minus estimated fees plus funding) per position.
- **Net P&L formula**: `Trade.pnl` = rawPnl - tradingFees. Dashboard total: `Trade::sum('pnl') + Trade::sum('funding_fee') + open positions net P&L`.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun balance**: `walletBalance = starting_balance + Trade::sum('pnl') + Trade::sum('funding_fee') + open Position::sum('funding_fee')`. Margin = `position_size_usdt / leverage`.
- **Dynamic sizing**: `openShort()` calculates notional from `walletBalance × (position_size_pct / 100) × leverage` at trade time. Verifies `availableBalance >= margin` before placing orders.

## Backtest Harness

Replays historical 15m + 1h klines through the **actual** `ShortScanner` + `TradingEngine` code — no reimplementation. This keeps backtest results honest to live behavior: if a change passes backtest, the same code is what runs in production.

### Pipeline

1. **`bot:download-history`** populates `kline_history` from `data.binance.vision` monthly zips.
   - Flags: `--months=N` (default 1, fetches most recent N completed calendar months), `--symbols=BTCUSDT,ETHUSDT` (default: all USDT perps from current exchangeInfo), `--skip-existing` (skip `(symbol, interval, month)` combos that already have rows).
   - Downloads both `15m` and `1h` intervals per symbol. `15m` drives entry/exit decisions; `1h` drives the HTF filter.
   - Uses `unzip` shell-out on the downloaded zip, then batched (1000-row) DB upsert on `(symbol, interval, open_time)`. 404s on a `(symbol, month)` combo (symbol didn't exist yet) are counted as `missing` and don't error out.

2. **`bot:backtest`** runs the replay.
   - Flags: `--from=YYYY-MM-DD` (required), `--to=YYYY-MM-DD` (default from+30d, exclusive), `--symbols=...` (default: all loaded), `--starting-balance=10000`, `--fixed-sizing`, `--truncate`, `--override=key=value` (repeatable; accepts any `Settings::KEYS` entry with automatic type coercion).
   - `--truncate` wipes all `is_dry_run=true` `Position` + `Trade` rows and clears the three `circuit_breaker:*` cache keys so the run starts clean.
   - Always forces `Settings::override('dry_run', true)` + `trading_paused=false` + the supplied starting balance. DB `bot_settings` rows aren't touched — the live container keeps its own state.

### Replay loop

- `HistoricalReplayExchange::__construct($fromMs, $toMs, $symbolFilter)` loads all 15m + 1h rows into memory. Lead-in: **200 × 15m bars** (~50h) before `$fromMs` so the scanner has room for EMA/ATR warm-up and 24h rolling aggregates on the very first tick; **50 × 1h bars** for the HTF EMA21. Per-symbol `open_times` arrays are pre-sorted for O(log n) binary search on clock lookup.
- The driver ticks the clock in 15-minute increments: `$replay->setClock($ms)` + `Carbon::setTestNow($ts)`. The scanner's sim-time kline cache expires at tick boundaries, so repeated runs are deterministic.
- Each tick:
  1. `FundingSettlementService::settleFunding()` — but `HistoricalReplayExchange::getFundingRates()` returns `[]`, so funding is effectively zero during backtests (stubbed; noted as off-by-a-few-bps on long holds).
  2. For every open `is_dry_run=true` position, call `tickPosition()` to probe intra-bar SL/TP via the 15m bar's high/low.
  3. `scanForEntries()` — runs `ShortScanner::getCandidates()`, applies cheap gates (cooldown, failed-entry cooldown, max_positions, one-per-symbol), then `analyze15m()` + `TradingEngine::openShort()` on the winners.

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

Four tabs: **Dashboard**, **Scanner**, **Trade History**, **Settings**.

- **Dashboard tab**:
  - **Cards**: Wallet balance, available balance, margin in use, P&L (net), fees, funding, win rate
  - **Equity Curve**: hand-rolled SVG line chart plotting `wallet_balance` (blue) and `available_balance` (green) from `balance_snapshots`. Range pills: 1h/6h/24h/7d/30d/All (24h default). Hover circles show timestamp, both balances, and open-position count. Auto-refreshes every 60s. Filtered by current `dry_run` toggle — flipping the mode swaps series to the other history.
  - **Open Positions table**: Symbol (with side/leverage/DRY badges), entry/current price, unrealized P&L, P&L%, size (notional USDT), net P&L (with fees + funding rate + accumulated funding), SL/TP, opened time, hold time, Add/Reverse/Close buttons
- **Scanner tab**:
  - **Scanner table**: 10 columns — Symbol (with PUMP/DUMP pill) | 24h % | Volume | Price | 15m Trend (DOWN/UP/FLAT pill) | Last Candle (RED/GREEN + body %) | Funding | Pos | Status (✓/✗ with blocked reason) | Actions (Short button)
  - **Scan button**: View-only scan — fetches latest candidates without opening
  - **Scan + Auto Trade button**: Scans and automatically opens SHORTs where all entry conditions pass
  - **Pause/Resume button**: Toggles `trading_paused` setting
  - **Manual SHORT open**: Symbol dropdown (populated from candidates) + Open SHORT button (direction is always SHORT)
  - **Auto-refresh**: Every 15s when the Scanner tab is visible; pauses when tab is backgrounded
- **Trade History tab**: Symbol (with side/leverage/DRY badge), entry/exit price, realized P&L, P&L%, size, fees (with funding breakdown), close reason, opened/closed time. Above the trade table, a red-bordered **Failed Entries** block renders `Position` rows with `status=Failed` (last 50) — shows symbol, size, `error_message`, time. Only rendered when failed entries exist.
- **Settings tab**: All runtime-configurable settings (34 keys across generic trading, short-scalp strategy, HTF filter, ATR SL, partial TP, and risk controls), auto-populated from `Settings::all()`
- **Formatting**: Thousand separators on dollar amounts, subscript notation for micro-prices, inline color styling for P&L values

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Some ISPs hijack `*.binance.com` — Docker Desktop's host `/etc/hosts` entry handles `fapi.binance.com`, but `fstream.binance.com` (WebSocket) needs an explicit `extra_hosts` mapping on the `ws-worker` and `ws-user-data` services (resolved via `dig +short fstream.binance.com @1.1.1.1`). Without it the TLS handshake fails with a `*.ioh.co.id` certificate mismatch.
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After major changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `./develop down && ./develop build && ./develop up -d`
- **Mode-dependent SL/TP close path**: In **dry-run** mode, `checkPosition()` price-triggers SL/TP and calls `closePosition()`. In **live** mode, Binance owns the close via `STOP_MARKET` / `TAKE_PROFIT_MARKET` brackets; the bot's `checkPosition()` only updates P&L and handles expiry. Live close reconciliation happens via `ws-user-data` (primary), `BotRun` safety reconcile (fallback), or `closePosition()`'s idempotent error path (race with manual close). This avoids the previous race where a bot-issued MARKET close would error out against a Binance SL/TP fill that already flattened the position.
- **listenKey lifecycle**: Binance user-data listenKeys are valid 60 min and must be kept alive every 30 min or less via `PUT /fapi/v1/listenKey`. The `ws-user-data` worker schedules this; on any disconnect it requests a fresh listenKey (previous one may have silently expired). If the worker is down for longer than 60s, `BotRun`'s safety reconcile (every 30s cycle) picks up missed fills via `getOpenPositions` + `getOrderStatus`.
- **Live trading readiness**: Per-order cancellation ensures closing one position doesn't destroy other symbols' orders. Fail-safe on open ensures no unprotected positions. Reconciliation converges through multiple paths (ws stream, safety poll, manual close) — all guarded by `status !== Open` refresh checks so idempotency holds.
- **LONG positions**: The bot never auto-opens LONG positions. A LONG can only exist if created manually via the `Reverse` button (SHORT → LONG). `checkPosition`/`closePosition`/`calculatePnl` still handle both sides for these manually-created positions.
- **`dry_run` toggle caveat**: `ExchangeDispatcher` reads the setting on every call, so flipping the dashboard toggle takes effect on the next bot cycle. **But open positions are tied to whichever exchange created them** (real orders on Binance vs DB-only rows with `is_dry_run=true`). Flipping mid-flight routes close orders to the wrong side. **Always close all open positions before toggling `dry_run`.**
- **Backtest shares the dry-run DB**: `bot:backtest` writes `is_dry_run=true` `Position` + `Trade` rows. If the `bot` container is also running in dry-run mode, it will see those rows and try to manage them — including closing backtest stragglers at today's price. Safest workflow: `./develop stop bot ws-worker ws-user-data` before a backtest, or always pass `--truncate` and let `forceCloseStragglers()` flatten everything at the final simulated bar. The bigger equity-curve widget on the dashboard will also mix live and backtest history (both are `is_dry_run=true` samples); the range pills or a manual `balance_snapshots` purge are the only separators.
- **HTF filter fails open**: if the 1h kline fetch errors or returns fewer than `htf_ema_period + 1` bars, `checkHigherTfDowntrend()` returns `true` — the filter is permissive on data gaps rather than restrictive. Backtests inherit this: a symbol with spotty 1h history will pass HTF even when the 15m state alone wouldn't justify an entry.
- **ATR SL default is ON**: `atr_sl_enabled=true` out of the box, so `stop_loss_pct` is only consulted as a fallback when ATR data is unavailable or the setting is flipped off. When tuning the fixed-pct SL, also flip `atr_sl_enabled=false` (or pass `--override=atr_sl_enabled=false` to `bot:backtest`) or the change will be silently ignored.
