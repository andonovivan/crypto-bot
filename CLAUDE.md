# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) auto-trading bot for Binance Futures with two strategies: **Trend Following** (default) and **Pump & Dump**. Supports both LONG and SHORT positions. Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck)
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, scans every 5 min, monitors every 1 min)
- **scheduler** — Laravel scheduler (`schedule:run` loop)
- App runs migrations; bot/scheduler wait for app to start first

### Exchange Abstraction
- `ExchangeInterface` — contract for all exchange operations (19 methods)
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB
- **Account data**: `getAccountData()` returns wallet balance, available balance, unrealized profit, margin balance, position margin, maintenance margin
  - BinanceExchange: calls `/fapi/v2/account` (cached 10s)
  - DryRunExchange: calculates from DB — margin = `position_size_usdt / leverage` (not full notional)
- **Commission rates**: `getCommissionRate($symbol)` returns maker/taker rates
  - BinanceExchange: calls `/fapi/v1/commissionRate` (cached 1h per symbol)
  - DryRunExchange: uses `dry_run_fee_rate` setting (default 0.05%)
- `getBalance()` delegates to `getAccountData()['availableBalance']` in both implementations

### Core Flow (Trend Strategy — default, "Focused DCA Trend")
1. **TrendScanner::scan()** — Scans watchlist (5-10 liquid coins), fetches 5m klines, computes EMA/RSI/MACD/ATR, scores signals with hard requirements
2. **TradingEngine::openPosition()** — Opens LONG or SHORT with ATR-based SL/TP, stores `atr_value` and `layer_count`
3. **TradingEngine::monitorPositions()** — Checks SL/TP/breakeven/trailing/expiry, then runs DCA check for eligible positions
4. **TradingEngine::checkDCA()** — Adds layers when price moves against entry by ATR multiples (up to 3 layers)
5. **TradingEngine::closePosition()** — Closes position via closeLong()/closeShort(), records Trade with fees

### Core Flow (Pump Strategy — legacy)
1. **PumpScanner::scan()** — Fetches all futures tickers, detects pumps
2. **PumpScanner::checkReversals()** — Monitors detected signals for price drop from peak
3. **TradingEngine::openShort()** — Opens short when reversal confirmed (wrapper around openPosition)
4. **TradingEngine::monitorPositions()** — Checks SL/TP/expiry on open positions
5. **TradingEngine::closePosition()** — Closes position, records Trade

