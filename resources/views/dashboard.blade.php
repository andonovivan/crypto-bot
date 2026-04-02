<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Crypto Pump &amp; Dump Bot</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
         background: #0d1117; color: #c9d1d9; padding: 20px; }
  h1 { color: #58a6ff; margin-bottom: 4px; font-size: 1.4em; }
  .subtitle { color: #8b949e; font-size: 0.85em; margin-bottom: 20px; }
  .dry-run-badge { background: #d29922; color: #0d1117; padding: 2px 8px;
                   border-radius: 4px; font-size: 0.75em; font-weight: bold; }
  .live-badge { background: #f85149; color: #fff; padding: 2px 8px;
                border-radius: 4px; font-size: 0.75em; font-weight: bold; }
  .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
           gap: 12px; margin-bottom: 24px; }
  .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 16px; }
  .card-label { color: #8b949e; font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.05em; }
  .card-value { font-size: 1.5em; font-weight: bold; margin-top: 4px; }
  .positive { color: #3fb950; }
  .negative { color: #f85149; }
  .neutral { color: #c9d1d9; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  th { text-align: left; color: #8b949e; font-size: 0.75em; text-transform: uppercase;
       letter-spacing: 0.05em; padding: 8px 12px; border-bottom: 1px solid #30363d;
       cursor: pointer; user-select: none; white-space: nowrap; }
  th:hover { color: #c9d1d9; }
  th .sort-arrow { margin-left: 4px; font-size: 0.7em; }
  td { padding: 8px 12px; border-bottom: 1px solid #21262d; font-size: 0.9em; }
  tr:hover { background: #161b22; }
  .section-title { color: #58a6ff; font-size: 1em; margin: 20px 0 10px; }
  .tabs { display: flex; gap: 0; margin-bottom: 20px; border-bottom: 1px solid #30363d; }
  .tab-btn { background: none; border: none; color: #8b949e; padding: 10px 20px;
    cursor: pointer; font-size: 0.9em; border-bottom: 2px solid transparent; }
  .tab-btn:hover { color: #c9d1d9; }
  .tab-btn.active { color: #58a6ff; border-bottom-color: #58a6ff; }
  .tab-pane { display: none; }
  .tab-pane.active { display: block; }
  .btn-close-pos { background: #da3633; color: #fff; border: none; border-radius: 4px;
    padding: 3px 10px; cursor: pointer; font-size: 0.75em; white-space: nowrap; }
  .btn-close-pos:hover { background: #f85149; }
  .btn-close-pos:disabled { opacity: 0.4; cursor: default; }
  .signal-status { padding: 2px 6px; border-radius: 3px; font-size: 0.75em; font-weight: bold; }
  .signal-detected { background: #d29922; color: #0d1117; }
  .signal-reversal { background: #f85149; color: #fff; }
  .side-long { color: #3fb950; font-weight: bold; font-size: 0.75em; }
  .side-short { color: #f85149; font-weight: bold; font-size: 0.75em; }
  .score-bar { display: inline-block; height: 6px; border-radius: 3px; background: #30363d; width: 60px; vertical-align: middle; }
  .score-fill { display: block; height: 100%; border-radius: 3px; }
  .signal-long { background: #238636; color: #fff; }
  .signal-short { background: #da3633; color: #fff; }
  .pagination { display: flex; align-items: center; gap: 8px; margin: 8px 0 20px; }
  .pagination button { background: #21262d; color: #c9d1d9; border: 1px solid #30363d;
    border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 0.8em; }
  .pagination button:hover { background: #30363d; }
  .pagination button:disabled { opacity: 0.4; cursor: default; }
  .pagination .page-info { color: #8b949e; font-size: 0.8em; }
  .settings-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px; overflow: hidden; }
  .setting-item { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 14px 16px;
    display: flex; justify-content: space-between; align-items: center; gap: 12px; overflow: hidden; min-width: 0; }
  .setting-item label { color: #8b949e; font-size: 0.85em; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .setting-item input[type="number"], .setting-item input[type="text"] {
    background: #0d1117; border: 1px solid #30363d; border-radius: 4px; color: #c9d1d9;
    padding: 6px 10px; font-size: 0.9em; width: 100px; min-width: 80px; flex-shrink: 0; text-align: right; font-family: monospace; }
  .setting-item input:focus { border-color: #58a6ff; outline: none; }
  .setting-item select { background: #0d1117; border: 1px solid #30363d; border-radius: 4px;
    color: #c9d1d9; padding: 6px 10px; font-size: 0.9em; flex-shrink: 0; }
  .btn-save { background: #238636; color: #fff; border: none; border-radius: 6px;
    padding: 8px 24px; cursor: pointer; font-size: 0.9em; margin-top: 12px; }
  .btn-save:hover { background: #2ea043; }
  .btn-save:disabled { opacity: 0.5; cursor: default; }
  .settings-msg { font-size: 0.85em; margin-bottom: 12px; min-height: 1.4em; }
  .footer { color: #484f58; font-size: 0.75em; margin-top: 24px; }
  .empty { color: #484f58; text-align: center; padding: 20px; }
  td sub, .card-value sub { font-size: 0.7em; vertical-align: baseline; color: #8b949e; }
  @media (max-width: 600px) {
    .cards { grid-template-columns: 1fr 1fr; }
    td, th { padding: 6px 8px; font-size: 0.8em; }
  }
</style>
</head>
<body>
<h1>Crypto Trading Bot <span id="badge"></span> <span id="strategy-badge"></span></h1>
<p class="subtitle">
  <span id="signals-count">-</span> active signals &middot;
  <span id="positions-count-sub">-</span> open positions &middot;
  Updated <span id="updated">-</span>
</p>

<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('dashboard')">Dashboard</button>
  <button class="tab-btn" onclick="switchTab('signals')" id="signals-tab-btn">Signals</button>
  <button class="tab-btn" onclick="switchTab('history')">Trade History</button>
  <button class="tab-btn" onclick="switchTab('settings')">Settings</button>
</div>

<!-- Dashboard Tab -->
<div id="tab-dashboard" class="tab-pane active">
  <div class="cards">
    <div class="card">
      <div class="card-label">Balance</div>
      <div class="card-value neutral" id="balance">-</div>
    </div>
    <div class="card">
      <div class="card-label">Combined P&amp;L</div>
      <div class="card-value" id="combined">-</div>
    </div>
    <div class="card">
      <div class="card-label">Unrealized P&amp;L</div>
      <div class="card-value" id="unrealized">-</div>
    </div>
    <div class="card">
      <div class="card-label">Realized P&amp;L</div>
      <div class="card-value" id="realized">-</div>
    </div>
    <div class="card">
      <div class="card-label">Win Rate</div>
      <div class="card-value" id="winrate">-</div>
    </div>
    <div class="card">
      <div class="card-label">Open Positions</div>
      <div class="card-value neutral" id="positions-count">-</div>
    </div>
    <div class="card">
      <div class="card-label">Total Invested</div>
      <div class="card-value neutral" id="invested">-</div>
    </div>
  </div>

  <h2 class="section-title">Open Positions</h2>
  <table>
    <thead>
      <tr>
        <th onclick="sortTable('positions', 'symbol')">Symbol <span class="sort-arrow" id="pos-sort-symbol"></span></th>
        <th>Side</th>
        <th onclick="sortTable('positions', 'entry_price')">Entry <span class="sort-arrow" id="pos-sort-entry_price"></span></th>
        <th onclick="sortTable('positions', 'current_price')">Current <span class="sort-arrow" id="pos-sort-current_price"></span></th>
        <th onclick="sortTable('positions', 'position_size_usdt')">Invested <span class="sort-arrow" id="pos-sort-position_size_usdt"></span></th>
        <th onclick="sortTable('positions', 'current_value')">Value <span class="sort-arrow" id="pos-sort-current_value"></span></th>
        <th onclick="sortTable('positions', 'unrealized_pnl')">P&amp;L <span class="sort-arrow" id="pos-sort-unrealized_pnl"></span></th>
        <th onclick="sortTable('positions', 'pnl_pct')">P&amp;L % <span class="sort-arrow" id="pos-sort-pnl_pct"></span></th>
        <th>SL</th>
        <th>TP</th>
        <th onclick="sortTable('positions', 'opened_at')">Opened <span class="sort-arrow" id="pos-sort-opened_at"></span></th>
        <th></th>
      </tr>
    </thead>
    <tbody id="positions-body"><tr><td colspan="12" class="empty">Loading...</td></tr></tbody>
  </table>
</div>

<!-- Signals Tab -->
<div id="tab-signals" class="tab-pane">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
    <h2 class="section-title" style="margin:0;" id="signals-title">Active Signals</h2>
    <button class="btn-save" id="scan-btn" onclick="scanNow(this)">Scan Now</button>
  </div>
  <div class="settings-msg" id="scan-msg"></div>

  <!-- Trend signals table -->
  <table id="trend-signals-table">
    <thead>
      <tr>
        <th>Symbol</th>
        <th>Direction</th>
        <th>Score</th>
        <th>EMA Cross</th>
        <th>RSI</th>
        <th>MACD</th>
        <th>Volume</th>
        <th>Price</th>
        <th>Status</th>
        <th>Detected</th>
      </tr>
    </thead>
    <tbody id="trend-signals-body"><tr><td colspan="10" class="empty">Loading...</td></tr></tbody>
  </table>

  <!-- Pump signals table (hidden when trend strategy active) -->
  <table id="pump-signals-table" style="display:none;">
    <thead>
      <tr>
        <th>Symbol</th>
        <th>Price Change</th>
        <th>Volume</th>
        <th>Peak Price</th>
        <th>Current</th>
        <th>Drop from Peak</th>
        <th>Status</th>
        <th>Detected</th>
      </tr>
    </thead>
    <tbody id="pump-signals-body"><tr><td colspan="8" class="empty">Loading...</td></tr></tbody>
  </table>
</div>

<!-- History Tab -->
<div id="tab-history" class="tab-pane">
  <h2 class="section-title">Recent Closed Trades</h2>
  <table>
    <thead>
      <tr>
        <th onclick="sortTable('history', 'symbol')">Symbol <span class="sort-arrow" id="hist-sort-symbol"></span></th>
        <th>Side</th>
        <th onclick="sortTable('history', 'entry_price')">Entry <span class="sort-arrow" id="hist-sort-entry_price"></span></th>
        <th onclick="sortTable('history', 'exit_price')">Exit <span class="sort-arrow" id="hist-sort-exit_price"></span></th>
        <th onclick="sortTable('history', 'quantity')">Qty <span class="sort-arrow" id="hist-sort-quantity"></span></th>
        <th onclick="sortTable('history', 'pnl')">P&amp;L <span class="sort-arrow" id="hist-sort-pnl"></span></th>
        <th onclick="sortTable('history', 'pnl_pct')">P&amp;L % <span class="sort-arrow" id="hist-sort-pnl_pct"></span></th>
        <th>Reason</th>
        <th onclick="sortTable('history', 'created_at')">Closed <span class="sort-arrow" id="hist-sort-created_at"></span></th>
      </tr>
    </thead>
    <tbody id="history-body"><tr><td colspan="9" class="empty">Loading...</td></tr></tbody>
  </table>
  <div class="pagination" id="history-pagination"></div>
</div>

<!-- Settings Tab -->
<div id="tab-settings" class="tab-pane">
  <h2 class="section-title">Bot Configuration</h2>
  <div class="settings-msg" id="settings-msg"></div>
  <div class="settings-form" id="settings-form">
    <div class="card" style="margin-bottom:12px"><div class="card-label">Loading settings...</div></div>
  </div>
  <div style="margin-top:8px;">
    <span style="color:#8b949e; font-size:0.8em;">Exchange: <span id="settings-exchange">-</span> | Testnet: <span id="settings-testnet">-</span></span>
  </div>

  <h2 class="section-title" style="margin-top:32px;">Danger Zone</h2>
  <div class="setting-item" style="border-color:#da3633; max-width:560px; justify-content:space-between;">
    <div>
      <label style="color:#c9d1d9; font-weight:bold;">Reset All Data</label>
      <div style="color:#8b949e; font-size:0.8em; margin-top:2px;">Deletes all trades, positions, and pump signals. Cannot be undone.</div>
    </div>
    <button class="btn-close-pos" style="padding:6px 16px; font-size:0.85em;" onclick="resetAll(this)">Reset</button>
  </div>
</div>

<div class="footer">Auto-refreshes every 10s</div>

<script>
const PAGE_SIZE = 15;
let lastData = null;

// Sorting state
const sortState = {
  positions: { key: 'opened_at', asc: false },
  history: { key: 'created_at', asc: false },
};

function switchTab(name) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelector('.tab-btn[onclick*="' + name + '"]').classList.add('active');
}

function sortData(items, key, asc) {
  return [...items].sort((a, b) => {
    let va = a[key], vb = b[key];
    if (va === null || va === undefined) va = -Infinity;
    if (vb === null || vb === undefined) vb = -Infinity;
    if (typeof va === 'string') return asc ? va.localeCompare(vb) : vb.localeCompare(va);
    return asc ? va - vb : vb - va;
  });
}

function sortTable(table, key) {
  const state = sortState[table];
  if (state.key === key) { state.asc = !state.asc; }
  else { state.key = key; state.asc = true; }
  if (lastData) render(lastData);
}

function pnlClass(val) {
  if (val > 0) return 'positive';
  if (val < 0) return 'negative';
  return 'neutral';
}

function pnlStr(val) {
  if (val === null || val === undefined) return '-';
  const prefix = val >= 0 ? '+$' : '-$';
  return prefix + Math.abs(val).toFixed(2);
}

function formatPrice(val) {
  if (val === null || val === undefined) return '-';
  if (val === 0) return '$0';
  const abs = Math.abs(val);
  const sign = val < 0 ? '-' : '';
  // For prices >= 100, use 2 decimal places
  if (abs >= 100) return sign + '$' + abs.toFixed(2);
  // For prices >= 1, use 4 decimal places
  if (abs >= 1) return sign + '$' + abs.toFixed(4);
  // For prices < 1, check for leading zeros after decimal
  const str = abs.toFixed(20).replace(/0+$/, '');
  const zeros = (str.match(/^0\.(0+)/) || [, ''])[1].length;
  if (zeros >= 3) {
    // Subscript notation: 0.0₃160 means 0.000160
    const sigStart = zeros + 2; // skip "0."
    const sigDigits = str.slice(sigStart, sigStart + 4).replace(/0+$/, '') || '0';
    const subscript = String(zeros).split('').map(d => '₀₁₂₃₄₅₆₇₈₉'[d]).join('');
    return sign + '$0.0' + '<sub>' + subscript + '</sub>' + sigDigits;
  }
  // Small price with 0-2 leading zeros — show 6 significant digits
  return sign + '$' + parseFloat(abs.toPrecision(6));
}

function timeAgo(ts) {
  if (!ts) return '-';
  const diff = Math.floor(Date.now() / 1000 - ts);
  if (diff < 60) return diff + 's ago';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
  return Math.floor(diff / 86400) + 'd ago';
}

function reasonBadge(reason) {
  const colors = {
    take_profit: '#3fb950',
    stop_loss: '#f85149',
    expired: '#d29922',
    manual: '#8b949e',
  };
  const color = colors[reason] || '#8b949e';
  return `<span style="color:${color};font-weight:bold;font-size:0.85em">${reason.replace('_', ' ').toUpperCase()}</span>`;
}

async function closePosition(id, btn) {
  if (!confirm('Close this position at market price?')) return;
  btn.disabled = true;
  btn.textContent = 'Closing...';
  try {
    const res = await fetch('/api/close', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ position_id: id }),
    });
    const data = await res.json();
    if (data.ok) {
      btn.textContent = 'Closed';
      fetchData();
    } else {
      alert(data.message || 'Failed to close');
      btn.disabled = false;
      btn.textContent = 'Close';
    }
  } catch (e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'Close';
  }
}

function sideBadge(side) {
  if (!side) return '';
  const cls = side === 'LONG' ? 'side-long' : 'side-short';
  return `<span class="${cls}">${side}</span>`;
}

function scoreBar(score) {
  const color = score >= 70 ? '#3fb950' : score >= 50 ? '#d29922' : '#f85149';
  return `<span class="score-bar"><span class="score-fill" style="width:${score}%;background:${color}"></span></span> ${score}`;
}

function render(data) {
  lastData = data;
  const s = data.summary;
  const strategy = s.strategy || 'pump';

  // Badge
  document.getElementById('badge').innerHTML = s.dry_run
    ? '<span class="dry-run-badge">DRY RUN</span>'
    : '<span class="live-badge">LIVE</span>';

  // Strategy badge
  document.getElementById('strategy-badge').innerHTML = strategy === 'trend'
    ? '<span style="background:#1f6feb;color:#fff;padding:2px 8px;border-radius:4px;font-size:0.55em;font-weight:bold;vertical-align:middle;">TREND</span>'
    : '<span style="background:#8957e5;color:#fff;padding:2px 8px;border-radius:4px;font-size:0.55em;font-weight:bold;vertical-align:middle;">PUMP</span>';

  // Subtitle
  document.getElementById('signals-count').textContent = s.active_signals;
  document.getElementById('positions-count-sub').textContent = s.open_positions;
  document.getElementById('updated').textContent = new Date(data.ts * 1000).toLocaleTimeString();

  // Cards
  document.getElementById('balance').textContent = '$' + s.balance.toFixed(2);

  document.getElementById('combined').className = 'card-value ' + pnlClass(s.combined_pnl);
  document.getElementById('combined').textContent = pnlStr(s.combined_pnl);

  document.getElementById('unrealized').className = 'card-value ' + pnlClass(s.unrealized_pnl);
  document.getElementById('unrealized').textContent = pnlStr(s.unrealized_pnl);

  document.getElementById('realized').className = 'card-value ' + pnlClass(s.realized_pnl);
  document.getElementById('realized').textContent = pnlStr(s.realized_pnl);

  document.getElementById('winrate').className = 'card-value ' + (s.win_rate >= 50 ? 'positive' : s.win_rate > 0 ? 'negative' : 'neutral');
  document.getElementById('winrate').textContent = s.win_rate + '% (' + s.winning_trades + '/' + s.total_trades + ')';

  document.getElementById('positions-count').textContent = s.open_positions;
  document.getElementById('invested').textContent = '$' + s.total_invested.toFixed(2);

  // Positions table
  const posBody = document.getElementById('positions-body');
  if (data.positions.length === 0) {
    posBody.innerHTML = '<tr><td colspan="12" class="empty">No open positions</td></tr>';
  } else {
    const sorted = sortData(data.positions.map(p => ({
      ...p,
      current_value: p.position_size_usdt + (p.unrealized_pnl || 0),
    })), sortState.positions.key, sortState.positions.asc);
    posBody.innerHTML = sorted.map(p => `<tr>
      <td><strong>${p.symbol}</strong>${p.is_dry_run ? ' <span class="dry-run-badge" style="font-size:0.6em">DRY</span>' : ''}</td>
      <td>${sideBadge(p.side)}</td>
      <td>${formatPrice(p.entry_price)}</td>
      <td>${formatPrice(p.current_price)}</td>
      <td>$${p.position_size_usdt.toFixed(2)}</td>
      <td class="${pnlClass(p.unrealized_pnl)}">$${p.current_value.toFixed(2)}</td>
      <td class="${pnlClass(p.unrealized_pnl)}">${pnlStr(p.unrealized_pnl)}</td>
      <td class="${pnlClass(p.pnl_pct)}">${p.pnl_pct >= 0 ? '+' : ''}${p.pnl_pct.toFixed(2)}%</td>
      <td>${formatPrice(p.stop_loss_price)}</td>
      <td>${formatPrice(p.take_profit_price)}</td>
      <td>${timeAgo(p.opened_at)}</td>
      <td><button class="btn-close-pos" onclick="closePosition(${p.id}, this)">Close</button></td>
    </tr>`).join('');
  }

  // Signals tables — show the right one based on strategy
  const isTrend = strategy === 'trend';
  document.getElementById('trend-signals-table').style.display = isTrend ? '' : 'none';
  document.getElementById('pump-signals-table').style.display = isTrend ? 'none' : '';
  document.getElementById('signals-title').textContent = isTrend ? 'Active Trend Signals' : 'Active Pump Signals';
  document.getElementById('signals-tab-btn').textContent = isTrend ? 'Trend Signals' : 'Pump Signals';

  // Trend signals
  const trendBody = document.getElementById('trend-signals-body');
  const trendSignals = data.trend_signals || [];
  if (trendSignals.length === 0) {
    trendBody.innerHTML = '<tr><td colspan="10" class="empty">No active trend signals</td></tr>';
  } else {
    trendBody.innerHTML = trendSignals.map(s => `<tr>
      <td><strong>${s.symbol}</strong></td>
      <td><span class="signal-status signal-${s.direction.toLowerCase()}">${s.direction}</span></td>
      <td>${scoreBar(s.score)}</td>
      <td>${s.ema_cross ? '✓ Fresh' : '—'}</td>
      <td>${s.rsi_value !== null ? s.rsi_value.toFixed(1) : '—'}</td>
      <td>${s.macd_histogram !== null ? s.macd_histogram.toFixed(6) : '—'}</td>
      <td>${s.volume_ratio !== null ? s.volume_ratio.toFixed(1) + 'x' : '—'}</td>
      <td>${formatPrice(s.entry_price)}</td>
      <td><span class="signal-status signal-detected">${s.status.replace('_', ' ').toUpperCase()}</span></td>
      <td>${timeAgo(s.created_at)}</td>
    </tr>`).join('');
  }

  // Pump signals
  const pumpBody = document.getElementById('pump-signals-body');
  const pumpSignals = data.pump_signals || [];
  if (pumpSignals.length === 0) {
    pumpBody.innerHTML = '<tr><td colspan="8" class="empty">No active pump signals</td></tr>';
  } else {
    pumpBody.innerHTML = pumpSignals.map(s => `<tr>
      <td><strong>${s.symbol}</strong></td>
      <td class="positive">+${s.price_change_pct.toFixed(2)}%</td>
      <td>${s.volume_multiplier.toFixed(1)}x</td>
      <td>${formatPrice(s.peak_price)}</td>
      <td>${formatPrice(s.current_price)}</td>
      <td class="${s.drop_from_peak_pct > 0 ? 'negative' : 'neutral'}">${s.drop_from_peak_pct.toFixed(2)}%</td>
      <td><span class="signal-status ${s.status === 'reversal_confirmed' ? 'signal-reversal' : 'signal-detected'}">${s.status.replace('_', ' ').toUpperCase()}</span></td>
      <td>${timeAgo(s.created_at)}</td>
    </tr>`).join('');
  }

  // History table
  const histBody = document.getElementById('history-body');
  if (data.recent_trades.length === 0) {
    histBody.innerHTML = '<tr><td colspan="9" class="empty">No closed trades yet</td></tr>';
  } else {
    const sorted = sortData(data.recent_trades, sortState.history.key, sortState.history.asc);
    histBody.innerHTML = sorted.map(t => `<tr>
      <td><strong>${t.symbol}</strong>${t.is_dry_run ? ' <span class="dry-run-badge" style="font-size:0.6em">DRY</span>' : ''}</td>
      <td>${sideBadge(t.side)}</td>
      <td>${formatPrice(t.entry_price)}</td>
      <td>${formatPrice(t.exit_price)}</td>
      <td>${t.quantity.toFixed(4)}</td>
      <td class="${pnlClass(t.pnl)}">${pnlStr(t.pnl)}</td>
      <td class="${pnlClass(t.pnl_pct)}">${t.pnl_pct >= 0 ? '+' : ''}${t.pnl_pct.toFixed(2)}%</td>
      <td>${reasonBadge(t.close_reason)}</td>
      <td>${timeAgo(t.created_at)}</td>
    </tr>`).join('');
  }
}

async function loadSettings() {
  try {
    const res = await fetch('/api/settings');
    const data = await res.json();
    const container = document.getElementById('settings-form');

    document.getElementById('settings-exchange').textContent = data.exchange.driver.toUpperCase();
    document.getElementById('settings-testnet').textContent = data.exchange.testnet ? 'Yes' : 'No';

    const settings = data.settings;
    let html = '';

    for (const [key, meta] of Object.entries(settings)) {
      if (meta.type === 'bool') {
        html += `<div class="setting-item">
          <label>${meta.label}</label>
          <select data-key="${key}">
            <option value="true" ${meta.value ? 'selected' : ''}>Yes</option>
            <option value="false" ${!meta.value ? 'selected' : ''}>No</option>
          </select>
        </div>`;
      } else if (key === 'strategy') {
        html += `<div class="setting-item">
          <label>${meta.label}</label>
          <select data-key="${key}">
            <option value="trend" ${meta.value === 'trend' ? 'selected' : ''}>Trend Following</option>
            <option value="pump" ${meta.value === 'pump' ? 'selected' : ''}>Pump &amp; Dump</option>
          </select>
        </div>`;
      } else if (meta.type === 'string') {
        html += `<div class="setting-item">
          <label>${meta.label}</label>
          <input type="text" data-key="${key}" value="${meta.value || ''}" />
        </div>`;
      } else {
        const step = meta.type === 'float' ? '0.1' : '1';
        html += `<div class="setting-item">
          <label>${meta.label}</label>
          <input type="number" data-key="${key}" value="${meta.value}" step="${step}" min="0" />
        </div>`;
      }
    }

    html += `<div style="grid-column: 1 / -1; margin-top: 4px;">
      <button class="btn-save" onclick="saveSettings(this)">Save Settings</button>
    </div>`;

    container.innerHTML = html;
  } catch (e) {
    console.error('Failed to load settings:', e);
  }
}

async function saveSettings(btn) {
  btn.disabled = true;
  btn.textContent = 'Saving...';
  const msg = document.getElementById('settings-msg');
  msg.textContent = '';

  const settings = {};
  document.querySelectorAll('#settings-form [data-key]').forEach(el => {
    settings[el.dataset.key] = el.value;
  });

  try {
    const res = await fetch('/api/settings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ settings }),
    });
    const data = await res.json();
    if (data.ok) {
      msg.style.color = '#3fb950';
      msg.textContent = 'Settings saved. Changes apply to the next scan cycle.';
      fetchData();
    } else {
      msg.style.color = '#f85149';
      msg.textContent = data.message || 'Failed to save';
    }
  } catch (e) {
    msg.style.color = '#f85149';
    msg.textContent = 'Error: ' + e.message;
  }

  btn.disabled = false;
  btn.textContent = 'Save Settings';
}

async function scanNow(btn) {
  btn.disabled = true;
  btn.textContent = 'Scanning...';
  const msg = document.getElementById('scan-msg');
  msg.textContent = '';

  try {
    const res = await fetch('/api/scan', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ auto_trade: true }),
    });
    const data = await res.json();
    if (data.ok) {
      let text = `[${(data.strategy || 'pump').toUpperCase()}] Found ${data.signals} signal(s)`;
      if (data.reversals !== undefined) text += `, ${data.reversals} reversal(s)`;
      if (data.trades_opened.length > 0) {
        text += ` — opened: ${data.trades_opened.join(', ')}`;
      }
      msg.style.color = '#3fb950';
      msg.textContent = text;
      fetchData();
    }
  } catch (e) {
    msg.style.color = '#f85149';
    msg.textContent = 'Error: ' + e.message;
  }

  btn.disabled = false;
  btn.textContent = 'Scan Now';
}

async function resetAll(btn) {
  if (!confirm('This will delete ALL trades, positions, and signals. Are you sure?')) return;
  btn.disabled = true;
  btn.textContent = 'Resetting...';
  try {
    const res = await fetch('/api/reset', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    });
    const data = await res.json();
    if (data.ok) {
      btn.textContent = 'Done';
      fetchData();
      setTimeout(() => { btn.disabled = false; btn.textContent = 'Reset'; }, 2000);
    }
  } catch (e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'Reset';
  }
}

async function fetchData() {
  try {
    const res = await fetch('/api/data');
    const data = await res.json();
    render(data);
  } catch (e) {
    console.error('Failed to fetch data:', e);
  }
}

// Initial load
fetchData();
loadSettings();
setInterval(fetchData, 10000);
</script>
</body>
</html>
