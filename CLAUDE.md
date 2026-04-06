# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) **grid trading bot** for Binance Futures. Opens multiple concurrent positions per symbol at different price levels, using EMA alignment for trend direction and fixed-% TP/SL per trade. Direction-specific TP/SL: longs get wider stops, shorts get tighter exits. Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck)
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, 30-second scan+monitor loop)
- **scheduler** — Laravel scheduler (`schedule:run` loop)
- App runs migrations; bot/scheduler wait for app to start first

### Exchange Abstraction
- `ExchangeInterface` — contract for all exchange operations (21 methods)
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB
- **Account data**: `getAccountData()` returns wallet balance, available balance, unrealized profit, margin balance, position margin, maintenance margin
  - BinanceExchange: calls `/fapi/v2/account` (cached 10s)
  - DryRunExchange: calculates from DB — margin = `position_size_usdt / leverage` (not full notional). Wallet balance includes realized P&L + closed funding + open funding.
- **Commission rates**: `getCommissionRate($symbol)` returns maker/taker rates
  - BinanceExchange: calls `/fapi/v1/commissionRate` (cached 1h per symbol)
  - DryRunExchange: uses `dry_run_fee_rate` setting (default 0.05%)
- **Funding rates**: `getFundingRates($symbol)` returns current funding rate, next funding time, mark price per symbol
  - BinanceExchange: calls `/fapi/v1/premiumIndex` (cached 60s)
  - DryRunExchange: delegates to real exchange (market data)
- `getBalance()` delegates to `getAccountData()['availableBalance']` in both implementations

### Core Flow (Grid Trading)
1. **BotRun** — 30-second loop: for each watchlist symbol, runs two phases per symbol
2. **Phase 1 — Manage positions**: Fetches ALL open positions for the symbol, calls `checkPosition()` on each (SL/TP/expiry checks)
3. **Phase 2 — Evaluate new grid entry**: Checks EMA alignment, cooldown, per-symbol count, grid spacing, then opens position if all pass
4. **WaveScanner::analyze()** — Fetches klines (1h default, cached 15s), computes EMA(5/13), RSI(7), ATR(14), classifies trend state
5. **TradingEngine::openPosition()** — Calculates position size dynamically (% of wallet balance x leverage), opens LONG or SHORT with direction-specific fixed-% SL/TP. Stores SL/TP order IDs per position. Fail-safe: if SL/TP placement fails, closes position immediately.
6. **TradingEngine::checkPosition()** — Checks SL/TP/expiry, closes if hit
7. **TradingEngine::closePosition()** — Cancels only this position's SL/TP orders by ID (not all orders for symbol), closes via closeLong()/closeShort(), records Trade with fees and funding snapshot
8. **Phase 1b — Auto-add to losing positions**: After managing positions, evaluates DCA adds for positions approaching SL with signal still confirming direction
9. **FundingSettlementService** — Called each tick, settles funding fees at 8h UTC boundaries (00:00, 08:00, 16:00)

### Market Scanner (WaveScanner)
- **Watchlist-based**: Scans pre-selected coins (default: BTCUSDT)
- **Data**: Configurable kline interval (default 1h), 50 candles
- **Indicators**: EMA(5/13), RSI(7), ATR(14)
- **Direction**: LONG if EMA5 > EMA13, SHORT if EMA5 < EMA13
- **Fresh cross**: EMA crossed between previous and current candle
- **RSI filter**: Off by default. When enabled, rejects LONG if RSI > 80, SHORT if RSI < 20
- **ATR sanity**: Reject if ATR < 0.05% of price (dead market)
- **Wave states**:
  - `new_wave` — fresh EMA cross + RSI confirms
  - `riding` — EMA aligned, no fresh cross
  - `weakening` — EMA gap shrinking 3 consecutive candles
- **Entry condition**: Opens on `new_wave` or `riding` (any EMA-aligned state)
- **Signals are ephemeral** — WaveSignal DTOs, never stored in DB
- **In-memory kline cache** — 15-second TTL prevents duplicate API calls within same loop iteration

### Grid Trading Logic
- **Multiple positions per symbol**: Up to `grid_max_per_symbol` (default 10) concurrent positions per symbol
- **Grid spacing**: New entries must be >= `grid_spacing_pct` (default 0.5%) away from ALL existing entries on the same symbol
- **Direction-specific TP/SL**:
  - LONG: TP `grid_long_tp_pct` (1.68%), SL `grid_long_sl_pct` (5.0%)
  - SHORT: TP `grid_short_tp_pct` (1.0%), SL `grid_short_sl_pct` (2.0%)
