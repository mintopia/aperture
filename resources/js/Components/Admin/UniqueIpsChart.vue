<script setup>
import { computed } from 'vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

const props = defineProps({
    data: {
        type: Array,
        default: () => [],
    },
});

const viewBoxWidth = computed(() => {
    if (props.data.length <= 1) return 100;
    return (props.data.length - 1) * 100;
});

const chartData = computed(() => {
    if (!props.data.length) return [];

    const max = Math.max(...props.data.map((d) => d.count), 1);

    return props.data.map((d, i, arr) => ({
        date: d.date,
        count: d.count,
        pct: (d.count / max) * 100,
        x: arr.length > 1 ? (i / (arr.length - 1)) * viewBoxWidth.value : viewBoxWidth.value / 2,
        y: 100 - Math.max((d.count / max) * 96, 0) - 2,
        label: formatDayLabel(d.date),
    }));
});

const linePoints = computed(() => chartData.value.map((p) => `${p.x},${p.y}`).join(' '));

const areaPoints = computed(() => {
    if (!chartData.value.length) return '';
    const first = chartData.value[0];
    const last = chartData.value[chartData.value.length - 1];
    return `${first.x},100 ${linePoints.value} ${last.x},100`;
});

const maxCount = computed(() => {
    if (!props.data.length) return 0;
    return Math.max(...props.data.map((d) => d.count));
});

function formatDayLabel(dateStr) {
    const date = new Date(dateStr + 'T00:00:00');
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diff = Math.round((today - date) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yday';
    return date.toLocaleDateString('en-US', { weekday: 'short' });
}
</script>

<template>
    <div data-testid="unique-ips-chart">
        <SectionHeader title="Unique IPs — Last 7 Days" />

        <div
            v-if="chartData.length"
            class="rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4 pt-6"
        >
            <div class="relative h-[160px]">
                <svg
                    data-testid="unique-ips-line-chart"
                    :viewBox="`0 0 ${viewBoxWidth} 100`"
                    class="absolute inset-0 h-full w-full overflow-visible"
                    preserveAspectRatio="none"
                    role="img"
                    :aria-label="`Line chart showing unique IPs over ${chartData.length} days, peak ${maxCount}`"
                >
                    <polygon
                        data-testid="unique-ips-area"
                        :points="areaPoints"
                        fill="var(--color-primary)"
                        opacity="0.06"
                    />
                    <polyline
                        data-testid="unique-ips-line"
                        :points="linePoints"
                        fill="none"
                        stroke="var(--color-primary)"
                        stroke-width="2"
                        vector-effect="non-scaling-stroke"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>

                <div class="absolute inset-0 flex">
                    <div v-for="point in chartData" :key="point.date" class="group relative flex-1">
                        <span
                            class="pointer-events-none absolute left-1/2 z-10 -translate-x-1/2 rounded bg-[var(--color-text)] px-1.5 py-0.5 font-mono text-[10px] whitespace-nowrap text-[var(--color-bg)] opacity-0 transition-opacity group-hover:opacity-100"
                            :style="{ bottom: `${Math.max(point.pct, 4) + 6}%` }"
                        >
                            {{ point.count }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-2 flex justify-between">
                <span
                    v-for="point in chartData"
                    :key="point.date"
                    data-testid="unique-ips-day-label"
                    class="text-[9px] text-[var(--color-text-muted)]"
                >
                    {{ point.label }}
                </span>
            </div>
        </div>

        <div
            v-else
            data-testid="unique-ips-empty"
            class="flex h-[200px] items-center justify-center rounded border border-[var(--color-border)] bg-[var(--color-surface)]"
        >
            <p class="font-mono text-[11px] text-[var(--color-text-muted)]">No IP activity data available</p>
        </div>

        <p
            v-if="chartData.length"
            data-testid="unique-ips-peak"
            class="mt-2 font-mono text-[11px] text-[var(--color-text-muted)]"
        >
            Peak: {{ maxCount }} unique IPs
        </p>
    </div>
</template>
