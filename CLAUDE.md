# CLAUDE.md — Crypto Trading Bot

## Project Overview

Laravel 13 (PHP 8.4) auto-trading bot for Binance Futures with two strategies: **Wave Rider** (ATR-based scalping) and **Staircase** (fixed-% TP trend riding). Supports both LONG and SHORT positions. Scans every 30 seconds on 15-minute candles (configurable). Runs in Docker on port 8090. Currently in **DRY_RUN mode** (no real trades).

## Architecture

### Docker Services (docker-compose.yml)
- **db** — MariaDB 11 (persistent volume `dbdata`, healthcheck)
- **app** — Dashboard web server (port 8090, runs migrations on startup)
- **bot** — Continuous scan loop (`bot:run`, unified 30-second scan+monitor loop)
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

### Core Flow (Wave Rider)
1. **BotRun** — Unified 30-second loop: for each watchlist symbol, analyze wave then manage position
2. **WaveScanner::analyze()** — Fetches klines at strategy-specific interval (wave: 15m, staircase: 1h, cached 15s), computes EMA(5/13), RSI(7), ATR(14), classifies wave state
3. **TradingEngine::openPosition()** — Calculates position size dynamically (% of wallet balance × leverage), opens LONG or SHORT with ATR-based SL/TP on `new_wave` signal
4. **Wave break check** — If EMA alignment flips against position, close immediately (CloseReason::WaveBreak). Disabled for staircase strategy.
5. **TradingEngine::checkPosition()** — Checks SL/TP/trailing/expiry
6. **TradingEngine::checkDCA()** — Adds layers when price moves 0.5x ATR against entry (if wave intact). Disabled for staircase.
7. **TradingEngine::closePosition()** — Closes position via closeLong()/closeShort(), records Trade with fees

### Wave Detection Logic (WaveScanner)
- **Watchlist-based**: Scans 1-3 pre-selected coins (default: BTCUSDT)
- **Data**: Strategy-specific kline interval (wave: `wave_kline_interval` default 15m, staircase: `staircase_kline_interval` default 1h), 50 candles
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
- **In-memory kline cache** — 15-second TTL prevents duplicate API calls within same loop iteration
- **isWaveIntact()** — Checks EMA alignment still matches position direction (used for DCA validation and wave-break exit)

### Staircase Strategy (alternative to Wave Rider)
- **Concept**: Fixed-% TP scalping that rides trends in "steps" — TP hit → close → re-enter after cooldown if trend intact → repeat
- **Entry**: Opens when EMAs are aligned (`new_wave` OR `riding` state), not just on fresh crosses
- **TP**: Fixed percentage of entry price (default 1.68%) — not ATR-based
- **SL**: Fixed percentage of entry price (default 5.0%) — hard stop to prevent catastrophic losses
- **No DCA**: DCA is disabled — avoids "averaging down" into losing positions
- **No trailing stop**: Fixed TP is the sole exit mechanism (plus breakeven protection)
- **RSI filter**: Off by default (configurable via `staircase_rsi_filter`)
- **Breakeven protection**: Still active — moves SL to entry once fees are covered
- **No wave break exit**: Wave break is disabled for staircase — positions held until TP/SL/expiry. This matches the OKX strategy where positions were held for hours, not closed on EMA wobbles.
- **Cooldown**: After closing a position, waits `staircase_cooldown_minutes` (default 30) before re-entering the same symbol. Prevents churn from rapid close→re-enter cycles.
- **Kline interval**: Uses separate `staircase_kline_interval` (default 1h) for EMA analysis. Longer candles = more stable trend signal, fewer false crosses. EMA(5/13) on 1h = 5h/13h lookback.
- **Max hold**: 1440 minutes (24h) by default
- **Origin**: Reverse-engineered from OKX position history (149 trades, 89.9% win rate, ~1.68% TP target)

