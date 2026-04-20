<script setup>
import SectionHeader from '@/Components/UI/SectionHeader.vue';

const props = defineProps({
    errors: {
        type: Object,
        default: () => null,
    },
});

const stats = [
    { key: 'crc', label: 'CRC' },
    { key: 'input', label: 'Input' },
    { key: 'output', label: 'Output' },
    { key: 'collisions', label: 'Collisions' },
];

function hasErrors() {
    if (!props.errors) return false;

    return stats.some((s) => (props.errors[s.key] ?? 0) > 0);
}
</script>

<template>
    <div data-testid="port-errors-card">
        <SectionHeader title="Port Errors" />

        <template v-if="errors">
            <div data-testid="port-errors-grid" class="mb-4 grid grid-cols-2 gap-0">
                <div
                    v-for="(stat, index) in stats"
                    :key="stat.key"
                    :data-testid="`port-error-stat-${stat.key}`"
                    class="py-2"
                    :class="[
                        index % 2 === 0 ? 'pr-4' : 'border-l border-[var(--color-border)] pl-4',
                        index < 2 ? 'mb-1 border-b border-[var(--color-border)] pb-3' : '',
                    ]"
                >
                    <div
                        class="mb-[2px] text-[10px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                        data-testid="port-error-label"
                    >
                        {{ stat.label }}
                    </div>
                    <div
                        class="font-heading text-[20px] font-bold"
                        :class="
                            (errors[stat.key] ?? 0) === 0
                                ? 'text-[var(--color-text-muted)]'
                                : 'text-[var(--color-text)]'
                        "
                        :style="{ fontVariationSettings: '\'opsz\' 28' }"
                        data-testid="port-error-count"
                    >
                        {{ errors[stat.key] ?? 0 }}
                    </div>
                </div>
            </div>

            <p v-if="!hasErrors()" data-testid="port-errors-clean" class="text-[11px] text-[var(--color-success)]">
                No errors detected
            </p>
        </template>

        <p v-else data-testid="port-errors-empty" class="text-[11px] text-[var(--color-text-muted)]">
            No error data available — requires Prometheus integration
        </p>
    </div>
</template>
