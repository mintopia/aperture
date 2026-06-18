<script setup>
import { computed } from 'vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';

const props = defineProps({
    data: {
        type: Array,
        default: () => [],
    },
});

const chartSeries = computed(() => {
    if (!props.data.length) return [];

    return [
        {
            label: 'Unique IPs',
            data: props.data.map((d) => ({
                timestamp: new Date(d.date + 'T12:00:00').getTime() / 1000,
                value: d.count,
            })),
            color:
                typeof window !== 'undefined'
                    ? getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#6366f1'
                    : '#6366f1',
            fill: true,
        },
    ];
});

const maxCount = computed(() => {
    if (!props.data.length) return 0;
    return Math.max(...props.data.map((d) => d.count));
});

function formatCount(value) {
    return Math.round(value).toString();
}
</script>

<template>
    <div data-testid="unique-ips-chart">
        <SectionHeader title="Unique IPs — Last 7 Days" />

        <TimeSeriesChart
            v-if="chartSeries.length"
            data-testid="unique-ips-line-chart"
            :series="chartSeries"
            y-axis-label="IPs"
            :y-axis-formatter="formatCount"
            height="200px"
            time-range="7d"
            empty-message="No IP activity data available"
        />

        <div
            v-else
            data-testid="unique-ips-empty"
            class="flex h-[200px] items-center justify-center rounded border border-[var(--color-border)] bg-[var(--color-surface)]"
        >
            <p class="font-mono text-[11px] text-[var(--color-text-muted)]">No IP activity data available</p>
        </div>

        <p
            v-if="data.length"
            data-testid="unique-ips-peak"
            class="mt-2 font-mono text-[11px] text-[var(--color-text-muted)]"
        >
            Peak: {{ maxCount }} unique IPs
        </p>
    </div>
</template>
