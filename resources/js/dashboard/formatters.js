export function fmtNum(val, decimals = 2) {
    if (val === null || val === undefined || Number.isNaN(val)) return '—';
    return Number(val).toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function fmtMoney(val, decimals = 2) {
    if (val === null || val === undefined || Number.isNaN(val)) return '—';
    return '$' + fmtNum(val, decimals);
}

export function fmtPnl(val, decimals = 2) {
    if (val === null || val === undefined || Number.isNaN(val)) return '—';
    const prefix = val >= 0 ? '+$' : '−$';
    return prefix + fmtNum(Math.abs(val), decimals);
}

export function fmtVolume(v) {
    if (v === null || v === undefined) return '—';
    if (v >= 1e9) return '$' + (v / 1e9).toFixed(2) + 'B';
    if (v >= 1e6) return '$' + (v / 1e6).toFixed(2) + 'M';
    if (v >= 1e3) return '$' + (v / 1e3).toFixed(1) + 'K';
    return '$' + fmtNum(v, 0);
}

// Subscript-zero notation for tiny prices: $0.0₅1234. Keeps wide tables
// scannable instead of showing 7 leading zeroes.
export function formatPrice(val) {
    if (val === null || val === undefined) return '—';
    if (val === 0) return '$0';
    const abs = Math.abs(val);
    const sign = val < 0 ? '−' : '';
    if (abs >= 100) return sign + '$' + fmtNum(abs, 2);
    if (abs >= 1) return sign + '$' + fmtNum(abs, 4);
    const str = abs.toFixed(20).replace(/0+$/, '');
    const zeros = (str.match(/^0\.(0+)/) || [null, ''])[1].length;
    if (zeros >= 3) {
        const sigStart = zeros + 2;
        const sigDigits = str.slice(sigStart, sigStart + 4).replace(/0+$/, '') || '0';
        const subscript = String(zeros)
            .split('')
            .map((d) => '₀₁₂₃₄₅₆₇₈₉'[d])
            .join('');
        return sign + '$0.0' + '<sub class="text-[var(--color-text-subtle)]">' + subscript + '</sub>' + sigDigits;
    }
    return sign + '$' + parseFloat(abs.toPrecision(6));
}

export function formatTimestamp(ts) {
    if (!ts) return '—';
    const d = new Date(ts * 1000);
    const pad = (n) => String(n).padStart(2, '0');
    return (
        d.getFullYear() +
        '-' + pad(d.getMonth() + 1) +
        '-' + pad(d.getDate()) +
        ' ' + pad(d.getHours()) +
        ':' + pad(d.getMinutes())
    );
}

export function holdTime(openedTs, expiresTs) {
    if (!openedTs) return '—';
    const now = Math.floor(Date.now() / 1000);
    const held = Math.floor((now - openedTs) / 60);
    let str = held + 'm';
    if (expiresTs) {
        const remaining = Math.max(0, Math.floor((expiresTs - now) / 60));
        str += ` <span class="text-[var(--color-text-subtle)] text-[10px]">(${remaining}m left)</span>`;
    }
    return str;
}

export function pnlClass(val) {
    if (val > 0) return 'text-[var(--color-success)]';
    if (val < 0) return 'text-[var(--color-danger)]';
    return 'text-[var(--color-text-muted)]';
}

export function fundingEarning(side, rate) {
    return (side === 'LONG' && rate < 0) || (side === 'SHORT' && rate > 0);
}

export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]),
    );
}
