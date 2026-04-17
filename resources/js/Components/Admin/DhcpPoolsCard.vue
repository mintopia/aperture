<script setup>
import ProgressBar from '@/Components/UI/ProgressBar.vue';

defineProps({
    pools: {
        type: Array,
        default: () => [],
    },
});

function poolColor(utilisation) {
    if (utilisation >= 0.9) return 'danger';
    if (utilisation >= 0.7) return 'warning';

    return 'primary';
}
</script>

<template>
    <div
        data-testid="dhcp-pools-card"
        class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
    >
        <h3
            data-testid="dhcp-pools-title"
            class="mb-4 text-[11px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
        >
            DHCP Pools
        </h3>

        <div v-if="pools.length" class="space-y-3">
            <ProgressBar
                v-for="pool in pools"
                :key="pool.name"
                :label="pool.name"
                :value="pool.used"
                :max="pool.total"
                :color="poolColor(pool.utilisation)"
                :display-value="`${pool.used} / ${pool.total}`"
                data-testid="dhcp-pool-row"
            />
        </div>

        <p v-else data-testid="dhcp-pools-empty" class="text-[11px] text-[var(--color-text-muted)]">
            No DHCP pools available
        </p>
    </div>
</template>
