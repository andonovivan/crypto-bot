import { getJson, postJson } from './api.js';
import { fmtMoney, fmtPnl } from './formatters.js';
import { renderEquity, resizeEquity } from './charts/equity.js';
import { renderPnlBySymbol, renderCloseReason, renderTradesPerDay, renderWinRateSparkline, resizeAggregates } from './charts/aggregates.js';
import { renderPositions } from './tables/positions.js';
import { toast } from './toast.js';

const polling = {
    timers: {},
    equityRange: '24h',
    state: {
        dryRun: false,
        paused: false,
        circuitBreakerActive: false,
        wallet: 0,
        todayPnl: 0,
        lastUpdate: null,
    },
    listeners: new Set(),
};

function emit() {
    polling.listeners.forEach((fn) => fn(polling.state));
}

function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function setHtml(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
}

function setColor(id, cls) {
    const el = document.getElementById(id);
    if (el) {
        el.classList.remove(
            'text-[var(--color-success)]',
            'text-[var(--color-danger)]',
            'text-[var(--color-warning)]',
            'text-[var(--color-text)]',
            'text-[var(--color-accent)]',
        );
        if (cls) el.classList.add(cls);
    }
}

function pnlClass(v) {
    if (v > 0) return 'text-[var(--color-success)]';
    if (v < 0) return 'text-[var(--color-danger)]';
    return 'text-[var(--color-text)]';
}

async function fetchData() {
    try {
        const data = await getJson('/api/data');
        const s = data.summary;

        polling.state.dryRun = s.dry_run;
        polling.state.paused = s.trading_paused;
        polling.state.wallet = s.wallet_balance;
        polling.state.lastUpdate = data.ts;
        emit();

        // KPI cards (these IDs only exist on overview/positions pages)
        setText('kpi-wallet', fmtMoney(s.wallet_balance));
        setText('kpi-available', fmtMoney(s.available_balance));
        setText('kpi-margin-in-use', fmtMoney(s.margin_in_use));

        const netPnlEl = document.getElementById('kpi-net-pnl');
        if (netPnlEl) {
            netPnlEl.textContent = fmtPnl(s.net_pnl);
            setColor('kpi-net-pnl', pnlClass(s.net_pnl));
        }

        const fundingEl = document.getElementById('kpi-funding');
        if (fundingEl) {
            fundingEl.textContent = fmtPnl(s.total_funding);
            setColor('kpi-funding', pnlClass(s.total_funding));
        }

        setText('kpi-fees', '−' + fmtMoney(Math.abs(s.total_fees), 2));
        setColor('kpi-fees', 'text-[var(--color-danger)]');

        const winrateEl = document.getElementById('kpi-winrate');
        if (winrateEl) {
            winrateEl.textContent = s.win_rate + '%';
            const sub = document.getElementById('kpi-winrate-sub');
            if (sub) sub.textContent = `${s.winning_trades}/${s.total_trades} trades`;
            setColor('kpi-winrate', s.win_rate >= 50 ? 'text-[var(--color-success)]' : (s.total_trades > 0 ? 'text-[var(--color-danger)]' : 'text-[var(--color-text-muted)]'));
        }

        setText('kpi-open-positions', String(s.open_positions));

        renderPositions(data.positions || []);
    } catch (e) {
        console.error('fetchData error:', e);
    }
}

