// Shared ECharts theme tokens. Mirrors the CSS custom properties so charts
// look like a coherent part of the UI rather than a third-party widget.
export const chartTheme = {
    text: '#e6e9ef',
    textMuted: '#99a1b1',
    textSubtle: '#6b7383',
    grid: '#1f242e',
    surface: '#11141a',
    accent: '#38bdf8',
    accentSoft: 'rgba(56,189,248,0.18)',
    success: '#34d399',
    successSoft: 'rgba(52,211,153,0.18)',
    danger: '#f87171',
    dangerSoft: 'rgba(248,113,113,0.18)',
    warning: '#fbbf24',
    purple: '#c084fc',
    fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
    fontMono: 'JetBrains Mono, ui-monospace, monospace',
};

export function defaultTooltip() {
    return {
        backgroundColor: '#11141a',
        borderColor: '#2a313d',
        borderWidth: 1,
        padding: 10,
        textStyle: { color: chartTheme.text, fontSize: 12, fontFamily: chartTheme.fontFamily },
        extraCssText: 'border-radius: 8px; box-shadow: 0 8px 24px rgba(0,0,0,0.4);',
    };
}

export function axisStyle() {
    return {
        axisLine: { lineStyle: { color: chartTheme.grid } },
        axisTick: { show: false },
        axisLabel: { color: chartTheme.textSubtle, fontSize: 11, fontFamily: chartTheme.fontMono },
        splitLine: { lineStyle: { color: chartTheme.grid, type: [3, 4] } },
    };
}