### Dynamic Position Sizing
- **Percentage-based**: Position size calculated dynamically at trade time from wallet balance
- **Formula**: `margin = walletBalance × (position_size_pct / 100)`, `notional = margin × leverage`
- **Example**: $10,000 balance, 1% setting, 10x leverage → $100 margin → $1,000 notional position
- **Scales automatically**: As balance grows/shrinks from P&L, position sizes adjust proportionally
- **DCA layers**: Also use dynamic sizing — recalculated from current balance at DCA time

### Position Management (bidirectional)
- **LONG entry**: `openLong()` — ATR-based SL/TP (wave), fixed % SL/TP (staircase)
- **SHORT entry**: `openShort()` — ATR-based SL/TP (wave), fixed % SL/TP (staircase)
- **Margin check**: Before opening, verifies `availableBalance >= notional / leverage`
- **P&L**: LONG = (current - entry) * qty, SHORT = (entry - current) * qty
- **Fees**: Deducted from P&L on close — entry fee + exit fee (taker rate on notional). Stored in `Trade.fees` column.
- **Expiry**: `wave_max_hold_minutes` (default 120 min) or `staircase_max_hold_minutes` (default 1440 min)

### ATR-Based SL/TP (Wave Rider)
- **SL**: 1.0x ATR from entry price (configurable via `wave_sl_atr_multiplier`)
- **TP**: 1.5x ATR from entry (configurable via `wave_tp_atr_multiplier`)
- **Fee-aware TP floor**: TP distance guaranteed to cover round-trip fees × configurable multiplier. Min TP = `entryPrice × 2 × takerRate × feeFloorMultiplier` (default 2.5×). Logged when adjustment occurs.
- **ATR/fee viability filter**: Before opening, checks if fee-floor-adjusted TP exceeds `wave_max_tp_atr` × ATR (default 2.5×). If so, skips the trade — ATR is too low relative to fees for TP to be reachable. Prevents opening positions that can never realistically hit TP.
- **Fallback**: If ATR < 0.1% of price, uses 1% SL / 0.5% TP
- **Breakeven protection**: When unrealized profit >= round-trip fee distance (`entryPrice × 2 × takerRate`), SL moves to entry price. Prevents profitable positions from reversing to full SL loss. Resets on DCA (entry changes). Uses `CloseReason::Breakeven` to distinguish from full stop-loss.
- **Trailing stop**: Activates at 0.15x ATR profit, trails at 0.2x ATR distance (configurable). Engages after breakeven, only moves SL further into profit.

