import * as echarts from 'echarts/core';
import { BarChart, PieChart, LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { chartTheme, defaultTooltip, axisStyle } from './theme.js';

echarts.use([BarChart, PieChart, LineChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

const charts = {};

function get(el) {
    // SPA navigation re-creates the chart container, so dispose any cached
    // instance bound to a now-detached node before reinitializing. Re-measure
    // on the next frame in case init ran before the swapped subtree had laid out.
    if (charts[el.id] && charts[el.id].getDom() !== el) {
        charts[el.id].dispose();
        delete charts[el.id];
    }
    if (!charts[el.id]) {
        charts[el.id] = echarts.init(el, null, { renderer: 'canvas' });
        requestAnimationFrame(() => charts[el.id]?.resize());
    }
    return charts[el.id];
}

export function renderPnlBySymbol(el, rows) {
    if (!rows || rows.length === 0) {
        el.style.display = 'none';
        document.getElementById(el.id + '-empty')?.classList.remove('hidden');
        return;
    }
    document.getElementById(el.id + '-empty')?.classList.add('hidden');
    el.style.display = '';

    const sorted = rows.slice().sort((a, b) => a.pnl - b.pnl);
    const symbols = sorted.map((r) => r.symbol);
    const values = sorted.map((r) => r.pnl);

    get(el).setOption({
        animation: false,
        backgroundColor: 'transparent',
        textStyle: { fontFamily: chartTheme.fontFamily },
        grid: { left: 90, right: 24, top: 12, bottom: 24 },
        tooltip: {
            ...defaultTooltip(),
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            valueFormatter: (v) => '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
        },
        xAxis: {
            type: 'value',
            ...axisStyle(),
            axisLabel: { ...axisStyle().axisLabel, formatter: (v) => '$' + Number(v).toFixed(0) },
        },
        yAxis: {
            type: 'category',
            data: symbols,
            ...axisStyle(),
            axisLabel: { color: chartTheme.text, fontSize: 11, fontFamily: chartTheme.fontMono },
            splitLine: { show: false },
        },
        series: [
            {
                type: 'bar',
                data: values.map((v) => ({
                    value: v,
                    itemStyle: { color: v >= 0 ? chartTheme.success : chartTheme.danger },
                })),
                barWidth: 14,
            },
        ],
    }, true);
}

export function renderCloseReason(el, rows) {
    if (!rows || rows.length === 0) {
        el.style.display = 'none';
        document.getElementById(el.id + '-empty')?.classList.remove('hidden');
        return;
    }
    document.getElementById(el.id + '-empty')?.classList.add('hidden');
    el.style.display = '';

    const palette = {
        take_profit: chartTheme.success,
        partial_take_profit: chartTheme.accent,
        stop_loss: chartTheme.danger,
        expired: chartTheme.warning,
        manual: chartTheme.textMuted,
        reversed: chartTheme.purple,
    };
    const data = rows.map((r) => ({
        value: r.count,
        name: r.reason.replace(/_/g, ' '),
        itemStyle: { color: palette[r.reason] || chartTheme.textMuted },
    }));

    get(el).setOption({
        animation: false,
        backgroundColor: 'transparent',
        textStyle: { fontFamily: chartTheme.fontFamily },
        tooltip: { ...defaultTooltip(), trigger: 'item' },
        legend: {
            bottom: 0,
            textStyle: { color: chartTheme.textMuted, fontSize: 11 },
            itemWidth: 12,
            itemHeight: 8,
        },
        series: [
            {
                type: 'pie',
                radius: ['52%', '74%'],
                center: ['50%', '46%'],
                avoidLabelOverlap: true,
                label: {
                    color: chartTheme.text,
                    fontSize: 11,
                    formatter: '{b|{b}}\n{c|{c}}',
                    rich: {
                        b: { color: chartTheme.text, fontSize: 11 },
                        c: { color: chartTheme.textMuted, fontSize: 10 },
                    },
                },
                labelLine: { lineStyle: { color: chartTheme.grid } },
                data,
            },
        ],
    }, true);
}

export function renderTradesPerDay(el, rows) {
    if (!rows || rows.length === 0) {
        el.style.display = 'none';
        document.getElementById(el.id + '-empty')?.classList.remove('hidden');
        return;
    }
    document.getElementById(el.id + '-empty')?.classList.add('hidden');
    el.style.display = '';

    const dates = rows.map((r) => r.date);
    const counts = rows.map((r) => ({
        value: r.trades,
        itemStyle: { color: r.pnl >= 0 ? chartTheme.success : chartTheme.danger },
    }));

    get(el).setOption({
        animation: false,
        backgroundColor: 'transparent',
        textStyle: { fontFamily: chartTheme.fontFamily },
        grid: { left: 36, right: 16, top: 16, bottom: 30 },
        tooltip: {
            ...defaultTooltip(),
            trigger: 'axis',
            axisPointer: { type: 'shadow' },
            formatter: (params) => {
                const p = params[0];
                const row = rows[p.dataIndex];
                const pnl = row.pnl;
                const color = pnl >= 0 ? chartTheme.success : chartTheme.danger;
                const sign = pnl >= 0 ? '+' : '';
                return `<div style="margin-bottom:4px">${row.date}</div>
                    <div>${row.trades} trade${row.trades === 1 ? '' : 's'}</div>
                    <div style="color:${color}">${sign}$${Number(pnl).toFixed(2)} P&amp;L</div>`;
            },
        },
        xAxis: {
            type: 'category',
            data: dates,
            ...axisStyle(),
            axisLabel: {
                ...axisStyle().axisLabel,
                interval: Math.max(0, Math.floor(dates.length / 8) - 1),
                formatter: (val) => {
                    const d = new Date(val);
                    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
                },
            },
        },
        yAxis: {
            type: 'value',
            ...axisStyle(),
            minInterval: 1,
        },
        series: [{ type: 'bar', data: counts, barWidth: '70%' }],
    }, true);
}

export function renderWinRateSparkline(el, history) {
    if (!history || history.length === 0) {
        el.innerHTML = '';
        return;
    }
    if (charts[el.id] && charts[el.id].getDom() !== el) {
        charts[el.id].dispose();
        delete charts[el.id];
    }
    if (!charts[el.id]) charts[el.id] = echarts.init(el, null, { renderer: 'canvas' });
    requestAnimationFrame(() => charts[el.id]?.resize());
    charts[el.id].setOption({
        animation: false,
        backgroundColor: 'transparent',
        grid: { left: 0, right: 0, top: 4, bottom: 4 },
        xAxis: { type: 'category', show: false, data: history.map((_, i) => i) },
        yAxis: { type: 'value', show: false, min: 0, max: 1 },
        series: [
            {
                type: 'line',
                showSymbol: false,
                smooth: 0.3,
                lineStyle: { color: chartTheme.accent, width: 1.6 },
                areaStyle: { color: 'rgba(56,189,248,0.18)' },
                data: history,
            },
        ],
    }, true);
}

export function resizeAggregates() {
    Object.values(charts).forEach((c) => c?.resize());
}
