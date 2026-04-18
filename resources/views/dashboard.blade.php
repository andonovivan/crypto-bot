<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Short-Scalp Bot</title>
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
  .btn-add-pos { background: #1f6feb; color: #fff; border: none; border-radius: 4px;
    padding: 3px 10px; cursor: pointer; font-size: 0.75em; white-space: nowrap; margin-right: 4px; }
  .btn-add-pos:hover { background: #388bfd; }
  .btn-add-pos:disabled { opacity: 0.4; cursor: default; }
  .btn-reverse-pos { background: #8957e5; color: #fff; border: none; border-radius: 4px;
    padding: 3px 10px; cursor: pointer; font-size: 0.75em; white-space: nowrap; margin-right: 4px; }
  .btn-reverse-pos:hover { background: #a371f7; }
  .btn-reverse-pos:disabled { opacity: 0.4; cursor: default; }
  .add-input { width: 55px; background: #0d1117; border: 1px solid #30363d; border-radius: 4px;
    color: #c9d1d9; padding: 3px 5px; font-size: 0.7em; text-align: right; font-family: monospace; margin-right: 4px; }
  .pagination { display: flex; align-items: center; gap: 8px; margin: 8px 0 20px; }
  .pagination button { background: #21262d; color: #c9d1d9; border: 1px solid #30363d;
    border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 0.8em; }
  .pagination button:hover { background: #30363d; }
  .pagination button:disabled { opacity: 0.4; cursor: default; }
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
  .btn-pause { background: #30363d; color: #c9d1d9; border: 1px solid #484f58; border-radius: 6px;
    padding: 8px 16px; cursor: pointer; font-size: 0.9em; margin-right: 8px; }
  .btn-pause:hover { background: #484f58; }
  .btn-pause[data-paused] { background: #da3633; color: #fff; border-color: #da3633; }
  .btn-pause[data-paused]:hover { background: #f85149; }
  .btn-open-short { background: #da3633; color: #fff; border: none; border-radius: 4px;
    padding: 3px 10px; cursor: pointer; font-size: 0.75em; white-space: nowrap; }
  .btn-open-short:hover { background: #f85149; }
  .btn-open-short:disabled { opacity: 0.4; cursor: default; }
  .btn-scan { background: #1f6feb; color: #fff; border: none; border-radius: 6px;
    padding: 8px 16px; cursor: pointer; font-size: 0.9em; }
  .btn-scan:hover { background: #388bfd; }
  .btn-scan:disabled { opacity: 0.5; cursor: default; }
  .pill { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 0.7em;
    font-weight: bold; text-transform: uppercase; letter-spacing: 0.03em; }
  .pill-pump { background: #238636; color: #fff; }
  .pill-dump { background: #da3633; color: #fff; }
  .pill-down { background: #238636; color: #fff; }
  .pill-up { background: #da3633; color: #fff; }
  .pill-flat { background: #484f58; color: #c9d1d9; }
  /*noinspection CssUnusedSymbol*/
  .pill-red { background: #238636; color: #fff; }
  /*noinspection CssUnusedSymbol*/
  .pill-green { background: #da3633; color: #fff; }
  /*noinspection CssUnusedSymbol*/
  .pill-amber { background: #9e6a03; color: #fff; }
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
<h1>Short-Scalp Bot <span id="badge"></span></h1>
<p class="subtitle">
  <span id="positions-count-sub">-</span> open positions &middot;
  Updated <span id="updated">-</span>
</p>

<div class="tabs">
  <button class="tab-btn active" onclick="switchTab('dashboard')">Dashboard</button>
  <button class="tab-btn" onclick="switchTab('scanner')">Scanner</button>
  <button class="tab-btn" onclick="switchTab('history')">Trade History</button>
  <button class="tab-btn" onclick="switchTab('settings')">Settings</button>
</div>

<!-- Dashboard Tab -->
<div id="tab-dashboard" class="tab-pane active">
  <div class="cards">
    <div class="card">
      <div class="card-label">Wallet Balance</div>
      <div class="card-value neutral" id="balance">-</div>
    </div>
    <div class="card">
      <div class="card-label">Available Balance</div>
      <div class="card-value neutral" id="available-balance">-</div>
    </div>
    <div class="card">
      <div class="card-label">Margin in Use</div>
      <div class="card-value neutral" id="margin-in-use">-</div>
    </div>
    <div class="card">
      <div class="card-label">P&amp;L (Net)</div>
      <div class="card-value" id="net-pnl">-</div>
    </div>
    <div class="card">
      <div class="card-label">Fees</div>
      <div class="card-value" id="total-fees" style="color:#f85149">-</div>
    </div>
    <div class="card">
      <div class="card-label">Funding</div>
      <div class="card-value" id="total-funding">-</div>
    </div>
    <div class="card">
      <div class="card-label">Win Rate</div>
      <div class="card-value" id="winrate">-</div>
    </div>
  </div>

  <div style="display:flex; align-items:center; justify-content:space-between; margin-top:24px; margin-bottom:12px;">
    <h2 class="section-title" style="margin:0;">Open Positions</h2>
    <button id="close-all-btn" class="btn-close-pos" style="padding:6px 14px; font-size:0.85em;" onclick="closeAll(this)">Close All</button>
  </div>
  <table>
    <thead>
      <tr>
        <th onclick="sortTable('positions', 'symbol')">Symbol <span class="sort-arrow" id="pos-sort-symbol"></span></th>
        <th onclick="sortTable('positions', 'entry_price')">Entry Price <span class="sort-arrow" id="pos-sort-entry_price"></span></th>
        <th onclick="sortTable('positions', 'current_price')">Current Price <span class="sort-arrow" id="pos-sort-current_price"></span></th>
        <th onclick="sortTable('positions', 'unrealized_pnl')">Unrealized PnL <span class="sort-arrow" id="pos-sort-unrealized_pnl"></span></th>
        <th onclick="sortTable('positions', 'pnl_pct')">PnL % <span class="sort-arrow" id="pos-sort-pnl_pct"></span></th>
        <th onclick="sortTable('positions', 'position_size_usdt')">Size <span class="sort-arrow" id="pos-sort-position_size_usdt"></span></th>
        <th onclick="sortTable('positions', 'net_pnl')">Net PnL <span class="sort-arrow" id="pos-sort-net_pnl"></span></th>
        <th>SL / TP</th>
        <th onclick="sortTable('positions', 'opened_at')">Time Opened <span class="sort-arrow" id="pos-sort-opened_at"></span></th>
        <th>Hold</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="positions-body"><tr><td colspan="11" class="empty">Loading...</td></tr></tbody>
  </table>
</div>

<!-- Scanner Tab -->
<div id="tab-scanner" class="tab-pane">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
    <h2 class="section-title" style="margin:0;">Market Scanner — Pumps &amp; Dumps</h2>
    <div style="display:flex; align-items:center;">
      <span class="settings-msg" id="scan-msg" style="margin-right:8px;"></span>
      <button class="btn-pause" id="pause-btn" onclick="togglePause(this)" style="margin-top:0;">⏸ Pause</button>
      <button class="btn-scan" id="scan-btn" onclick="fetchScannerData(this)">Scan</button>
      <button class="btn-save" style="margin-top:0; margin-left:8px;" onclick="scanNow(this)">Scan + Auto Trade</button>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th onclick="sortTable('scanner', 'symbol')">Symbol <span class="sort-arrow" id="scan-sort-symbol"></span></th>
        <th onclick="sortTable('scanner', 'price_change_pct')">24h % <span class="sort-arrow" id="scan-sort-price_change_pct"></span></th>
        <th onclick="sortTable('scanner', 'volume')">Volume <span class="sort-arrow" id="scan-sort-volume"></span></th>
        <th onclick="sortTable('scanner', 'price')">Price <span class="sort-arrow" id="scan-sort-price"></span></th>
        <th>15m Trend</th>
        <th onclick="sortTable('scanner', 'candle_body_pct')">Last Candle <span class="sort-arrow" id="scan-sort-candle_body_pct"></span></th>
        <th onclick="sortTable('scanner', 'funding_rate')">Funding <span class="sort-arrow" id="scan-sort-funding_rate"></span></th>
        <th onclick="sortTable('scanner', 'open_positions')">Pos <span class="sort-arrow" id="scan-sort-open_positions"></span></th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody id="scanner-body"><tr><td colspan="10" class="empty">Click Scan to find pump/dump candidates</td></tr></tbody>
  </table>

  <div style="margin-top:16px; padding:16px; background:#161b22; border:1px solid #30363d; border-radius:8px; display:flex; align-items:center; gap:12px;">
    <span style="color:#8b949e; font-size:0.85em; white-space:nowrap;">Manual Open SHORT:</span>
    <select id="manual-symbol" style="background:#0d1117; border:1px solid #30363d; border-radius:4px; color:#c9d1d9; padding:6px 10px; font-size:0.85em;">
      <option value="">Select symbol...</option>
    </select>
    <button class="btn-open-short" style="padding:6px 16px; font-size:0.85em;" onclick="openManualPosition(this)">Open SHORT</button>
    <span id="manual-msg" class="settings-msg" style="margin:0;"></span>
  </div>
</div>

<!-- History Tab -->
<div id="tab-history" class="tab-pane">
  <h2 class="section-title">Trade History</h2>
  <table>
    <thead>
      <tr>
        <th onclick="sortTable('history', 'symbol')">Symbol <span class="sort-arrow" id="hist-sort-symbol"></span></th>
        <th onclick="sortTable('history', 'entry_price')">Entry Price <span class="sort-arrow" id="hist-sort-entry_price"></span></th>
        <th onclick="sortTable('history', 'exit_price')">Exit Price <span class="sort-arrow" id="hist-sort-exit_price"></span></th>
        <th onclick="sortTable('history', 'net_pnl')">Realized PnL <span class="sort-arrow" id="hist-sort-net_pnl"></span></th>
        <th onclick="sortTable('history', 'pnl_pct')">PnL % <span class="sort-arrow" id="hist-sort-pnl_pct"></span></th>
        <th onclick="sortTable('history', 'position_size_usdt')">Size <span class="sort-arrow" id="hist-sort-position_size_usdt"></span></th>
        <th onclick="sortTable('history', 'fees')">Fees <span class="sort-arrow" id="hist-sort-fees"></span></th>
        <th>Reason</th>
        <th onclick="sortTable('history', 'opened_at')">Time Opened <span class="sort-arrow" id="hist-sort-opened_at"></span></th>
        <th onclick="sortTable('history', 'created_at')">Time Closed <span class="sort-arrow" id="hist-sort-created_at"></span></th>
      </tr>
    </thead>
    <tbody id="history-body"><tr><td colspan="10" class="empty">Loading...</td></tr></tbody>
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
      <div style="color:#8b949e; font-size:0.8em; margin-top:2px;">Deletes all trades and positions. Cannot be undone.</div>
    </div>
    <button class="btn-close-pos" style="padding:6px 16px; font-size:0.85em;" onclick="resetAll(this)">Reset</button>
  </div>
</div>

<div class="footer">Dashboard auto-refreshes every 10s · Scanner auto-refreshes every 15s when visible</div>

<script>
const PAGE_SIZE = 15;
let lastData = null;

// Sorting state
const sortState = {
  positions: { key: 'opened_at', asc: false },
  history: { key: 'created_at', asc: false },
  scanner: { key: 'price_change_pct', asc: false },
};
let scannerData = null;
let scannerLoaded = false;
let scannerTimer = null;
let activeTab = 'dashboard';

function switchTab(name) {
  activeTab = name;
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + name).classList.add('active');
  document.querySelector('.tab-btn[onclick*="' + name + '"]').classList.add('active');
  if (name === 'scanner') {
    if (!scannerLoaded) {
      fetchScannerData(document.getElementById('scan-btn'));
    }
    startScannerAutoRefresh();
  } else {
    stopScannerAutoRefresh();
  }
}

function startScannerAutoRefresh() {
  stopScannerAutoRefresh();
  scannerTimer = setInterval(() => {
    if (activeTab === 'scanner' && !document.hidden) {
      fetchScannerData(null);
    }
  }, 15000);
}

function stopScannerAutoRefresh() {
  if (scannerTimer) { clearInterval(scannerTimer); scannerTimer = null; }
}

document.addEventListener('visibilitychange', () => {
  if (document.hidden) stopScannerAutoRefresh();
  else if (activeTab === 'scanner') startScannerAutoRefresh();
});

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
  if (table === 'scanner' && scannerData) { renderScanner(scannerData); }
  else if (lastData) { render(lastData); }
}

function fmtNum(val, decimals) {
  return val.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function fmtVolume(v) {
  if (v === null || v === undefined) return '-';
  if (v >= 1e9) return '$' + (v / 1e9).toFixed(2) + 'B';
  if (v >= 1e6) return '$' + (v / 1e6).toFixed(2) + 'M';
  if (v >= 1e3) return '$' + (v / 1e3).toFixed(1) + 'K';
  return '$' + fmtNum(v, 0);
}

function pnlColor(val) {
  if (val > 0) return '#3fb950';
  if (val < 0) return '#f85149';
  return '#c9d1d9';
}

function pnlStr(val) {
  if (val === null || val === undefined) return '-';
  const prefix = val >= 0 ? '+$' : '-$';
  return prefix + fmtNum(Math.abs(val), 2);
}

function fundingColor(side, rate) {
  const earning = (side === 'LONG' && rate < 0) || (side === 'SHORT' && rate > 0);
  return earning ? '#3fb950' : '#f85149';
}

function fundingLabel(side, rate) {
  const earning = (side === 'LONG' && rate < 0) || (side === 'SHORT' && rate > 0);
  return earning ? 'earning' : 'paying';
}

function formatPrice(val) {
  if (val === null || val === undefined) return '-';
  if (val === 0) return '$0';
  const abs = Math.abs(val);
  const sign = val < 0 ? '-' : '';
  if (abs >= 100) return sign + '$' + fmtNum(abs, 2);
  if (abs >= 1) return sign + '$' + fmtNum(abs, 4);
  const str = abs.toFixed(20).replace(/0+$/, '');
  const zeros = (str.match(/^0\.(0+)/) || [null, ''])[1].length;
  if (zeros >= 3) {
    const sigStart = zeros + 2;
    const sigDigits = str.slice(sigStart, sigStart + 4).replace(/0+$/, '') || '0';
    const subscript = String(zeros).split('').map(d => '₀₁₂₃₄₅₆₇₈₉'[d]).join('');
    return sign + '$0.0' + '<sub>' + subscript + '</sub>' + sigDigits;
  }
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

function formatTimestamp(ts) {
  if (!ts) return '-';
  const d = new Date(ts * 1000);
  const pad = n => String(n).padStart(2, '0');
  return d.getFullYear() + '/' + pad(d.getMonth()+1) + '/' + pad(d.getDate())
    + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

function holdTime(openedTs, expiresTs) {
  if (!openedTs) return '-';
  const now = Math.floor(Date.now() / 1000);
  const held = Math.floor((now - openedTs) / 60);
  let str = held + 'm';
  if (expiresTs) {
    const remaining = Math.max(0, Math.floor((expiresTs - now) / 60));
    str += ` <span style="color:#8b949e;font-size:0.8em">(${remaining}m left)</span>`;
  }
  return str;
}

function reasonBadge(reason) {
  const colors = {
    take_profit: '#3fb950',
    stop_loss: '#f85149',
    expired: '#d29922',
    manual: '#8b949e',
    reversed: '#8957e5',
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

async function closeAll(btn) {
  if (!confirm('Close ALL open positions at market price?')) return;
  btn.disabled = true;
  btn.textContent = 'Closing all...';
  try {
    const res = await fetch('/api/close-all', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
    });
    const data = await res.json();
    if (data.ok) {
      btn.textContent = `Closed ${data.closed}`;
    } else {
      const msg = data.failed?.map(f => `${f.symbol}: ${f.error}`).join('\n') || 'Unknown error';
      alert(`Closed ${data.closed}, failed ${data.failed?.length || 0}:\n${msg}`);
    }
    fetchData();
    setTimeout(() => { btn.disabled = false; btn.textContent = 'Close All'; }, 2000);
  } catch (e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'Close All';
  }
}

async function addToPosition(id, btn) {
  const input = document.getElementById('add-amt-' + id);
  const amount = parseFloat(input?.value);
  if (!amount || amount <= 0) {
    alert('Enter a USDT amount to add');
    return;
  }
  if (!confirm('Add $' + amount.toFixed(2) + ' USDT to this position?')) return;
  btn.disabled = true;
  btn.textContent = 'Adding...';
  try {
    const res = await fetch('/api/add-margin', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ position_id: id, amount_usdt: amount }),
    });
    const data = await res.json();
    if (data.ok) {
      btn.textContent = 'Added!';
      if (input) input.value = '';
      fetchData();
      setTimeout(() => { btn.disabled = false; btn.textContent = 'Add'; }, 1500);
    } else {
      alert(data.message || 'Failed to add');
      btn.disabled = false;
      btn.textContent = 'Add';
    }
  } catch (e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'Add';
  }
}

async function reversePosition(id, btn) {
  if (!confirm('Reverse this position? This will close it and open a new one in the opposite direction with the same USDT size.\n\nNote: the bot only manages SHORT positions. A reversed LONG will need to be closed manually.')) return;
  btn.disabled = true;
  btn.textContent = 'Reversing...';
  try {
    const res = await fetch('/api/reverse', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ position_id: id }),
    });
    const data = await res.json();
    if (data.ok) {
      if (data.warning) {
        alert(data.warning);
      }
      btn.textContent = 'Reversed!';
      fetchData();
    } else {
      alert(data.message || 'Failed to reverse');
      btn.disabled = false;
      btn.textContent = 'Reverse';
    }
  } catch (e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.textContent = 'Reverse';
  }
}

function sideBadge(side) {
  if (!side) return '';
  const color = side === 'LONG' ? '#3fb950' : '#f85149';
  return `<span style="color:${color};font-weight:bold;font-size:0.75em">${side}</span>`;
}

function render(data) {
  lastData = data;
  const s = data.summary;

  document.getElementById('badge').innerHTML = s.dry_run
    ? '<span class="dry-run-badge">DRY RUN</span>'
    : '<span class="live-badge">LIVE</span>';

  updatePauseButton(s.trading_paused);

  document.getElementById('positions-count-sub').textContent = s.open_positions;
  document.getElementById('updated').textContent = new Date(data.ts * 1000).toLocaleTimeString();

  document.getElementById('balance').textContent = '$' + fmtNum(s.wallet_balance, 2);
  document.getElementById('available-balance').textContent = '$' + fmtNum(s.available_balance, 2);
  document.getElementById('margin-in-use').textContent = '$' + fmtNum(s.margin_in_use, 2);

  document.getElementById('net-pnl').className = 'card-value';
  document.getElementById('net-pnl').style.color = pnlColor(s.net_pnl);
  document.getElementById('net-pnl').textContent = pnlStr(s.net_pnl);

  document.getElementById('total-fees').textContent = '-$' + fmtNum(Math.abs(s.total_fees), 2);

  document.getElementById('total-funding').className = 'card-value';
  document.getElementById('total-funding').style.color = pnlColor(s.total_funding);
  document.getElementById('total-funding').textContent = pnlStr(s.total_funding);

  document.getElementById('winrate').className = 'card-value';
  document.getElementById('winrate').style.color = s.win_rate >= 50 ? '#3fb950' : s.win_rate > 0 ? '#f85149' : '#c9d1d9';
  document.getElementById('winrate').textContent = s.win_rate + '% (' + s.winning_trades + '/' + s.total_trades + ')';

  const posBody = document.getElementById('positions-body');
  if (data.positions.length === 0) {
    posBody.innerHTML = '<tr><td colspan="11" class="empty">No open positions</td></tr>';
  } else {
    const sorted = sortData([...data.positions], sortState.positions.key, sortState.positions.asc);
    posBody.innerHTML = sorted.map(p => `<tr>
      <td>
        <strong>${p.symbol}</strong><br>
        ${sideBadge(p.side)}
        <span style="color:#8b949e;font-size:0.7em;margin-left:2px">${p.leverage || '-'}x</span>
        ${p.is_dry_run ? ' <span class="dry-run-badge" style="font-size:0.55em">DRY</span>' : ''}
      </td>
      <td>${formatPrice(p.entry_price)}</td>
      <td>${formatPrice(p.current_price)}</td>
      <td style="color:${pnlColor(p.unrealized_pnl)}">${pnlStr(p.unrealized_pnl)}</td>
      <td style="color:${pnlColor(p.pnl_pct)};font-weight:bold">${p.pnl_pct >= 0 ? '+' : ''}${p.pnl_pct.toFixed(2)}%</td>
      <td>${fmtNum(p.position_size_usdt, 2)} USDT</td>
      <td style="color:${pnlColor(p.net_pnl)};font-weight:bold">${pnlStr(p.net_pnl)}<br><span style="color:#8b949e;font-weight:normal;font-size:0.75em">-$${fmtNum(p.estimated_fees || 0, 4)} fees</span>${p.funding_rate !== null ? `<br><span style="font-weight:normal;font-size:0.75em;color:${fundingColor(p.side, p.funding_rate)}">${(Math.abs(p.funding_rate) * 100).toFixed(4)}% ${fundingLabel(p.side, p.funding_rate)}</span>` : ''}${p.funding_fee ? `<br><span style="font-weight:normal;font-size:0.75em;color:${pnlColor(p.funding_fee)}">${pnlStr(p.funding_fee)} funding</span>` : ''}</td>
      <td>${formatPrice(p.stop_loss_price)} / ${formatPrice(p.take_profit_price)}</td>
      <td style="font-size:0.85em">${formatTimestamp(p.opened_at)}</td>
      <td>${holdTime(p.opened_at, p.expires_at)}</td>
      <td style="white-space:nowrap">
        <input type="number" id="add-amt-${p.id}" class="add-input" placeholder="USDT" min="1" step="1" />
        <button class="btn-add-pos" onclick="addToPosition(${p.id}, this)">Add</button>
        <button class="btn-reverse-pos" onclick="reversePosition(${p.id}, this)">Reverse</button>
        <button class="btn-close-pos" onclick="closePosition(${p.id}, this)">Close</button>
      </td>
    </tr>`).join('');
  }

  const histBody = document.getElementById('history-body');
  if (data.recent_trades.length === 0) {
    histBody.innerHTML = '<tr><td colspan="10" class="empty">No closed trades yet</td></tr>';
  } else {
    const trades = data.recent_trades.map(t => ({ ...t, net_pnl: t.pnl + (t.funding_fee || 0) }));
    const sorted = sortData(trades, sortState.history.key, sortState.history.asc);
    histBody.innerHTML = sorted.map(t => {
      return `<tr>
      <td>
        <strong>${t.symbol}</strong><br>
        ${sideBadge(t.side)}
        <span style="color:#8b949e;font-size:0.7em;margin-left:2px">${t.leverage || '-'}x</span>
        ${t.is_dry_run ? ' <span class="dry-run-badge" style="font-size:0.55em">DRY</span>' : ''}
      </td>
      <td>${formatPrice(t.entry_price)}</td>
      <td>${formatPrice(t.exit_price)}</td>
      <td style="color:${pnlColor(t.net_pnl)};font-weight:bold">${pnlStr(t.net_pnl)}</td>
      <td style="color:${pnlColor(t.pnl_pct)};font-weight:bold">${t.pnl_pct >= 0 ? '+' : ''}${t.pnl_pct.toFixed(2)}%</td>
      <td>${t.position_size_usdt ? fmtNum(t.position_size_usdt, 2) + ' USDT' : '-'}</td>
      <td style="color:#f85149">-$${fmtNum(t.fees || 0, 4)}${t.funding_fee ? `<br><span style="color:${pnlColor(t.funding_fee)};font-size:0.8em">${pnlStr(t.funding_fee)} fund</span>` : ''}</td>
      <td>${reasonBadge(t.close_reason)}</td>
      <td style="font-size:0.85em">${formatTimestamp(t.opened_at)}</td>
      <td style="font-size:0.85em">${formatTimestamp(t.created_at)}</td>
    </tr>`;
    }).join('');
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

async function togglePause(btn) {
  btn.disabled = true;
  const isPaused = btn.hasAttribute('data-paused');
  try {
    await fetch('/api/settings', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ settings: { trading_paused: !isPaused } }),
    });
    updatePauseButton(!isPaused);
    fetchData();
  } catch (e) {
    console.error('Toggle pause failed:', e);
  }
  btn.disabled = false;
}

function updatePauseButton(paused) {
  const btn = document.getElementById('pause-btn');
  if (paused) {
    btn.setAttribute('data-paused', '');
    btn.textContent = '\u25B6 Resume';
  } else {
    btn.removeAttribute('data-paused');
    btn.textContent = '\u23F8 Pause';
  }
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
      let text = `Found ${data.candidate_count} candidate(s)`;
      if (data.trades_opened && data.trades_opened.length > 0) {
        text += ` — opened: ${data.trades_opened.join(', ')}`;
      }
      msg.style.color = '#3fb950';
      msg.textContent = text;
      fetchData();
      fetchScannerData(document.getElementById('scan-btn'));
    }
  } catch (e) {
    msg.style.color = '#f85149';
    msg.textContent = 'Error: ' + e.message;
  }

  btn.disabled = false;
  btn.textContent = 'Scan + Auto Trade';
}

async function fetchScannerData(btn) {
  if (btn) { btn.disabled = true; btn.textContent = 'Scanning...'; }
  try {
    const res = await fetch('/api/scanner');
    const data = await res.json();
    if (data.ok) {
      scannerData = data;
      scannerLoaded = true;
      renderScanner(data);
      const sel = document.getElementById('manual-symbol');
      const current = sel.value;
      sel.innerHTML = '<option value="">Select symbol...</option>' +
        data.candidates.map(s => `<option value="${s.symbol}" ${s.symbol === current ? 'selected' : ''}>${s.symbol}</option>`).join('');
    }
  } catch (e) {
    console.error('Scanner fetch failed:', e);
  }
  if (btn) { btn.disabled = false; btn.textContent = 'Scan'; }
}

function reasonPill(reason) {
  if (reason === 'pump') return '<span class="pill pill-pump">PUMP</span>';
  if (reason === 'dump') return '<span class="pill pill-dump">DUMP</span>';
  return '';
}

function trendPill(c) {
  if (c.ema_fast === null || c.ema_slow === null) {
    return '<span class="pill pill-flat">-</span>';
  }
  if (c.ema_fast < c.ema_slow) return '<span class="pill pill-down">DOWN</span>';
  if (c.ema_fast > c.ema_slow) return '<span class="pill pill-up">UP</span>';
  return '<span class="pill pill-flat">FLAT</span>';
}

function candleCell(c) {
  if (c.last_candle_red === null || c.last_candle_red === undefined) return '-';
  const redCount = (c.last_candle_red ? 1 : 0) + (c.prior_candle_red ? 1 : 0);
  const pillClass = redCount === 2 ? 'pill-red' : (redCount === 1 ? 'pill-amber' : 'pill-green');
  const label = redCount === 2 ? '2/2 RED' : (redCount === 1 ? '1/2 RED' : '0/2 RED');
  const pill = `<span class="pill ${pillClass}">${label}</span>`;
  const body = c.candle_body_pct !== null && c.candle_body_pct !== undefined
    ? ` <span style="color:#8b949e;font-size:0.8em">${c.candle_body_pct.toFixed(2)}%</span>`
    : '';
  return pill + body;
}

function fundingCell(rate) {
  if (rate === null || rate === undefined) return '-';
  const pct = (rate * 100).toFixed(4);
  const color = rate < 0 ? '#f85149' : '#3fb950';
  return `<span style="color:${color}">${rate >= 0 ? '+' : ''}${pct}%</span>`;
}

function renderScanner(data) {
  const body = document.getElementById('scanner-body');
  const candidates = data.candidates || [];
  if (candidates.length === 0) {
    body.innerHTML = '<tr><td colspan="10" class="empty">No pump/dump candidates right now</td></tr>';
    return;
  }

  const sorted = sortData([...candidates], sortState.scanner.key, sortState.scanner.asc);
  body.innerHTML = sorted.map(c => {
    const changeColor = c.price_change_pct >= 0 ? '#3fb950' : '#f85149';
    const statusIcon = c.can_enter
      ? '<span style="color:#3fb950;font-weight:bold">&#10003;</span>'
      : '<span style="color:#f85149;font-weight:bold">&#10007;</span>';
    const reasonText = c.blocked_reasons && c.blocked_reasons.length > 0
      ? `<br><span style="color:#8b949e;font-size:0.75em" title="${c.blocked_reasons.join('\n')}">${c.blocked_reasons[0]}</span>`
      : '';

    return `<tr>
      <td><strong>${c.symbol}</strong><br>${reasonPill(c.reason)}</td>
      <td style="color:${changeColor};font-weight:bold">${c.price_change_pct >= 0 ? '+' : ''}${c.price_change_pct.toFixed(2)}%</td>
      <td>${fmtVolume(c.volume)}</td>
      <td>${formatPrice(c.price)}</td>
      <td>${trendPill(c)}</td>
      <td>${candleCell(c)}</td>
      <td>${fundingCell(c.funding_rate)}</td>
      <td>${c.open_positions}</td>
      <td>${statusIcon}${reasonText}</td>
      <td style="white-space:nowrap">
        <button class="btn-open-short" onclick="openPosition('${c.symbol}', this)">Short</button>
      </td>
    </tr>`;
  }).join('');
}

async function openPosition(symbol, btn) {
  if (!confirm(`Open SHORT position on ${symbol}?`)) return;
  btn.disabled = true;
  const origText = btn.textContent;
  btn.textContent = 'Opening...';

  try {
    const res = await fetch('/api/open-position', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ symbol }),
    });
    const data = await res.json();
    if (data.ok) {
      btn.textContent = 'Opened!';
      fetchData();
      fetchScannerData(document.getElementById('scan-btn'));
      setTimeout(() => { btn.disabled = false; btn.textContent = origText; }, 1500);
    } else {
      alert(data.message || 'Failed to open position');
      btn.disabled = false;
      btn.textContent = origText;
    }
  } catch (e) {
    alert('Error: ' + e.message);
    btn.disabled = false;
    btn.textContent = origText;
  }
}

async function openManualPosition(btn) {
  const symbolSel = document.getElementById('manual-symbol');
  const msg = document.getElementById('manual-msg');
  msg.textContent = '';

  const symbol = symbolSel.value;

  if (!symbol) {
    msg.style.color = '#f85149';
    msg.textContent = 'Select a symbol first';
    return;
  }

  if (!confirm(`Open SHORT position on ${symbol}?`)) return;
  btn.disabled = true;
  btn.textContent = 'Opening...';

  try {
    const res = await fetch('/api/open-position', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ symbol }),
    });
    const data = await res.json();
    if (data.ok) {
      msg.style.color = '#3fb950';
      msg.textContent = `Opened SHORT on ${symbol} @ ${formatPrice(data.position.entry_price).replace(/<[^>]+>/g, '')}`;
      fetchData();
      fetchScannerData(document.getElementById('scan-btn'));
    } else {
      msg.style.color = '#f85149';
      msg.textContent = data.message || 'Failed to open position';
    }
  } catch (e) {
    msg.style.color = '#f85149';
    msg.textContent = 'Error: ' + e.message;
  }

  btn.disabled = false;
  btn.textContent = 'Open SHORT';
}

async function resetAll(btn) {
  if (!confirm('This will delete ALL trades and positions. Are you sure?')) return;
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

fetchData();
loadSettings();
setInterval(fetchData, 10000);
</script>
</body>
</html>
