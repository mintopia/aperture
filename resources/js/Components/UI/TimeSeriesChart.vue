<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, useAttrs, watch } from 'vue';
import { Chart } from 'chart.js/auto';
import 'chartjs-adapter-date-fns';

defineOptions({
    inheritAttrs: false,
});

const props = defineProps({
    series: { type: Array, required: true },
    yAxisLabel: { type: String, default: '' },
    yAxisFormatter: { type: Function, default: null },
    height: { type: String, default: '200px' },
    timeRange: { type: String, default: '24h' },
    loading: { type: Boolean, default: false },
    emptyMessage: { type: String, default: 'No data available' },
});

const attrs = useAttrs();
const canvas = ref(null);

let chart = null;

const hasData = computed(() => {
    return props.series.some((series) => Array.isArray(series.data) && series.data.length > 0);
});

const rootTestId = computed(() => attrs['data-testid'] || 'time-series-chart');
const rootClass = computed(() => attrs.class);
const rootStyle = computed(() => [attrs.style, { height: props.height }]);
const rootAttrs = computed(() => {
    const filteredAttrs = { ...attrs };
    delete filteredAttrs['data-testid'];
    delete filteredAttrs.class;
    delete filteredAttrs.style;

    return filteredAttrs;
});

function getComputedColor(varName, fallback = '') {
    if (typeof window === 'undefined') {
        return fallback;
    }

    return getComputedStyle(document.documentElement).getPropertyValue(varName).trim() || fallback;
}

function withAlpha(color, alpha) {
    if (!color) {
        return `rgba(99, 102, 241, ${alpha})`;
    }

    if (color.startsWith('#')) {
        let hex = color.slice(1);

        if (hex.length === 3) {
            hex = hex
                .split('')
                .map((char) => char + char)
                .join('');
        }

        const red = Number.parseInt(hex.slice(0, 2), 16);
        const green = Number.parseInt(hex.slice(2, 4), 16);
        const blue = Number.parseInt(hex.slice(4, 6), 16);

        return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
    }

    const rgbMatch = color.match(/rgba?\(([^)]+)\)/i);
    if (rgbMatch) {
        const [red, green, blue] = rgbMatch[1].split(',').map((value) => value.trim());
        return `rgba(${red}, ${green}, ${blue}, ${alpha})`;
    }

    return color;
}

function formatTimestamp(value, options = { hour: '2-digit', minute: '2-digit' }) {
    return new Date(value).toLocaleTimeString([], options);
}

function formatValue(value) {
    const numericValue = Number(value);

    if (props.yAxisFormatter) {
        return props.yAxisFormatter(numericValue);
    }

    if (numericValue >= 1e9) return `${(numericValue / 1e9).toFixed(1)} Gbps`;
    if (numericValue >= 1e6) return `${(numericValue / 1e6).toFixed(1)} Mbps`;
    if (numericValue >= 1e3) return `${(numericValue / 1e3).toFixed(1)} Kbps`;

    return numericValue.toFixed(1);
}

function destroyChart() {
    if (chart) {
        chart.destroy();
        chart = null;
    }
}

function buildChart() {
    if (!canvas.value || props.loading || !hasData.value) {
        destroyChart();
        return;
    }

    destroyChart();

    const textColor = getComputedColor('--color-text-muted', '#888888');
    const bodyColor = getComputedColor('--color-text', '#ffffff');
    const surfaceColor = getComputedColor('--color-surface', '#1a1a2e');
    const gridColor = getComputedColor('--color-border', '#333333');

    const datasets = props.series
        .filter((series) => Array.isArray(series.data) && series.data.length > 0)
        .map((series) => ({
            label: series.label,
            data: series.data.map((point) => ({ x: point.timestamp * 1000, y: point.value })),
            borderColor: series.color,
            backgroundColor: series.fill ? withAlpha(series.color, 0.12) : 'transparent',
            fill: Boolean(series.fill),
            tension: 0.3,
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2,
        }));

    chart = new Chart(canvas.value, {
        type: 'line',
        data: { datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: datasets.length > 1,
                    labels: {
                        color: textColor,
                        boxWidth: 12,
                        padding: 16,
                        font: { size: 11 },
                    },
                },
                tooltip: {
                    backgroundColor: surfaceColor,
                    titleColor: textColor,
                    bodyColor,
                    borderColor: gridColor,
                    borderWidth: 1,
                    padding: 10,
                    callbacks: {
                        title(items) {
                            if (!items.length) {
                                return '';
                            }

                            return new Date(items[0].parsed.x).toLocaleString([], {
                                month: 'short',
                                day: 'numeric',
                                hour: '2-digit',
                                minute: '2-digit',
                            });
                        },
                        label(context) {
                            return `${context.dataset.label}: ${formatValue(context.parsed.y)}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    type: 'timeseries',
                    ticks: {
                        color: textColor,
                        maxTicksLimit: 8,
                        font: { size: 10 },
                        callback: (_value, index, ticks) => {
                            return formatTimestamp(ticks[index]?.value ?? Date.now());
                        },
                    },
                    grid: {
                        color: withAlpha(gridColor, 0.25),
                    },
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: Boolean(props.yAxisLabel),
                        text: props.yAxisLabel,
                        color: textColor,
                        font: { size: 11 },
                    },
                    ticks: {
                        color: textColor,
                        font: { size: 10 },
                        callback: (value) => formatValue(value),
                    },
                    grid: {
                        color: withAlpha(gridColor, 0.25),
                    },
                },
            },
        },
    });
}

onMounted(() => {
    nextTick(() => buildChart());
});

onUnmounted(() => {
    destroyChart();
});

watch(
    () => [props.series, props.loading, props.yAxisLabel, props.yAxisFormatter, props.timeRange],
    () => {
        nextTick(() => buildChart());
    },
    { deep: true },
);
</script>

<template>
    <div
        v-bind="rootAttrs"
        :data-testid="rootTestId"
        :data-time-range="timeRange"
        :class="rootClass"
        :style="rootStyle"
    >
        <div
            v-if="loading"
            data-testid="chart-loading"
            class="flex h-full items-center justify-center rounded-lg border border-dashed border-[var(--color-border)] bg-[var(--color-bg)]"
        >
            <div class="flex flex-col items-center gap-2">
                <div
                    class="h-6 w-6 animate-spin rounded-full border-2 border-[var(--color-primary)] border-t-transparent"
                ></div>
                <span class="text-xs text-[var(--color-text-muted)]">Loading chart data…</span>
            </div>
        </div>
        <div
            v-else-if="!hasData"
            data-testid="chart-empty"
            class="flex h-full items-center justify-center rounded-lg border border-dashed border-[var(--color-border)] bg-[var(--color-bg)]"
        >
            <span class="text-sm text-[var(--color-text-muted)]">{{ emptyMessage }}</span>
        </div>
        <div v-else class="h-full">
            <canvas ref="canvas" data-testid="chart-canvas"></canvas>
        </div>
    </div>
</template>