- **Cooldown**: After closing a position, waits `grid_cooldown_minutes` (default 1 min) before re-entering same symbol
- **Max total positions**: `max_positions` (default 30) across all symbols
- **Two-phase processing**: Phase 1 manages ALL open positions, Phase 2 evaluates new grid entry — both run every cycle regardless of each other
- **Origin**: Reverse-engineered from OKX position history (148 unique positions, 89.86% win rate, up to 8 concurrent per symbol, ~1% grid spacing)

### Dynamic Position Sizing
- **Percentage-based**: Position size calculated dynamically at trade time from wallet balance
- **Formula**: `margin = walletBalance x (position_size_pct / 100)`, `notional = margin x leverage`
- **Example**: $10,000 balance, 1% setting, 10x leverage -> $100 margin -> $1,000 notional position
- **Scales automatically**: As balance grows/shrinks from P&L, position sizes adjust proportionally

### Position Management (bidirectional)
- **LONG entry**: `openLong()` — direction-specific fixed % SL/TP
- **SHORT entry**: `openShort()` — direction-specific fixed % SL/TP (tighter than longs)
- **SL/TP order tracking**: Each position stores `sl_order_id` and `tp_order_id` from the exchange. On close, only that position's orders are cancelled (not all orders for the symbol). Safe for grid trading with multiple positions per symbol.
- **SL/TP fail-safe**: If SL or TP order placement fails after opening, the position is immediately closed and any partial SL/TP orders are cancelled. No unprotected positions can exist.
- **Per-order cancellation**: `cancelOrder(symbol, orderId)` uses `DELETE /fapi/v1/order` to cancel a specific order. `cancelOrders(symbol)` still available for cleanup but not used in normal flow.
- **Margin check**: Before opening, verifies `availableBalance >= notional / leverage`
- **P&L**: LONG = (current - entry) * qty, SHORT = (entry - current) * qty
- **Fees**: Deducted from P&L on close — entry fee + exit fee (taker rate on notional). Stored in `Trade.fees` column.
- **Funding fees**: Accumulated per position via `FundingSettlementService`. Snapshotted into `Trade.funding_fee` on close. Included in net P&L calculations.
- **Expiry**: `grid_max_hold_minutes` (default 1440 min / 24h)

### Auto-Add (DCA) to Losing Positions
- **SL proximity trigger**: Auto-adds when loss reaches X% of the stop loss distance (default 80%). Adapts to direction-specific SL.
  - LONG (5% SL): triggers at -4% loss (80% × 5%)
  - SHORT (2% SL): triggers at -1.6% loss (80% × 2%)
- **Signal guard**: Only adds when wave state is `new_wave` or `riding` (not `weakening`). Direction must match.
- **Max layers**: Configurable via `grid_auto_add_max_layers` (default 3 adds + 1 original = 4 total)
- **After add**: Weighted average entry, SL/TP recalculated from new entry, proximity resets naturally
- **Natural cooldown**: After averaging, loss % drops below threshold — price must drop further to trigger next add

### Funding Rate Tracking
- **Mechanism**: Binance perpetual futures settle funding every 8h (00:00, 08:00, 16:00 UTC). Positive rate = longs pay shorts; negative = shorts pay longs.
- **FundingSettlementService**: Called every bot tick (30s). Detects 8h boundary crossing, calculates `notional × rate` per open position, updates `Position.funding_fee`.
- **Sign convention**: LONG + positive rate = pays (negative); SHORT + positive rate = receives (positive)
- **DryRun simulation**: Uses real funding rates from `/fapi/v1/premiumIndex` to simulate settlements
- **P&L integration**: Open position net P&L = unrealized - estimated fees + funding. Closed trade snapshots funding into `Trade.funding_fee`.
- **Dashboard**: Shows current funding rate per position (earning/paying), cumulative Funding card in summary

### Direction Conflict Prevention
- **One-way mode**: Binance Futures uses single net position per symbol. Bot prevents opening opposite-direction positions on same symbol.
- **Check**: Before new entry in both BotRun and scanNow, verifies no existing positions with opposite side.

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/WaveScanner.php` | Market scanner: EMA(5/13) cross, RSI(7), ATR(14) on 1h candles |
| `app/Services/WaveAnalysis.php` | Ephemeral DTO: direction, state, RSI, ATR, price |
| `app/Services/WaveSignal.php` | Lightweight DTO for openPosition() interface |
| `app/Services/TechnicalAnalysis.php` | EMA, RSI, MACD, ATR calculations |
| `app/Services/TradingEngine.php` | Position open/close/checkPosition (grid-aware) |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/FundingSettlementService.php` | 8h funding rate settlement for open positions |
| `app/Services/Exchange/ExchangeInterface.php` | Contract: 21 methods including account data, commission rates, funding rates & per-order cancellation |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT, account data, commission rates, funding rates) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation (margin-based balance, fee + funding simulation) |
| `app/Http/Controllers/DashboardController.php` | All API endpoints |
| `resources/views/dashboard.blade.php` | Single-page dark theme dashboard |
| `routes/web.php` | Route definitions |
| `routes/console.php` | Scheduled commands |
| `app/Console/Commands/BotRun.php` | Two-phase grid scan+monitor loop with graceful shutdown |
| `bootstrap/app.php` | CSRF exemption for `api/*` routes |
| `config/crypto.php` | Default config values |

