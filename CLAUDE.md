# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) **short-scalp trading bot** for Binance Futures. Scans all USDT perpetuals every 30s, opens SHORT positions on coins that pumped >=25% or dumped <=-10% over 24h when the 15m chart confirms a downtrend, using 25x leverage for quick 2% profit exits. Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck)
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, configurable scan interval, default 30s)
- **scheduler** — Laravel scheduler (`schedule:run` loop)
- **ws-worker** — Long-running WebSocket worker (`bot:ws-prices`) that subscribes to Binance `!markPrice@arr` and writes to the `binance:prices` cache key (30s TTL). `BinanceExchange::getPrice()` reads that cache, so this replaces 10s REST polling with sub-second push updates. Has `extra_hosts: fstream.binance.com:18.178.11.87` to bypass ISP DNS hijacking (host `/etc/hosts` propagation only covers `fapi.binance.com`). If the worker dies, cache entries expire in 30s and `getPrice()` falls back to REST transparently.
- App runs migrations; bot/scheduler/ws-worker wait for app to start first

### Exchange Abstraction
- `ExchangeInterface` — contract for all exchange operations
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB
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
   - **Manage open positions**: for each open position, fetch latest price, call `checkPosition()` to check TP/SL/expiry
   - **Find new entries**: `ShortScanner::getCandidates()` returns pump/dump candidates (one API call for all tickers)
   - For each candidate: apply cheap gates (paused, max_positions, already-open-on-symbol, cooldown), then `analyze15m()` for the Strict downtrend check, then open SHORT via `TradingEngine::openShort()` if eligible
3. **Cycle summary** logged: `Cycle: candidates=N analyzed=M opened=V openPositions=X`

### Short-Scalp Strategy

**Entry criteria (all must pass):**
1. **24h mover**: `priceChangePct >= pump_threshold_pct` (default +25%) OR `priceChangePct <= -dump_threshold_pct` (default -10%)
2. **Liquidity**: 24h quote volume >= `min_volume_usdt` (default 10M USDT)
3. **15m downtrend (Strict rule)**:
   - EMA fast (default 9) < EMA slow (default 21) on BOTH current and prior 15m candle
   - Current price (close of last closed candle) < EMA fast
   - **Last N CLOSED 15m candles are red** (close < open) — where N = `min_red_candles` (default 2). Checks `candles[last-1]` and `candles[last-2]`, not the in-progress candle. Filters dead-cat-bounce setups.
   - Candle body of last closed candle <= `max_candle_body_pct` (default 3%) — skips frenzied volatility
4. **Funding guard**: current funding rate >= -0.05% (avoid paying heavy funding against us)
5. **Capacity gates**: trading not paused, global `max_positions` not hit, no open position on this symbol, cooldown cleared

**Exit criteria:**
- TP hit: `entry × (1 - take_profit_pct / 100)` — for SHORT, TP is BELOW entry (default 2% below)
- SL hit: `entry × (1 + stop_loss_pct / 100)` — for SHORT, SL is ABOVE entry (default 1% above)
- Expiry: `max_hold_minutes` (default 120 = 2h)

**Risk sizing at 25x leverage (default):**
- Margin per trade = `position_size_pct` (default 10%) × wallet balance
- Notional = margin × leverage = 250% of wallet per trade
- SL 1% adverse = 25% margin loss = 2.5% wallet loss per bad trade
- TP 2% favorable = 50% margin gain = 5% wallet gain per good trade
- Practical concurrent cap: ~9-10 positions at 10% margin each exhausts available balance; `max_positions=10` is effectively the ceiling
- The engine's `availableBalance >= margin` guard prevents over-commit

**Cooldown:** `cooldown_minutes` (default 120 = 2h) after any close on same symbol.

