import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent, LegendComponent, MarkAreaComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { chartTheme, defaultTooltip, axisStyle } from './theme.js';

echarts.use([LineChart, GridComponent, TooltipComponent, LegendComponent, MarkAreaComponent, CanvasRenderer]);

let chart = null;

function buildOption(points, range) {
    const times = points.map((p) => p.ts * 1000);
    const wallet = points.map((p) => [p.ts * 1000, p.wallet_balance]);
    const avail = points.map((p) => [p.ts * 1000, p.available_balance]);

    // Drawdown overlay: walk wallet history, compute rolling peak, plot %dd.
    let peak = 0;
    const dd = points.map((p) => {
        if (p.wallet_balance > peak) peak = p.wallet_balance;
        const v = peak > 0 ? -((peak - p.wallet_balance) / peak) * 100 : 0;
        return [p.ts * 1000, Number(v.toFixed(2))];
    });

    const isShort = range === '1h' || range === '6h' || range === '24h';

    return {
        animation: false,
        backgroundColor: 'transparent',
        textStyle: { fontFamily: chartTheme.fontFamily },
        grid: { left: 60, right: 60, top: 28, bottom: 28 },
        legend: {
            data: ['Wallet', 'Available', 'Drawdown'],
            textStyle: { color: chartTheme.textMuted, fontSize: 11 },
            top: 0,
            right: 12,
            itemWidth: 14,
            itemHeight: 8,
        },
        tooltip: {
            ...defaultTooltip(),
            trigger: 'axis',
            axisPointer: { type: 'cross', lineStyle: { color: chartTheme.grid } },
            valueFormatter: (val, idx) => {
                if (val === null || val === undefined) return '—';
                if (idx === 2) return Number(val).toFixed(2) + '%';
                return '$' + Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            },
        },
        xAxis: {
            type: 'time',
            ...axisStyle(),
            axisLabel: {
                ...axisStyle().axisLabel,
                formatter: (val) => {
                    const d = new Date(val);
                    if (isShort) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
                },
            },
            min: times[0],
            max: times[times.length - 1],
        },
        yAxis: [
            {
                type: 'value',
                ...axisStyle(),
                scale: true,
                axisLabel: {
                    ...axisStyle().axisLabel,
                    formatter: (v) => '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 }),
                },
            },
            {
                type: 'value',
                ...axisStyle(),
                position: 'right',
                splitLine: { show: false },
                max: 0,
                axisLabel: {
                    ...axisStyle().axisLabel,
                    formatter: (v) => Number(v).toFixed(0) + '%',
                },
            },
        ],
        series: [
            {
                name: 'Wallet',
                type: 'line',
                showSymbol: false,
                smooth: 0.18,
                lineStyle: { color: chartTheme.accent, width: 2 },
                areaStyle: {
                    color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                        { offset: 0, color: 'rgba(56,189,248,0.30)' },
                        { offset: 1, color: 'rgba(56,189,248,0)' },
                    ]),
                },
                data: wallet,
            },
            {
                name: 'Available',
                type: 'line',
                showSymbol: false,
                smooth: 0.18,
                lineStyle: { color: chartTheme.success, width: 1.4, opacity: 0.9 },
                data: avail,
            },
            {
                name: 'Drawdown',
                type: 'line',
                yAxisIndex: 1,
                showSymbol: false,
                smooth: 0.18,
                lineStyle: { color: chartTheme.danger, width: 1, opacity: 0.7, type: 'dashed' },
                areaStyle: { color: 'rgba(248,113,113,0.10)' },
                data: dd,
            },
        ],
    };
}

export function renderEquity(el, points, range) {
    if (!points || points.length === 0) {
        el.style.display = 'none';
        document.getElementById(el.id + '-empty')?.classList.remove('hidden');
        if (chart) chart.clear();
        return;
    }
    document.getElementById(el.id + '-empty')?.classList.add('hidden');
    el.style.display = '';
    if (!chart) chart = echarts.init(el, null, { renderer: 'canvas' });
    chart.setOption(buildOption(points, range), true);
}

export function resizeEquity() {
    chart?.resize();
}