### Pump Detection Logic
- **Price threshold**: `min_price_change_pct` (default 15%) — 24h price change
- **Volume threshold**: `min_volume_multiplier` (default 3x) — current volume vs 7-day EMA average
- **Extreme pump bypass**: If price change >= 2x threshold (>30%), volume check is skipped (handles cold-start where EMA hasn't built up)
- **Volume EMA**: `avg_volume_7d` uses exponential moving average with alpha=1/7, stored in `scanned_coins` table
- **First-scan coins**: Return volume multiplier 1.0 (conservative, no false positives)

### Reversal Confirmation
- Signal status transitions: Detected -> ReversalConfirmed -> Traded/Expired
- Reversal confirmed when drop from peak >= `reversal_drop_pct` (default 3%)
- Peak price tracked and updated if price goes higher

### Trend Detection Logic (TrendScanner — "Focused DCA Trend")
- **Watchlist-based**: Scans only pre-selected liquid coins (default: BTCUSDT, ETHUSDT, SOLUSDT, XRPUSDT, DOGEUSDT)
- **Data**: 5-minute klines (100 candles = ~8 hours)
- **Indicators**: EMA(9/21/50), RSI(14), MACD(12,26,9), ATR(14), volume ratio
- **Hard requirements** (must all pass):
  - RSI not extreme (15-85 range)
  - MACD histogram must confirm direction (positive for LONG, negative for SHORT)
- **Signal scoring** (0-100): EMA cross (30pts), RSI zone (20pts), MACD histogram (25pts), volume (15pts), trend alignment (10pts)
- **Min score**: `trend_min_score` (default 75) to open a trade
- **Direction**: LONG if EMA9 > EMA21, SHORT if EMA9 < EMA21
- **ATR**: Returned with each signal for volatility-based SL/TP
- **Signal expiry**: 4 hours
- **Alignment check**: `isAlignmentValid()` verifies EMA alignment before DCA layers

### Position Management (bidirectional)
- **LONG entry**: `openLong()` — ATR-based SL/TP (see below), fallback to fixed %
- **SHORT entry**: `openShort()` — ATR-based SL/TP (see below), fallback to fixed %
- **Margin check**: Before opening, verifies `availableBalance >= positionSize / leverage`
- **P&L**: LONG = (current - entry) * qty, SHORT = (entry - current) * qty
- **Fees**: Deducted from P&L on close — entry fee + exit fee (taker rate on notional). Stored in `Trade.fees` column.
- **Expiry**: `max_hold_hours` (default 24h for pump, 2h for trend)

### ATR-Based SL/TP
- **SL**: 1.5x ATR from entry price (wider = fewer whipsaw stops)
- **TP**: 1.0x ATR from entry (2.0x ATR for strong signals with score >= 90)
- **Fallback**: If ATR < 0.1% of price, uses fixed % from settings
- **Breakeven stop**: When profit >= 0.5x ATR, SL moves to entry price (locks in zero-loss)
- **ATR trailing stop**: Activates at 1.0x ATR profit, trails at 0.5x ATR distance
- **Fallback trailing**: If no ATR stored, uses %-based trailing from settings

### DCA (Dollar Cost Averaging)
- **Purpose**: Build positions when price moves against entry but signal remains valid
- **Layers**: Up to 3 (configurable via `dca_max_layers`)
- **Trigger**: Price moves >= 1x ATR * layer_count against average entry
- **Layer sizing**: Layer 1 = 100%, Layer 2 = 75%, Layer 3 = 50% of base `position_size_usdt`
- **Validation**: Before each DCA layer, `TrendScanner::isAlignmentValid()` checks EMA9/21 still agrees
- **Cap**: Total position cannot exceed `max_position_usdt` (default 150 USDT)
- **On DCA**: Recalculates weighted average entry, updates quantity, recomputes SL/TP from new average
- **Exchange orders**: Old SL/TP orders cancelled and replaced with new levels
- **Position fields**: `layer_count` (1-3), `atr_value` (decimal) stored on Position model

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/TrendScanner.php` | Trend detection via technical indicators |
| `app/Services/TechnicalAnalysis.php` | EMA, RSI, MACD, ATR calculations |
| `app/Services/PumpScanner.php` | Pump detection + reversal checking |
| `app/Services/TradingEngine.php` | Position open/close/monitor (LONG + SHORT) |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/Exchange/ExchangeInterface.php` | Contract: 19 methods including account data & commission rates |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT, account data, commission rates) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation (margin-based balance, fee simulation) |
| `app/Http/Controllers/DashboardController.php` | All API endpoints |
| `resources/views/dashboard.blade.php` | Single-page dark theme dashboard |
| `routes/web.php` | Route definitions |
| `routes/console.php` | Scheduled commands |
| `app/Console/Commands/BotRun.php` | Continuous loop with graceful shutdown |
| `bootstrap/app.php` | CSRF exemption for `api/*` routes |
| `config/crypto.php` | Default config values |

## Settings (runtime-configurable from dashboard)

