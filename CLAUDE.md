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
- `ExchangeInterface` — contract for all exchange operations
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB
- DryRun balance = `starting_balance - allocated_usdt + realized_pnl` (from Position/Trade tables)

### Core Flow (Trend Strategy — default)
1. **TrendScanner::scan()** — Pre-filters tickers by volume/range, fetches 5m klines for top candidates, computes EMA/RSI/MACD, scores signals 0-100
2. **TradingEngine::openPosition()** — Opens LONG or SHORT based on signal direction
3. **TradingEngine::monitorPositions()** — Checks SL/TP/trailing/expiry (direction-aware)
4. **TradingEngine::closePosition()** — Closes position via closeLong()/closeShort(), records Trade

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

### Trend Detection Logic (TrendScanner)
- **Pre-filter**: Min 24h volume ($5M), tradable, min 2% intraday range
- **Candidates**: Top 50 by absolute price change
- **Data**: 5-minute klines (100 candles = ~8 hours)
- **Indicators**: EMA(9/21/50), RSI(14), MACD(12,26,9), volume ratio
- **Signal scoring** (0-100): EMA cross (30pts), RSI zone (20pts), MACD histogram (25pts), volume (15pts), trend alignment (10pts)
- **Min score**: `trend_min_score` (default 60) to open a trade
- **Direction**: LONG if EMA9 > EMA21, SHORT if EMA9 < EMA21
- **Signal expiry**: 4 hours

### Position Management (bidirectional)
- **LONG entry**: `openLong()` — SL = entry * (1 - sl_pct/100), TP = entry * (1 + tp_pct/100)
- **SHORT entry**: `openShort()` — SL = entry * (1 + sl_pct/100), TP = entry * (1 - tp_pct/100)
- **P&L**: LONG = (current - entry) * qty, SHORT = (entry - current) * qty
- **Trailing stop**: Direction-aware — tracks best price (highest for LONG, lowest for SHORT)
- **Expiry**: `max_hold_hours` (default 24h for pump, 4h for trend)

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/TrendScanner.php` | Trend detection via technical indicators |
| `app/Services/TechnicalAnalysis.php` | EMA, RSI, MACD, ATR calculations |
| `app/Services/PumpScanner.php` | Pump detection + reversal checking |
| `app/Services/TradingEngine.php` | Position open/close/monitor (LONG + SHORT) |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation |
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
| `position_size_usdt` | 100 | USDT per trade |
| `max_positions` | 5 | Max concurrent open positions |
| `stop_loss_pct` | 5 | Stop loss % above entry |
| `take_profit_pct` | 10 | Take profit % below entry |
| `max_hold_hours` | 24 | Auto-close after N hours |
| `leverage` | 10 | Futures leverage |
| `dry_run` | true | Paper trading mode |
| `starting_balance` | 10000 | Simulated starting balance |
| `min_price_change_pct` | 15 | Min 24h price change to detect pump |
| `min_volume_multiplier` | 3 | Min volume vs 7-day average |
| `reversal_drop_pct` | 3 | Min drop from peak to confirm reversal |
| `min_volume_usdt` | 5000000 | Min 24h USDT volume to consider coin |
| `strategy` | trend | Active strategy: 'pump' or 'trend' |
| `trend_scan_interval` | 120 | Seconds between trend scans |
| `trend_min_score` | 60 | Min score (0-100) to open a trend trade |
| `trend_max_hold_hours` | 4 | Max hold time for trend trades |
| `trend_stop_loss_pct` | 2.5 | Trend stop loss % |
| `trend_take_profit_pct` | 5 | Trend take profit % |
| `trend_trailing_stop_activation_pct` | 1.5 | Trend trailing stop activation % |
| `trend_trailing_stop_pct` | 1.5 | Trend trailing stop distance % |

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions, trades, signals, summary JSON |
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
- **Batch pricing**: `getPrices(array $symbols)` fetches all prices in 1 API call. Used by `checkReversals()`, `monitorPositions()`, and dashboard `data()`.
- **Tradability filter**: `getExchangeInfo()` cached 1 hour. `isTradable()` checks symbol status is "TRADING". Skips delisted/frozen coins in scan and before opening trades.
- **LOT_SIZE**: `calculateQuantity()` and `formatQuantity()` use actual stepSize from exchangeInfo instead of hardcoded rounding.
- **Min volume filter**: `min_volume_usdt` setting (default $5M) skips low-liquidity coins before any pump evaluation.
- **MariaDB**: Proper concurrent access from all containers. No file locking issues.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: Scans every 300s, monitors positions every 60s (decoupled intervals).

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **Cold-start volume**: First scan for any coin has no volume history, so volume multiplier = 1.0. Extreme pumps (>2x threshold) bypass volume check to handle this
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
