# CLAUDE.md — Crypto Pump & Dump Short Bot

## Project Overview

Laravel 13 (PHP 8.4) auto-trading bot that detects pump & dump coins on Binance Futures and opens short positions to profit from the dump. Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **app** — Dashboard web server (port 8090, `php artisan serve`)
- **bot** — Continuous scan loop (`bot:run`, scans every 5 min, monitors every 1 min)
- **scheduler** — Laravel scheduler (`schedule:run` loop)
- Shared volume: SQLite database + storage/logs

### Exchange Abstraction
- `ExchangeInterface` — contract for all exchange operations
- `BinanceExchange` — real Binance Futures API (HMAC-SHA256 signed requests)
- `DryRunExchange` — wraps real exchange for market data, simulates trades in DB
- DryRun balance = `starting_balance - allocated_usdt + realized_pnl` (from Position/Trade tables)

### Core Flow
1. **PumpScanner::scan()** — Fetches all futures tickers, detects pumps
2. **PumpScanner::checkReversals()** — Monitors detected signals for price drop from peak
3. **TradingEngine::openShort()** — Opens short when reversal confirmed
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

### Short Position Management
- **Entry**: At current price when reversal confirmed
- **Stop Loss**: entry_price * (1 + stop_loss_pct/100) — default 5% above entry
- **Take Profit**: entry_price * (1 - take_profit_pct/100) — default 10% below entry
- **Expiry**: `max_hold_hours` (default 24h)
- **P&L for shorts**: `(entry_price - current_price) * quantity`

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/PumpScanner.php` | Pump detection + reversal checking |
| `app/Services/TradingEngine.php` | Position open/close/monitor |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API |
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

## Database (SQLite)

**Tables**: `scanned_coins`, `pump_signals`, `positions`, `trades`, `bot_settings`

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
- **SQLite WAL mode**: `busy_timeout=5000`, `journal_mode=wal` in config/database.php. Prevents "database is locked" across 3 containers.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: Scans every 300s, monitors positions every 60s (decoupled intervals).

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **Cold-start volume**: First scan for any coin has no volume history, so volume multiplier = 1.0. Extreme pumps (>2x threshold) bypass volume check to handle this
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
