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

### Core Flow (Grid Trading)
1. **BotRun** — 30-second loop: for each watchlist symbol, runs two phases per symbol
2. **Phase 1 — Manage positions**: Fetches ALL open positions for the symbol, calls `checkPosition()` on each (SL/TP/expiry checks)
3. **Phase 2 — Evaluate new grid entry**: Checks EMA alignment, cooldown, per-symbol count, grid spacing, then opens position if all pass
4. **WaveScanner::analyze()** — Fetches klines (1h default, cached 15s), computes EMA(5/13), RSI(7), ATR(14), classifies trend state
5. **TradingEngine::openPosition()** — Calculates position size dynamically (% of wallet balance x leverage), opens LONG or SHORT with direction-specific fixed-% SL/TP
6. **TradingEngine::checkPosition()** — Checks SL/TP/expiry, closes if hit
7. **TradingEngine::closePosition()** — Closes via closeLong()/closeShort(), records Trade with fees

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
- **Margin check**: Before opening, verifies `availableBalance >= notional / leverage`
- **P&L**: LONG = (current - entry) * qty, SHORT = (entry - current) * qty
- **Fees**: Deducted from P&L on close — entry fee + exit fee (taker rate on notional). Stored in `Trade.fees` column.
- **Expiry**: `grid_max_hold_minutes` (default 1440 min / 24h)

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/WaveScanner.php` | Market scanner: EMA(5/13) cross, RSI(7), ATR(14) on 1h candles |
| `app/Services/WaveAnalysis.php` | Ephemeral DTO: direction, state, RSI, ATR, price |
| `app/Services/WaveSignal.php` | Lightweight DTO for openPosition() interface |
| `app/Services/TechnicalAnalysis.php` | EMA, RSI, MACD, ATR calculations |
| `app/Services/TradingEngine.php` | Position open/close/checkPosition (grid-aware) |
| `app/Services/Settings.php` | DB-first settings with config fallback |
| `app/Services/Exchange/ExchangeInterface.php` | Contract: 19 methods including account data & commission rates |
| `app/Services/Exchange/BinanceExchange.php` | Binance Futures API (LONG + SHORT, account data, commission rates) |
| `app/Services/Exchange/DryRunExchange.php` | Paper trading simulation (margin-based balance, fee simulation) |
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

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions (with estimated fees & net P&L), trades, summary JSON |
| GET | `/api/settings` | Current settings |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Manual scan with grid checks `{auto_trade: bool}` |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions |

## Database (MariaDB 11)

**Tables**: `scanned_coins`, `positions`, `trades`, `bot_settings`, `cache`, `jobs`, `users`

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual

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
- **API budget**: ~12-36 weight/minute for 1-3 coins (vs 2,400 limit = 1.5% usage). Each `getKlines()` = 1 weight, `getPrice()` = 1 weight (cached 10s).
- **Tradability filter**: `getExchangeInfo()` cached 1 hour. `isTradable()` checks symbol status is "TRADING".
- **LOT_SIZE**: `calculateQuantity()` and `formatQuantity()` use actual stepSize from exchangeInfo.
- **MariaDB**: Proper concurrent access from all containers. No file locking issues.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: 30-second loop — two-phase grid management per symbol. Responsive shutdown via sub-second sleep chunks.

## Fee & Balance Tracking

- **Trading fees**: Calculated on position close. Both entry and exit use taker rate (market orders). Fee = `price * quantity * takerRate` per side. Deducted from realized P&L.
- **Estimated fees for open positions**: Dashboard computes `estimated_fees` (entry + projected exit at current price) and `net_pnl` (unrealized P&L minus estimated fees) per position. Uses `getCommissionRate()` (cached 1h per symbol) with fallback to `dry_run_fee_rate`.
- **Fee rates**: Live mode fetches from `/fapi/v1/commissionRate` (default ~0.05% taker). Dry-run uses `dry_run_fee_rate` setting.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun margin**: Uses `position_size_usdt / leverage` (margin), not full notional. With 10x leverage, a $1,000 notional position locks $100 margin.
- **Dynamic sizing**: `openPosition()` calculates notional from `walletBalance x (position_size_pct / 100) x leverage` at trade time. Verifies `availableBalance >= margin` before placing orders.

## Dashboard

- **Cards**: Wallet balance, available balance, margin in use, combined/realized/unrealized P&L, total fees, win rate
- **Open Positions table**: Symbol (with side/leverage/DRY badge), entry/current price, unrealized P&L, P&L%, size (notional USDT), net P&L (with fees), SL/TP, opened time, hold time, close button
- **Scan Now button**: Manual trigger for grid scan with auto-trade (respects grid spacing and cooldown)
- **Trade History table**: Symbol (with side/leverage/DRY badge), entry/exit price, P&L, P&L%, size, fees, net P&L, close reason, opened/closed time
- **Settings tab**: All runtime-configurable settings
- **Formatting**: Thousand separators on all dollar amounts ($66,591.10), subscript notation for micro-prices, inline color styling for P&L values

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After major changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
- **cancelOrders limitation**: `cancelOrders($symbol)` cancels ALL orders for a symbol. In DryRun mode this is harmless (SL/TP checked in code). For live trading with grid, would need per-order cancellation (store individual SL/TP order IDs per position).
