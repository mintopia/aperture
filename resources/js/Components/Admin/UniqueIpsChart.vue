<script setup>
import { computed } from 'vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

const props = defineProps({
    data: {
        type: Array,
        default: () => [],
    },
});

const chartData = computed(() => {
    if (!props.data.length) return [];

    const max = Math.max(...props.data.map((d) => d.count), 1);

    return props.data.map((d) => ({
        date: d.date,
        count: d.count,
        pct: (d.count / max) * 100,
        label: formatDayLabel(d.date),
    }));
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
            class="flex h-[200px] items-end gap-[6px] rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4 pt-6"
        >
            <div
                v-for="bar in chartData"
                :key="bar.date"
                class="group relative flex flex-1 flex-col items-center"
                style="height: 100%"
            >
                <div class="flex w-full flex-1 items-end">
                    <div
                        data-testid="unique-ips-bar"
                        class="w-full rounded-t-[3px] bg-[var(--color-primary)] transition-[height] duration-300"
                        :style="{ height: `${Math.max(bar.pct, 2)}%` }"
                    />
                </div>
                <span class="mt-1.5 text-[9px] text-[var(--color-text-muted)]">{{ bar.label }}</span>

                <!-- Tooltip -->
                <span
                    class="pointer-events-none absolute -top-6 left-1/2 z-10 -translate-x-1/2 rounded bg-[var(--color-text)] px-1.5 py-0.5 font-mono text-[10px] whitespace-nowrap text-[var(--color-bg)] opacity-0 transition-opacity group-hover:opacity-100"
                >
                    {{ bar.count }}
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