## Settings (runtime-configurable from dashboard)

### Core Settings
| Key | Default | Description |
|-----|---------|-------------|
| `position_size_pct` | 1.0 | Position size as % of wallet balance (margin before leverage) |
| `max_positions` | 30 | Max concurrent open positions across all symbols |
| `leverage` | 5 | Futures leverage (multiplied with margin to get notional) |
| `dry_run` | true | Paper trading mode |
| `starting_balance` | 10000 | Simulated starting balance |
| `dry_run_fee_rate` | 0.0005 | Dry-run simulated fee rate (0.05%) |
| `watchlist` | BTCUSDT | Comma-separated symbols to scan |
| `max_position_usdt` | 150 | Max total USDT per position |
| `funding_tracking_enabled` | true | Track funding fees per position |

### Grid Settings
| Key | Default | Description |
|-----|---------|-------------|
| `grid_scan_interval` | 30 | Seconds between scan loops |
| `grid_kline_interval` | 1h | Kline candle interval for EMA analysis |
| `grid_max_hold_minutes` | 1440 | Max hold time (24h default) |
| `grid_rsi_filter` | false | Whether to apply RSI overbought/oversold filter |
| `grid_cooldown_minutes` | 1 | Minutes to wait after closing before re-entering same symbol |
| `grid_ema_fast` | 5 | Fast EMA period |
| `grid_ema_slow` | 13 | Slow EMA period |
| `grid_rsi_period` | 7 | RSI period |
| `grid_atr_period` | 14 | ATR period |
| `grid_kline_limit` | 50 | Number of candles to fetch |
| `grid_rsi_overbought` | 80 | RSI overbought threshold (reject LONG above) |
| `grid_rsi_oversold` | 20 | RSI oversold threshold (reject SHORT below) |
| `grid_max_per_symbol` | 10 | Max concurrent positions per symbol |
| `grid_spacing_pct` | 0.5 | Min % distance between grid entries on same symbol |
| `grid_take_profit_pct` | 1.68 | Default TP as % of entry (fallback) |
| `grid_stop_loss_pct` | 5.0 | Default SL as % of entry (fallback) |
| `grid_long_tp_pct` | 1.68 | Long TP as % of entry |
| `grid_long_sl_pct` | 5.0 | Long SL as % of entry |
| `grid_short_tp_pct` | 1.0 | Short TP as % of entry (tighter than longs) |
| `grid_short_sl_pct` | 2.0 | Short SL as % of entry (tighter than longs) |
| `grid_auto_add_enabled` | true | Auto-add (DCA) to losing positions when signal confirms |
| `grid_auto_add_sl_proximity_pct` | 80 | Trigger auto-add at this % of the SL distance |
| `grid_auto_add_max_layers` | 3 | Max auto-adds per position (total layers = 1 + this) |

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions (with estimated fees & net P&L), trades, summary JSON |
| GET | `/api/settings` | Current settings |
| GET | `/api/scanner` | Scan all watchlist symbols: signals, blocked reasons, entry eligibility |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Scan + auto-trade with grid checks `{auto_trade: bool}` |
| POST | `/api/open-position` | Manually open position `{symbol: string, direction: 'LONG'\|'SHORT'}` |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/add` | Add to position `{position_id: int, amount: float}` |
| POST | `/api/reverse` | Reverse position direction `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions |

## Database (MariaDB 11)

**Tables**: `scanned_coins`, `positions`, `trades`, `bot_settings`, `cache`, `jobs`, `users`

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