### DCA (Dollar Cost Averaging)
- **Purpose**: Build positions when price moves against entry but wave still intact
- **Layers**: Up to 3 (configurable via `dca_max_layers`)
- **Trigger**: Price moves >= 0.5x ATR * layer_count against average entry (configurable via `wave_dca_trigger_atr`)
- **Layer sizing**: Layer 1 = 100%, Layer 2 = 75%, Layer 3 = 50% of dynamically calculated base size
- **Validation**: Before each DCA layer, `WaveScanner::isWaveIntact()` checks EMA5/13 still agrees
- **Cap**: Total position cannot exceed `max_position_usdt` (default 150 USDT)
- **On DCA**: Recalculates weighted average entry, updates quantity, recomputes SL/TP from new average
- **Exchange orders**: Old SL/TP orders cancelled and replaced with new levels
- **Position fields**: `layer_count` (1-3), `atr_value` (decimal) stored on Position model

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/WaveScanner.php` | Wave detection: EMA(5/13) cross, RSI(7), ATR(14) on strategy-specific candles (wave: 15m, staircase: 1h) |
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
| `app/Console/Commands/BotRun.php` | Unified 30-second scan+monitor loop with graceful shutdown |
| `bootstrap/app.php` | CSRF exemption for `api/*` routes |
| `config/crypto.php` | Default config values |

## Settings (runtime-configurable from dashboard)

### Core Settings
| Key | Default | Description |
|-----|---------|-------------|
| `position_size_pct` | 1.0 | Position size as % of wallet balance (margin before leverage) |
| `max_positions` | 2 | Max concurrent open positions |
| `leverage` | 5 | Futures leverage (multiplied with margin to get notional) |
| `dry_run` | true | Paper trading mode |
| `starting_balance` | 10000 | Simulated starting balance |
| `dry_run_fee_rate` | 0.0005 | Dry-run simulated fee rate (0.05%) |
| `watchlist` | BTCUSDT | Comma-separated symbols to scan (1-3 recommended) |
| `max_position_usdt` | 150 | Max total USDT per coin incl. DCA layers |
| `dca_enabled` | true | Enable/disable DCA layers |
| `dca_max_layers` | 3 | Max DCA layers per position |
| `strategy` | wave | Active strategy: 'wave' or 'staircase' |

### Wave Settings
| Key | Default | Description |
|-----|---------|-------------|
| `wave_scan_interval` | 30 | Seconds between scan loops |
| `wave_kline_interval` | 15m | Kline candle interval (1m, 5m, 15m, 1h, 4h) |
| `wave_ema_fast` | 5 | Fast EMA period |
| `wave_ema_slow` | 13 | Slow EMA period |
| `wave_rsi_period` | 7 | RSI period |
| `wave_atr_period` | 14 | ATR period |
| `wave_kline_limit` | 50 | Number of candles to fetch |
| `wave_sl_atr_multiplier` | 1.0 | Stop loss distance in ATR multiples |
| `wave_tp_atr_multiplier` | 1.5 | Take profit distance in ATR multiples |
| `wave_fee_floor_multiplier` | 2.5 | Fee floor multiplier for minimum TP distance |
| `wave_max_tp_atr` | 2.0 | Max TP distance in ATR multiples (skip trade if fee floor exceeds this) |
| `wave_trailing_activation_atr` | 0.15 | Trailing stop activation in ATR multiples |
| `wave_trailing_distance_atr` | 0.2 | Trailing stop distance in ATR multiples |
| `wave_max_hold_minutes` | 120 | Max hold time before forced close |
| `wave_dca_trigger_atr` | 0.5 | DCA trigger distance in ATR multiples |
| `wave_rsi_overbought` | 80 | RSI overbought threshold (reject LONG above) |
| `wave_rsi_oversold` | 20 | RSI oversold threshold (reject SHORT below) |

### Staircase Settings
| Key | Default | Description |
|-----|---------|-------------|
| `staircase_take_profit_pct` | 1.68 | Fixed TP as % of entry price |
| `staircase_stop_loss_pct` | 5.0 | Fixed SL as % of entry price |
| `staircase_max_hold_minutes` | 1440 | Max hold time (24h default) |
| `staircase_rsi_filter` | false | Whether to apply RSI overbought/oversold filter |
| `staircase_scan_interval` | 30 | Scan interval in seconds |
| `staircase_cooldown_minutes` | 30 | Minutes to wait after closing before re-entering same symbol |
| `staircase_kline_interval` | 1h | Kline candle interval for EMA analysis (1h = more stable than 15m) |

## API Endpoints

| Method | Path | Action |
|--------|------|--------|
| GET | `/` | Dashboard view |
| GET | `/api/data` | Positions (with estimated fees & net P&L), trades, wave status, summary JSON |
| GET | `/api/settings` | Current settings |
| POST | `/api/settings` | Save settings `{settings: {key: value}}` |
| POST | `/api/scan` | Manual wave scan `{auto_trade: bool}` |
| POST | `/api/close` | Close position `{position_id: int}` |
| POST | `/api/reset` | Truncate all trades/positions |

## Database (MariaDB 11)

**Tables**: `scanned_coins`, `positions`, `trades`, `bot_settings`, `cache`, `jobs`, `users`

**Enums**:
- `PositionStatus`: Open, Closed, Expired, StoppedOut
- `CloseReason`: TakeProfit, StopLoss, Expired, Manual, WaveBreak, Breakeven

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

- **Kline cache**: In-memory 15-second TTL in WaveScanner. Prevents duplicate API calls when `analyze()` and `isWaveIntact()` need klines for the same symbol in the same loop.
- **Price cache**: 10s TTL in database cache. `getPrice()` reads from cache first (1 API weight per miss).
- **Account data cache**: 10s TTL. `getAccountData()` cached as `binance:account_data`.
- **Commission rate cache**: 1h TTL per symbol. `getCommissionRate()` cached as `binance:commission:{symbol}`.
- **API budget**: ~12-36 weight/minute for 1-3 coins (vs 2,400 limit = 1.5% usage). Each `getKlines()` = 1 weight, `getPrice()` = 1 weight (cached 10s).
- **Tradability filter**: `getExchangeInfo()` cached 1 hour. `isTradable()` checks symbol status is "TRADING".
- **LOT_SIZE**: `calculateQuantity()` and `formatQuantity()` use actual stepSize from exchangeInfo.
- **MariaDB**: Proper concurrent access from all containers. No file locking issues.
- **Rate limiting**: Tracks `X-MBX-USED-WEIGHT-1M` header from Binance. Warns at 1800/2400. Pauses at 2300/2400.
- **Bot loop**: Unified 30-second loop — scan + monitor in one pass per symbol. Responsive shutdown via sub-second sleep chunks.

## Fee & Balance Tracking

- **Trading fees**: Calculated on position close. Both entry and exit use taker rate (market orders). Fee = `price * quantity * takerRate` per side. Deducted from realized P&L.
- **Estimated fees for open positions**: Dashboard computes `estimated_fees` (entry + projected exit at current price) and `net_pnl` (unrealized P&L minus estimated fees) per position. Uses `getCommissionRate()` (cached 1h per symbol) with fallback to `dry_run_fee_rate`.
- **Fee-aware TP floor**: `calculateSlTp()` ensures TP distance always exceeds round-trip fees × configurable multiplier (default 2.5×). Min TP = `entryPrice × 2 × takerRate × feeFloorMultiplier`. Prevents take-profit exits that lose money after fees.
- **Fee rates**: Live mode fetches from `/fapi/v1/commissionRate` (default ~0.05% taker). Dry-run uses `dry_run_fee_rate` setting.
- **Balance model**: `getAccountData()` returns Binance-compatible fields: `walletBalance`, `availableBalance`, `unrealizedProfit`, `marginBalance`, `positionMargin`, `maintMargin`.
- **DryRun margin**: Uses `position_size_usdt / leverage` (margin), not full notional. With 10x leverage, a $1,000 notional position locks $100 margin.
- **Dynamic sizing**: `openPosition()` calculates notional from `walletBalance × (position_size_pct / 100) × leverage` at trade time. Verifies `availableBalance >= margin` before placing orders.

## Dashboard

- **Cards**: Wallet balance, available balance, margin in use, combined/realized/unrealized P&L, total fees, win rate
- **Open Positions table**: Symbol (with side/leverage/DRY badge), entry/current price, unrealized P&L, P&L%, size (notional USDT), net P&L (with fees), SL/TP, layers, opened time, close button
- **Wave Status tab**: Per-symbol wave direction, state (new_wave/riding/weakening), RSI, ATR, EMA gap, price. Hidden when wave strategy is not selected.
- **Trade History table**: Symbol (with side/leverage/DRY badge), entry/exit price, P&L, P&L%, size, fees, net P&L, close reason, opened/closed time
- **Settings tab**: All runtime-configurable settings with strategy dropdown (Wave/Staircase)
- **Formatting**: Thousand separators on all dollar amounts ($66,591.10), subscript notation for micro-prices, inline color styling for P&L values

## Known Issues & Gotchas

- **CSRF**: POST routes under `/api/*` are excluded from CSRF verification in `bootstrap/app.php`
- **Binance DNS blocking**: Indonesian ISPs block `fapi.binance.com`. Workaround: `/etc/hosts` entries pointing to real IPs (resolved via Cloudflare 1.1.1.1)
- **BINANCE_TESTNET env**: Must use `filter_var(env(...), FILTER_VALIDATE_BOOLEAN)` because `env()` returns string "false" which is truthy in PHP
- **DB settings override**: Old settings in `bot_settings` table override new config defaults. After strategy changes, reset via `POST /api/settings` or `POST /api/reset`
- **Rebuild required**: After code changes, must `docker compose down && docker compose build && docker compose up -d`
