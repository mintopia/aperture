<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import { formatBytes } from '@/helpers.js';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';

defineProps({
    title: { type: String, default: 'Bandwidth' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const ranges = [
    { value: '1h', label: '1H' },
    { value: '24h', label: '24H' },
    { value: '3d', label: '72H' },
];

const selectedRange = ref('1h');

const bandwidthData = ref({
    timestamps: [],
    download: [],
    upload: [],
    totalReceived: 0,
    totalSent: 0,
});

const loading = ref(true);

const chartSeries = computed(() => {
    const { timestamps, download, upload } = bandwidthData.value;
    if (!timestamps.length) return [];

    return [
        {
            label: 'Download',
            color: 'var(--color-success)',
            fill: true,
            data: timestamps.map((ts, i) => ({
                timestamp: Number(ts),
                value: download[i] ?? 0,
            })),
        },
        {
            label: 'Upload',
            color: 'var(--color-info)',
            fill: true,
            data: timestamps.map((ts, i) => ({
                timestamp: Number(ts),
                value: upload[i] ?? 0,
            })),
        },
    ];
});

let pollInterval = null;

async function fetchBandwidth() {
    try {
        const response = await fetch(route('portal.stats.bandwidth') + '?range=' + selectedRange.value);
        if (response.ok) {
            bandwidthData.value = await response.json();
        }
    } catch (_e) {
        // Silently fail — data will refresh next interval
    } finally {
        loading.value = false;
    }
}

function selectRange(range) {
    selectedRange.value = range;
}

watch(selectedRange, () => {
    loading.value = true;
    fetchBandwidth();
});

onMounted(() => {
    fetchBandwidth();
    pollInterval = setInterval(fetchBandwidth, 30000);
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <div data-testid="block-bandwidth">
        <div class="mb-3 flex items-baseline justify-between">
            <div class="flex items-baseline gap-4">
                <div data-testid="bandwidth-download">
                    <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                        Down
                    </span>
                    <span class="ml-1 font-mono text-sm font-bold text-[var(--color-success)]">
                        {{ formatBytes(bandwidthData.totalReceived) }}
                    </span>
                </div>
                <div data-testid="bandwidth-upload">
                    <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                        Up
                    </span>
                    <span class="ml-1 font-mono text-sm font-bold text-[var(--color-info)]">
                        {{ formatBytes(bandwidthData.totalSent) }}
                    </span>
                </div>
            </div>
            <div class="flex gap-1" data-testid="bandwidth-range-selector">
                <button
                    v-for="r in ranges"
                    :key="r.value"
                    type="button"
                    :data-testid="'bandwidth-range-' + r.value"
                    :class="[
                        'rounded px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase transition-all',
                        selectedRange === r.value
                            ? 'bg-[var(--color-accent-dim)] text-[var(--color-primary)]'
                            : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)]',
                    ]"
                    @click="selectRange(r.value)"
                >
                    {{ r.label }}
                </button>
            </div>
        </div>
        <TimeSeriesChart
            :series="chartSeries"
            :loading="loading"
            y-axis-label="bps"
            height="180px"
            empty-message="No bandwidth data available"
            data-testid="bandwidth-chart"
        />
    </div>
</template>
