# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) auto-trading bot for Binance Futures using the **Wave Rider** scalping strategy. Supports both LONG and SHORT positions. Scans every 5 seconds on 1-minute candles. Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck)
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, unified 5-second scan+monitor loop)
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

### Core Flow (Wave Rider — active strategy)
1. **BotRun** — Unified 5-second loop: for each watchlist symbol, analyze wave then manage position
2. **WaveScanner::analyze()** — Fetches 1m klines (cached 3s), computes EMA(5/13), RSI(7), ATR(14), classifies wave state
3. **TradingEngine::openPosition()** — Opens LONG or SHORT with ATR-based SL/TP on `new_wave` signal
4. **Wave break check** — If EMA alignment flips against position, close immediately (CloseReason::WaveBreak)
5. **TradingEngine::checkPosition()** — Checks SL/TP/trailing/expiry
6. **TradingEngine::checkDCA()** — Adds layers when price moves 0.5x ATR against entry (if wave intact)
7. **TradingEngine::closePosition()** — Closes position via closeLong()/closeShort(), records Trade with fees

### Wave Detection Logic (WaveScanner)
- **Watchlist-based**: Scans 1-3 pre-selected coins (default: BTCUSDT)
- **Data**: 1-minute klines (30 candles)
- **Indicators**: EMA(5/13), RSI(7), ATR(14)
- **Direction**: LONG if EMA5 > EMA13, SHORT if EMA5 < EMA13
- **Fresh cross**: EMA crossed between previous and current candle
- **RSI filter**: Reject LONG if RSI > 80, reject SHORT if RSI < 20
- **ATR sanity**: Reject if ATR < 0.05% of price (dead market)
- **Wave states**:
  - `new_wave` — fresh EMA cross + RSI confirms → entry signal
  - `riding` — EMA aligned, no fresh cross → hold
  - `weakening` — EMA gap shrinking 3 consecutive candles → prepare to exit
- **Signals are ephemeral** — WaveSignal DTOs, never stored in DB
- **In-memory kline cache** — 3-second TTL prevents duplicate API calls within same loop iteration
- **isWaveIntact()** — Checks EMA alignment still matches position direction (used for DCA validation and wave-break exit)

### Legacy Strategies (kept for backward compatibility)
- **Trend Following** (`TrendScanner`) — EMA(9/21/50), RSI(14), MACD, ATR on 5m klines. Not actively used.
- **Pump & Dump** (`PumpScanner`) — 24h price/volume spike detection. Not actively used.

### Position Management (bidirectional)
- **LONG entry**: `openLong()` — ATR-based SL/TP, fallback to fixed %
- **SHORT entry**: `openShort()` — ATR-based SL/TP, fallback to fixed %
- **Margin check**: Before opening, verifies `availableBalance >= positionSize / leverage`
- **P&L**: LONG = (current - entry) * qty, SHORT = (entry - current) * qty
- **Fees**: Deducted from P&L on close — entry fee + exit fee (taker rate on notional). Stored in `Trade.fees` column.
- **Expiry**: `wave_max_hold_minutes` (default 30 minutes)

### ATR-Based SL/TP (Wave Rider)
- **SL**: 1.0x ATR from entry price (configurable via `wave_sl_atr_multiplier`)
- **TP**: 0.5x ATR from entry (configurable via `wave_tp_atr_multiplier`) — quick profit capture
- **Fallback**: If ATR < 0.1% of price, uses 1% SL / 0.5% TP
- **Trailing stop**: Activates at 0.3x ATR profit, trails at 0.3x ATR distance (configurable)
- **No breakeven** — trailing at 0.3x ATR activates before old breakeven would

### DCA (Dollar Cost Averaging)
- **Purpose**: Build positions when price moves against entry but wave still intact
- **Layers**: Up to 3 (configurable via `dca_max_layers`)
- **Trigger**: Price moves >= 0.5x ATR * layer_count against average entry (configurable via `wave_dca_trigger_atr`)
- **Layer sizing**: Layer 1 = 100%, Layer 2 = 75%, Layer 3 = 50% of base `position_size_usdt`
- **Validation**: Before each DCA layer, `WaveScanner::isWaveIntact()` checks EMA5/13 still agrees
- **Cap**: Total position cannot exceed `max_position_usdt` (default 150 USDT)
- **On DCA**: Recalculates weighted average entry, updates quantity, recomputes SL/TP from new average
- **Exchange orders**: Old SL/TP orders cancelled and replaced with new levels
- **Position fields**: `layer_count` (1-3), `atr_value` (decimal) stored on Position model

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/WaveScanner.php` | Wave detection: EMA(5/13) cross, RSI(7), ATR(14) on 1m candles |
| `app/Services/WaveAnalysis.php` | Ephemeral DTO: wave direction, state, RSI, ATR, price |
| `app/Services/WaveSignal.php` | Lightweight DTO for openPosition() interface compatibility |
| `app/Services/TechnicalAnalysis.php` | EMA, RSI, MACD, ATR calculations |
| `app/Services/TradingEngine.php` | Position open/close/checkPosition/checkDCA |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/Exchange/ExchangeInterface.php` | Contract: 19 methods including account data & commission rates |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT, account data, commission rates) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation (margin-based balance, fee simulation) |
| `app/Http/Controllers/DashboardController.php` | All API endpoints |
| `resources/views/dashboard.blade.php` | Single-page dark theme dashboard |
| `routes/web.php` | Route definitions |
| `routes/console.php` | Scheduled commands |
| `app/Console/Commands/BotRun.php` | Unified 5-second scan+monitor loop with graceful shutdown |
| `bootstrap/app.php` | CSRF exemption for `api/*` routes |
| `config/crypto.php` | Default config values |
| `app/Services/TrendScanner.php` | Legacy: trend detection (not actively used) |
| `app/Services/PumpScanner.php` | Legacy: pump detection (not actively used) |