### Scanner (ShortScanner)
- **One-shot ticker scan**: `getCandidates()` calls `getFuturesTickers()` once per cycle, filters by pump/dump threshold + min_volume, returns `ShortCandidate[]` sorted by absolute 24h change
- **Per-candidate klines**: `analyze15m(symbol)` fetches 30 15m candles (with 15s in-memory cache), returns `ShortAnalysis` with EMA/candle/funding data + Strict-rule verdict
- **Kline cache**: 15-second TTL in-memory cache prevents duplicate API calls within same loop iteration

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
| `app/Services/ShortAnalysis.php` | Readonly DTO: EMA fast/slow, candle state, funding rate, downtrendOk, blockedReason |
| `app/Services/ShortSignal.php` | Lightweight DTO for `TradingEngine::openShort` |
| `app/Services/TechnicalAnalysis.php` | EMA calculations |
| `app/Services/TradingEngine.php` | SHORT open/close/checkPosition, `placeEntryOrder` (post-only → MARKET fallback), manual add/reverse helpers |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/FundingSettlementService.php` | 8h funding rate settlement for open positions |
| `app/Services/Exchange/ExchangeInterface.php` | Contract for all exchange operations |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation |
| `app/Services/Exchange/ExchangeDispatcher.php` | Runtime router: reads `Settings::get('dry_run')` on each call, delegates to live or dry |
| `app/Http/Controllers/DashboardController.php` | All API endpoints |
| `resources/views/dashboard.blade.php` | Single-page dark theme dashboard |
| `routes/web.php` | Route definitions |
| `app/Console/Commands/BotRun.php` | Continuous scan+monitor loop with graceful shutdown |
| `app/Console/Commands/BotWsPrices.php` | Long-running WebSocket worker for `!markPrice@arr` → `binance:prices` cache |
| `app/Console/Commands/BotStatus.php` | CLI status overview |
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

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions (with estimated fees & net P&L), trades, summary JSON |
| GET | `/api/settings` | Current settings |
| GET | `/api/scanner` | Scan candidates: pump/dump filter + 15m analysis + blocked reasons |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Scan + auto-trade `{auto_trade: bool}` |
| POST | `/api/open-position` | Manually open SHORT `{symbol: string}` (direction forced to SHORT) |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/add-margin` | Add to position `{position_id: int, amount_usdt: float}` |
| POST | `/api/reverse` | Reverse position direction `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions |

## Database (MariaDB 11)

**Tables**: `positions`, `trades`, `bot_settings`, `cache`, `jobs`, `users`

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual, Reversed

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

## Dashboard

Four tabs: **Dashboard**, **Scanner**, **Trade History**, **Settings**.

- **Dashboard tab**:
  - **Cards**: Wallet balance, available balance, margin in use, P&L (net), fees, funding, win rate
  - **Open Positions table**: Symbol (with side/leverage/DRY badges), entry/current price, unrealized P&L, P&L%, size (notional USDT), net P&L (with fees + funding rate + accumulated funding), SL/TP, opened time, hold time, Add/Reverse/Close buttons
- **Scanner tab**:
  - **Scanner table**: 10 columns — Symbol (with PUMP/DUMP pill) | 24h % | Volume | Price | 15m Trend (DOWN/UP/FLAT pill) | Last Candle (RED/GREEN + body %) | Funding | Pos | Status (✓/✗ with blocked reason) | Actions (Short button)
  - **Scan button**: View-only scan — fetches latest candidates without opening
  - **Scan + Auto Trade button**: Scans and automatically opens SHORTs where all entry conditions pass
  - **Pause/Resume button**: Toggles `trading_paused` setting
  - **Manual SHORT open**: Symbol dropdown (populated from candidates) + Open SHORT button (direction is always SHORT)
  - **Auto-refresh**: Every 15s when the Scanner tab is visible; pauses when tab is backgrounded
- **Trade History tab**: Symbol (with side/leverage/DRY badge), entry/exit price, realized P&L, P&L%, size, fees (with funding breakdown), close reason, opened/closed time
- **Settings tab**: All 19 runtime-configurable settings, auto-populated from `Settings::all()`
- **Formatting**: Thousand separators on dollar amounts, subscript notation for micro-prices, inline color styling for P&L values

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Some ISPs hijack `*.binance.com` — Docker Desktop's host `/etc/hosts` entry handles `fapi.binance.com`, but `fstream.binance.com` (WebSocket) needs an explicit `extra_hosts` mapping on the `ws-worker` service (resolved via `dig +short fstream.binance.com @1.1.1.1`). Without it the TLS handshake fails with a `*.ioh.co.id` certificate mismatch.
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After major changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `./develop down && ./develop build && ./develop up -d`
- **Dual SL/TP checking**: Bot checks SL/TP in code every cycle AND places exchange-side SL/TP orders as safety net. If a Binance SL/TP triggers between bot checks, the bot reconciles on next cycle.
- **Live trading readiness**: Per-order cancellation ensures closing one position doesn't destroy other symbols' orders. Fail-safe ensures no unprotected positions. DB may briefly be out of sync until next check cycle if exchange SL/TP triggers independently.
- **LONG positions**: The bot never auto-opens LONG positions. A LONG can only exist if created manually via the `Reverse` button (SHORT → LONG). `checkPosition`/`closePosition`/`calculatePnl` still handle both sides for these manually-created positions.
- **`dry_run` toggle caveat**: `ExchangeDispatcher` reads the setting on every call, so flipping the dashboard toggle takes effect on the next bot cycle. **But open positions are tied to whichever exchange created them** (real orders on Binance vs DB-only rows with `is_dry_run=true`). Flipping mid-flight routes close orders to the wrong side. **Always close all open positions before toggling `dry_run`.**