| Key | Default | Description |
|-----|---------|-------------|
| `position_size_usdt` | 50 | USDT per trade (notional, leveraged) |
| `max_positions` | 5 | Max concurrent open positions |
| `stop_loss_pct` | 8 | Stop loss % (pump strategy) |
| `take_profit_pct` | 15 | Take profit % (pump strategy) |
| `max_hold_hours` | 24 | Auto-close after N hours |
| `leverage` | 5 | Futures leverage |
| `dry_run` | true | Paper trading mode |
| `starting_balance` | 10000 | Simulated starting balance |
| `dry_run_fee_rate` | 0.0005 | Dry-run simulated fee rate (0.05%) |
| `min_price_change_pct` | 15 | Min 24h price change to detect pump |
| `min_volume_multiplier` | 3 | Min volume vs 7-day average |
| `reversal_drop_pct` | 5 | Min drop from peak to confirm reversal |
| `min_volume_usdt` | 5000000 | Min 24h USDT volume to consider coin |
| `watchlist` | BTCUSDT,ETHUSDT,SOLUSDT,XRPUSDT,DOGEUSDT | Comma-separated symbols to scan |
| `max_position_usdt` | 150 | Max total USDT per coin incl. DCA layers |
| `dca_enabled` | true | Enable/disable DCA layers |
| `dca_max_layers` | 3 | Max DCA layers per position |
| `strategy` | trend | Active strategy: 'pump' or 'trend' |
| `trend_scan_interval` | 120 | Seconds between trend scans |
| `trend_min_score` | 75 | Min score (0-100) to open a trend trade |
| `trend_max_hold_hours` | 2 | Max hold time for trend trades |
| `trend_stop_loss_pct` | 2.5 | Trend stop loss % (ATR fallback) |
| `trend_take_profit_pct` | 5 | Trend take profit % (ATR fallback) |
| `trend_trailing_stop_activation_pct` | 1.5 | Trend trailing stop activation % (ATR fallback) |
| `trend_trailing_stop_pct` | 1.5 | Trend trailing stop distance % (ATR fallback) |

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions, trades, signals, summary (balance/margin/fees/P&L) JSON |
| GET | `/api/settings` | Current settings |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Manual scan `{auto_trade: bool}` |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions/signals |

## Database (MariaDB 11)

**Tables**: `scanned_coins`, `pump_signals`, `trend_signals`, `positions`, `trades`, `bot_settings`, `cache`, `jobs`, `users`

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut
- `SignalStatus`: Detected, ReversalConfirmed, Traded, Expired, Skipped
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual

## Development Commands

```bash
./develop up -d          # Start containers
./develop down           # Stop containers
./develop build          # Rebuild containers
./develop logs [-f]      # View logs
./develop art <cmd>      # Run artisan command
./develop bash           # Shell into app container
./develop scan           # Manual scan
./develop monitor        # Manual position monitor
./develop status         # Show bot status
```

## Performance & Reliability

- **Price cache**: 10s TTL in database cache. `getFuturesTickers()` populates cache for all symbols. `getPrices()` and `getPrice()` read from cache first.
- **Account data cache**: 10s TTL. `getAccountData()` cached as `binance:account_data`. Dashboard refreshes every 10s.
- **Commission rate cache**: 1h TTL per symbol. `getCommissionRate()` cached as `binance:commission:{symbol}`.
- **Batch pricing**: `getPrices(array $symbols)` fetches all prices in 1 API call. Used by `checkReversals()`, `monitorPositions()`, and dashboard `data()`.
- **Tradability filter**: `getExchangeInfo()` cached 1 hour. `isTradable()` checks symbol status is "TRADING". Skips delisted/frozen coins in scan and before opening trades.
- **LOT_SIZE**: `calculateQuantity()` and `formatQuantity()` use actual stepSize from exchangeInfo instead of hardcoded rounding.
- **Min volume filter**: `min_volume_usdt` setting (default $5M) skips low-liquidity coins before any pump evaluation.
- **MariaDB**: Proper concurrent access from all containers. No file locking issues.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: Scans every 300s, monitors positions every 60s (decoupled intervals).

## Fee & Balance Tracking

- **Trading fees**: Calculated on position close. Both entry and exit use taker rate (market orders). Fee = `price * quantity * takerRate` per side. Deducted from realized P&L.
- **Fee rates**: Live mode fetches from `/fapi/v1/commissionRate` (default ~0.05% taker). Dry-run uses `dry_run_fee_rate` setting.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun margin**: Uses `position_size_usdt / leverage` (margin), not full notional. With 5x leverage, a $50 position locks $10 margin.
- **Margin check**: `openPosition()` verifies `availableBalance >= positionSize / leverage` before placing orders.
- **Historical trades**: Trades created before fee tracking have `fees = 0`. The `total_fees` sum only reflects trades created after the feature was added.

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **Cold-start volume**: First scan for any coin has no volume history, so volume multiplier = 1.0. Extreme pumps (>2x threshold) bypass volume check to handle this
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