async function fetchStats() {
    try {
        const data = await getJson('/api/stats');
        polling.state.circuitBreakerActive = data.circuit_breaker?.is_active ?? false;
        polling.state.todayPnl = data.today_pnl ?? 0;
        emit();

        // Equity & 24h delta
        const equityEl = document.getElementById('kpi-equity');
        if (equityEl) {
            equityEl.textContent = fmtMoney(data.equity);
            const subEl = document.getElementById('kpi-equity-sub');
            if (subEl && data.equity_24h_ago !== null) {
                const delta = data.equity - data.equity_24h_ago;
                const deltaPct = data.equity_24h_ago > 0 ? (delta / data.equity_24h_ago) * 100 : 0;
                subEl.innerHTML = `<span class="${pnlClass(delta)}">${fmtPnl(delta)} (${deltaPct >= 0 ? '+' : ''}${deltaPct.toFixed(2)}%) 24h</span>`;
            }
        }

        const ddEl = document.getElementById('kpi-current-dd');
        if (ddEl) {
            const dd = data.current_drawdown_pct;
            ddEl.textContent = '−' + dd.toFixed(2) + '%';
            setColor('kpi-current-dd', dd > 5 ? 'text-[var(--color-danger)]' : dd > 1 ? 'text-[var(--color-warning)]' : 'text-[var(--color-text)]');
            const sub = document.getElementById('kpi-current-dd-sub');
            if (sub && data.circuit_breaker?.is_active) {
                const left = Math.max(0, (data.circuit_breaker.cooldown_until ?? 0) - Math.floor(Date.now() / 1000));
                const h = Math.floor(left / 3600);
                const m = Math.floor((left % 3600) / 60);
                sub.innerHTML = `<span class="text-[var(--color-danger)]">Breaker tripped — ${h}h ${m}m left</span>`;
            } else if (sub) {
                sub.textContent = data.circuit_breaker?.peak_equity ? 'Peak: ' + fmtMoney(data.circuit_breaker.peak_equity) : '';
            }
        }

        const todayEl = document.getElementById('kpi-today-pnl');
        if (todayEl) {
            todayEl.textContent = fmtPnl(data.today_pnl);
            setColor('kpi-today-pnl', pnlClass(data.today_pnl));
        }

        const expEl = document.getElementById('kpi-exposure');
        if (expEl) {
            expEl.textContent = data.exposure_pct.toFixed(1) + '%';
            const sub = document.getElementById('kpi-exposure-sub');
            if (sub) sub.textContent = fmtMoney(data.exposure_usdt) + ' notional';
            setColor('kpi-exposure', data.exposure_pct > 80 ? 'text-[var(--color-warning)]' : 'text-[var(--color-text)]');
        }

        const pfEl = document.getElementById('kpi-profit-factor');
        if (pfEl) {
            pfEl.textContent = data.profit_factor_30d == null ? 'n/a' : data.profit_factor_30d.toFixed(2);
            setColor('kpi-profit-factor', data.profit_factor_30d > 1.2 ? 'text-[var(--color-success)]' : data.profit_factor_30d != null && data.profit_factor_30d < 1 ? 'text-[var(--color-danger)]' : 'text-[var(--color-text)]');
        }

        const wrEl = document.getElementById('kpi-rolling-wr');
        if (wrEl && data.rolling_win_rate) {
            wrEl.textContent = (data.rolling_win_rate.current * 100).toFixed(0) + '%';
            const sub = document.getElementById('kpi-rolling-wr-sub');
            if (sub) sub.textContent = `last ${data.rolling_win_rate.window} trades`;
            setColor('kpi-rolling-wr', data.rolling_win_rate.current >= 0.5 ? 'text-[var(--color-success)]' : data.rolling_win_rate.window > 0 ? 'text-[var(--color-danger)]' : 'text-[var(--color-text-muted)]');
            const sparkEl = document.getElementById('kpi-rolling-wr-spark');
            if (sparkEl && data.rolling_win_rate.history) {
                renderWinRateSparkline(sparkEl, data.rolling_win_rate.history);
            }
        }

        const durEl = document.getElementById('kpi-avg-duration');
        if (durEl) {
            const s = data.avg_duration_seconds;
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            durEl.textContent = s === 0 ? '—' : (h > 0 ? `${h}h ${m}m` : `${m}m`);
        }

        const ddMaxEl = document.getElementById('kpi-max-dd');
        if (ddMaxEl) {
            ddMaxEl.textContent = data.max_drawdown ? '−' + data.max_drawdown.pct.toFixed(2) + '%' : '—';
            setColor('kpi-max-dd', data.max_drawdown && data.max_drawdown.pct > 0 ? 'text-[var(--color-warning)]' : 'text-[var(--color-text-muted)]');
            const sub = document.getElementById('kpi-max-dd-sub');
            if (sub && data.max_drawdown) {
                const fromD = new Date(data.max_drawdown.from);
                const toD = new Date(data.max_drawdown.to);
                sub.textContent = fromD.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' → ' + toD.toLocaleDateString([], { month: 'short', day: 'numeric' });
            }
        }

        // Risk page extras
        const bestEl = document.getElementById('risk-best-trade');
        if (bestEl) bestEl.innerHTML = data.best_trade
            ? `<span class="font-semibold">${data.best_trade.symbol}</span> · <span class="text-[var(--color-success)] font-mono">${fmtPnl(data.best_trade.pnl)}</span>`
            : '<span class="text-[var(--color-text-muted)]">—</span>';
        const worstEl = document.getElementById('risk-worst-trade');
        if (worstEl) worstEl.innerHTML = data.worst_trade
            ? `<span class="font-semibold">${data.worst_trade.symbol}</span> · <span class="text-[var(--color-danger)] font-mono">${fmtPnl(data.worst_trade.pnl)}</span>`
            : '<span class="text-[var(--color-text-muted)]">—</span>';

        const streakEl = document.getElementById('risk-streak');
        if (streakEl && data.current_streak) {
            const t = data.current_streak.type;
            const cls = t === 'win' ? 'text-[var(--color-success)]' : t === 'loss' ? 'text-[var(--color-danger)]' : 'text-[var(--color-text-muted)]';
            streakEl.innerHTML = t
                ? `<span class="${cls} font-semibold">${data.current_streak.count} ${t}${data.current_streak.count === 1 ? '' : 's'}</span>`
                : '<span class="text-[var(--color-text-muted)]">No trades yet</span>';
        }

        const fundEl = document.getElementById('risk-funding-30d');
        if (fundEl && data.funding_30d) {
            fundEl.innerHTML = `<span class="text-[var(--color-success)]">${fmtPnl(data.funding_30d.received)}</span> received · <span class="text-[var(--color-danger)]">${fmtPnl(data.funding_30d.paid)}</span> paid`;
        }

        const cbCard = document.getElementById('risk-cb-state');
        if (cbCard) {
            const enabled = data.circuit_breaker?.enabled;
            const active = data.circuit_breaker?.is_active;
            const stateText = !enabled ? 'Disabled' : active ? 'Tripped' : 'Armed';
            const cls = !enabled ? 'text-[var(--color-text-muted)]' : active ? 'text-[var(--color-danger)]' : 'text-[var(--color-success)]';
            cbCard.innerHTML = `<span class="${cls} font-semibold">${stateText}</span>`;
            const peakEl = document.getElementById('risk-cb-peak');
            if (peakEl) peakEl.textContent = data.circuit_breaker?.peak_equity ? fmtMoney(data.circuit_breaker.peak_equity) : '—';
            const cooldownEl = document.getElementById('risk-cb-cooldown');
            if (cooldownEl) {
                if (active && data.circuit_breaker.cooldown_until) {
                    const left = Math.max(0, data.circuit_breaker.cooldown_until - Math.floor(Date.now() / 1000));
                    const h = Math.floor(left / 3600);
                    const m = Math.floor((left % 3600) / 60);
                    cooldownEl.textContent = `${h}h ${m}m left`;
                } else {
                    cooldownEl.textContent = '—';
                }
            }
        }
    } catch (e) {
        console.error('fetchStats error:', e);
    }
}

