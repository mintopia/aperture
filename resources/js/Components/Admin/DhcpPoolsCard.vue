<script setup>
import SectionHeader from '@/Components/UI/SectionHeader.vue';

defineProps({
    pools: {
        type: Array,
        default: () => [],
    },
});

function barColor(utilisation) {
    if (utilisation >= 0.9) return 'var(--color-danger)';
    if (utilisation >= 0.7) return 'var(--color-warning)';

    return 'var(--color-success)';
}

function pctClass(utilisation) {
    if (utilisation >= 0.9) return 'text-[var(--color-danger)]';

    return 'text-[var(--color-text-secondary)]';
}
</script>

<template>
    <div data-testid="dhcp-pools-card">
        <SectionHeader title="DHCP Pools" />

        <div v-if="pools.length" class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            class="border-b border-[var(--color-border-hover)] py-2 text-left text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                        >
                            Network
                        </th>
                        <th
                            class="border-b border-[var(--color-border-hover)] py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                        >
                            Used
                        </th>
                        <th
                            class="border-b border-[var(--color-border-hover)] py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                        >
                            Total
                        </th>
                        <th
                            class="w-[120px] border-b border-[var(--color-border-hover)] py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                        >
                            Utilisation
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="pool in pools" :key="pool.network || pool.name" data-testid="dhcp-pool-row">
                        <td class="text-[13px] font-semibold text-[var(--color-text)]">
                            {{ pool.network || '—' }}
                        </td>
                        <td
                            data-testid="dhcp-pool-used"
                            class="pl-6 font-mono text-[13px] text-[var(--color-text-secondary)]"
                        >
                            {{ pool.used }}
                        </td>
                        <td
                            data-testid="dhcp-pool-total"
                            class="pl-6 font-mono text-[13px] text-[var(--color-text-secondary)]"
                        >
                            {{ pool.total }}
                        </td>
                        <td class="pl-6">
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-[6px] flex-1 overflow-hidden rounded-[3px] bg-[var(--color-surface-hover)]"
                                >
                                    <div
                                        data-testid="dhcp-pool-bar"
                                        class="h-full rounded-[3px] transition-[width] duration-300"
                                        :style="{
                                            width: `${Math.min((pool.used / pool.total) * 100, 100)}%`,
                                            backgroundColor: barColor(pool.utilisation),
                                        }"
                                    />
                                </div>
                                <span
                                    data-testid="dhcp-pool-pct"
                                    class="min-w-[32px] text-right font-mono text-[11px]"
                                    :class="pctClass(pool.utilisation)"
                                >
                                    {{ Math.round(pool.utilisation * 100) }}%
                                </span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-else data-testid="dhcp-pools-empty" class="text-[11px] text-[var(--color-text-muted)]">
            No DHCP pools available
        </p>
    </div>
</template>

<style scoped>
tbody td {
    padding-top: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}
tbody tr:last-child td {
    border-bottom: none;
}
</style>