## Settings (runtime-configurable from dashboard)

### Core Settings
| Key | Default | Description |
|-----|---------|-------------|
| `position_size_usdt` | 50 | USDT per trade (notional, leveraged) |
| `max_positions` | 2 | Max concurrent open positions |
| `leverage` | 5 | Futures leverage |
| `dry_run` | true | Paper trading mode |
| `starting_balance` | 10000 | Simulated starting balance |
| `dry_run_fee_rate` | 0.0005 | Dry-run simulated fee rate (0.05%) |
| `watchlist` | BTCUSDT | Comma-separated symbols to scan (1-3 recommended) |
| `max_position_usdt` | 150 | Max total USDT per coin incl. DCA layers |
| `dca_enabled` | true | Enable/disable DCA layers |
| `dca_max_layers` | 3 | Max DCA layers per position |
| `strategy` | wave | Active strategy: 'wave', 'trend', or 'pump' |

### Wave Settings
| Key | Default | Description |
|-----|---------|-------------|
| `wave_scan_interval` | 5 | Seconds between scan loops |
| `wave_ema_fast` | 5 | Fast EMA period |
| `wave_ema_slow` | 13 | Slow EMA period |
| `wave_rsi_period` | 7 | RSI period |
| `wave_atr_period` | 14 | ATR period |
| `wave_kline_limit` | 30 | Number of 1m candles to fetch |
| `wave_sl_atr_multiplier` | 1.0 | Stop loss distance in ATR multiples |
| `wave_tp_atr_multiplier` | 0.5 | Take profit distance in ATR multiples |
| `wave_trailing_activation_atr` | 0.3 | Trailing stop activation in ATR multiples |
| `wave_trailing_distance_atr` | 0.3 | Trailing stop distance in ATR multiples |
| `wave_max_hold_minutes` | 30 | Max hold time before forced close |
| `wave_dca_trigger_atr` | 0.5 | DCA trigger distance in ATR multiples |
| `wave_rsi_overbought` | 80 | RSI overbought threshold (reject LONG above) |
| `wave_rsi_oversold` | 20 | RSI oversold threshold (reject SHORT below) |

### Legacy Settings (pump/trend — kept for backward compat)
| Key | Default | Description |
|-----|---------|-------------|
| `stop_loss_pct` | 8 | Stop loss % (pump strategy) |
| `take_profit_pct` | 15 | Take profit % (pump strategy) |
| `max_hold_hours` | 24 | Auto-close after N hours (pump) |
| `trend_scan_interval` | 120 | Seconds between trend scans |
| `trend_min_score` | 75 | Min score to open a trend trade |
| `trend_max_hold_hours` | 2 | Max hold time for trend trades |

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions, trades, wave status, summary JSON |
| GET | `/api/settings` | Current settings |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Manual wave scan `{auto_trade: bool}` |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions/signals |

## Database (MariaDB 11)

**Tables**: `scanned_coins`, `pump_signals`, `trend_signals`, `positions`, `trades`, `bot_settings`, `cache`, `jobs`, `users`

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut
- `SignalStatus`: Detected, ReversalConfirmed, Traded, Expired, Skipped
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual, WaveBreak

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

- **Kline cache**: In-memory 3-second TTL in WaveScanner. Prevents duplicate API calls when `analyze()` and `isWaveIntact()` need klines for the same symbol in the same loop.
- **Price cache**: 10s TTL in database cache. `getPrice()` reads from cache first (1 API weight per miss).
- **Account data cache**: 10s TTL. `getAccountData()` cached as `binance:account_data`.
- **Commission rate cache**: 1h TTL per symbol. `getCommissionRate()` cached as `binance:commission:{symbol}`.
- **API budget**: ~12-36 weight/minute for 1-3 coins (vs 2,400 limit = 1.5% usage). Each `getKlines()` = 1 weight, `getPrice()` = 1 weight (cached 10s).
- **Tradability filter**: `getExchangeInfo()` cached 1 hour. `isTradable()` checks symbol status is "TRADING".
- **LOT_SIZE**: `calculateQuantity()` and `formatQuantity()` use actual stepSize from exchangeInfo.
- **MariaDB**: Proper concurrent access from all containers. No file locking issues.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: Unified 5-second loop — scan + monitor in one pass per symbol. Responsive shutdown via sub-second sleep chunks.

## Fee & Balance Tracking

- **Trading fees**: Calculated on position close. Both entry and exit use taker rate (market orders). Fee = `price * quantity * takerRate` per side. Deducted from realized P&L.
- **Fee rates**: Live mode fetches from `/fapi/v1/commissionRate` (default ~0.05% taker). Dry-run uses `dry_run_fee_rate` setting.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun margin**: Uses `position_size_usdt / leverage` (margin), not full notional. With 5x leverage, a $50 position locks $10 margin.
- **Margin check**: `openPosition()` verifies `availableBalance >= positionSize / leverage` before placing orders.

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After strategy changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