async function fetchEquity() {
    try {
        const data = await getJson('/api/balance-history?range=' + encodeURIComponent(polling.equityRange));
        const el = document.getElementById('equity-chart');
        if (el) renderEquity(el, data.points || [], polling.equityRange);
    } catch (e) {
        console.error('fetchEquity error:', e);
    }
}

async function fetchAggregates() {
    try {
        const data = await getJson('/api/trades/aggregates');
        const sym = document.getElementById('chart-pnl-by-symbol');
        const reason = document.getElementById('chart-close-reason');
        const day = document.getElementById('chart-trades-per-day');
        if (sym) renderPnlBySymbol(sym, data.by_symbol);
        if (reason) renderCloseReason(reason, data.by_reason);
        if (day) renderTradesPerDay(day, data.by_day);
    } catch (e) {
        console.error('fetchAggregates error:', e);
    }
}

function setRange(range) {
    polling.equityRange = range;
    document.querySelectorAll('[data-range-group="equity"] [data-range]').forEach((b) => {
        if (b.dataset.range === range) b.dataset.active = 'true';
        else delete b.dataset.active;
    });
    fetchEquity();
}

function bindRangePills() {
    document.querySelectorAll('[data-range-group="equity"] [data-range]').forEach((b) => {
        b.addEventListener('click', () => setRange(b.dataset.range));
    });
}

function start(page) {
    bindRangePills();

    fetchData();
    polling.timers.data = setInterval(fetchData, 10000);

    fetchStats();
    polling.timers.stats = setInterval(fetchStats, 30000);

    if (document.getElementById('equity-chart')) {
        fetchEquity();
        polling.timers.equity = setInterval(fetchEquity, 60000);
        window.addEventListener('resize', () => resizeEquity());
    }

    if (document.getElementById('chart-pnl-by-symbol') || document.getElementById('chart-close-reason') || document.getElementById('chart-trades-per-day')) {
        fetchAggregates();
        polling.timers.agg = setInterval(fetchAggregates, 60000);
        window.addEventListener('resize', () => resizeAggregates());
    }

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) fetchData();
    });
}

async function togglePause() {
    try {
        await postJson('/api/settings', { settings: { trading_paused: !polling.state.paused } });
        polling.state.paused = !polling.state.paused;
        emit();
        toast.success(polling.state.paused ? 'Trading paused' : 'Trading resumed');
        fetchData();
    } catch (e) {
        toast.error(e.message);
    }
}

window.dashboardPolling = {
    refreshNow: () => {
        fetchData();
        fetchStats();
    },
    subscribe: (fn) => {
        polling.listeners.add(fn);
        fn(polling.state);
        return () => polling.listeners.delete(fn);
    },
    togglePause,
    state: polling.state,
    start,
};