- **Kline cache**: In-memory 15-second TTL in WaveScanner. Prevents duplicate API calls when `analyze()` is called for the same symbol within one loop.
- **Price cache**: 10s TTL in database cache. `getPrice()` reads from cache first (1 API weight per miss).
- **Account data cache**: 10s TTL. `getAccountData()` cached as `binance:account_data`.
- **Commission rate cache**: 1h TTL per symbol. `getCommissionRate()` cached as `binance:commission:{symbol}`.
- **Funding rate cache**: 60s TTL. `getFundingRates()` cached as `binance:funding_rates`. Rates change every 8h so 60s is aggressive.
- **API budget**: ~12-36 weight/minute for 1-3 coins (vs 2,400 limit = 1.5% usage). Each `getKlines()` = 1 weight, `getPrice()` = 1 weight (cached 10s).
- **Tradability filter**: `getExchangeInfo()` cached 1 hour. `isTradable()` checks symbol status is "TRADING".
- **LOT_SIZE**: `calculateQuantity()` and `formatQuantity()` use actual stepSize from exchangeInfo.
- **MariaDB**: Proper concurrent access from all containers. No file locking issues.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: 30-second loop — two-phase grid management per symbol. Responsive shutdown via sub-second sleep chunks.

## Fee, Funding & Balance Tracking

- **Trading fees**: Calculated on position close. Both entry and exit use taker rate (market orders). Fee = `price * quantity * takerRate` per side. Deducted from realized P&L. Stored in `Trade.fees`.
- **Funding fees**: Accumulated per position at 8h settlement boundaries. Stored in `Position.funding_fee` (cumulative). Snapshotted to `Trade.funding_fee` on close. Positive = received, negative = paid.
- **Estimated fees for open positions**: Dashboard computes `estimated_fees` (entry + projected exit at current price) and `net_pnl` (unrealized P&L minus estimated fees plus funding) per position. Uses `getCommissionRate()` (cached 1h per symbol) with fallback to `dry_run_fee_rate`.
- **Net P&L formula**: `Trade.pnl` = rawPnl - tradingFees (already net of commissions). Dashboard total: `Trade::sum('pnl') + Trade::sum('funding_fee') + open positions net P&L`.
- **Fee rates**: Live mode fetches from `/fapi/v1/commissionRate` (default ~0.05% taker). Dry-run uses `dry_run_fee_rate` setting.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun balance**: `walletBalance = starting_balance + Trade::sum('pnl') + Trade::sum('funding_fee') + open Position::sum('funding_fee')`. Margin = `position_size_usdt / leverage`.
- **Dynamic sizing**: `openPosition()` calculates notional from `walletBalance x (position_size_pct / 100) x leverage` at trade time. Verifies `availableBalance >= margin` before placing orders.

## Dashboard

Four tabs: **Dashboard**, **Scanner**, **Trade History**, **Settings**.

- **Dashboard tab**:
  - **Cards**: Wallet balance, available balance, margin in use, P&L (Net), fees, funding, win rate
  - **Open Positions table**: Symbol (with side/leverage/DRY/layer badges), entry/current price, unrealized P&L, P&L%, size (notional USDT), net P&L (with fees + funding rate + accumulated funding), SL/TP, opened time, hold time, Add/Reverse/Close buttons
- **Scanner tab**:
  - **Scanner table**: Per-symbol signal data — direction (LONG/SHORT), wave state (new_wave/riding/weakening), RSI, price, open position count, entry status (allowed or blocked with reasons)
  - **Blocked reasons**: Trading paused, max total positions, wave weakening, RSI filtered, cooldown, max per symbol, direction conflict, grid spacing too close
  - **Scan button**: View-only scan — fetches latest signals without opening positions
  - **Scan + Auto Trade button**: Scans and automatically opens positions where all entry conditions pass
  - **Pause/Resume button**: Toggles `trading_paused` setting — existing positions keep running, only new entries are blocked
  - **Open Long/Short buttons**: Per-symbol manual position opening from the scanner table
  - **Manual open form**: Symbol dropdown + direction select for opening positions on any watchlist symbol
  - Auto-scans on first tab visit; no auto-refresh timer (kline API calls are heavier)
- **Trade History tab**: Symbol (with side/leverage/DRY badge), entry/exit price, P&L, P&L%, size, fees (with funding breakdown), net P&L, close reason, opened/closed time
- **Settings tab**: All runtime-configurable settings
- **Formatting**: Thousand separators on all dollar amounts ($66,591.10), subscript notation for micro-prices, inline color styling for P&L values

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After major changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
- **Dual SL/TP checking**: Bot checks SL/TP in code every 30 seconds AND places exchange-side SL/TP orders as safety net. If a Binance SL/TP triggers between bot checks, the bot will detect it on next cycle and reconcile. Exchange orders use `reduceOnly: true` with position-specific quantities.
- **Live trading readiness**: Per-order cancellation (`cancelOrder`) ensures closing one grid position doesn't destroy SL/TP orders for sibling positions. Fail-safe ensures no unprotected positions exist. Remaining consideration: if Binance SL/TP triggers independently, DB may briefly be out of sync until next check cycle.
