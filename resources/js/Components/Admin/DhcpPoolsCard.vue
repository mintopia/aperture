<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatPoolTotal } from '@/helpers.js';

defineProps({
    pools: {
        type: Array,
        default: () => [],
    },
});

const noIO = typeof window === 'undefined' || !('IntersectionObserver' in window);
const reducedMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
const skipAnimation = noIO || reducedMotion;

const entered = ref(skipAnimation);
const cardRef = ref(null);
let observer = null;

onMounted(() => {
    if (skipAnimation) return;

    observer = new IntersectionObserver(
        ([entry]) => {
            if (entry.isIntersecting) {
                entered.value = true;
                observer.disconnect();
            }
        },
        { threshold: 0.1 },
    );

    if (cardRef.value) observer.observe(cardRef.value);
});

onUnmounted(() => {
    observer?.disconnect();
});

function barWidth(used, total) {
    const totalCount = Number(total);
    if (!Number.isFinite(totalCount) || totalCount <= 0) return 0;

    const pct = (Number(used) / totalCount) * 100;
    if (!Number.isFinite(pct) || pct <= 0) return 0;

    return Math.min(pct, 100);
}

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
    <div ref="cardRef" data-testid="dhcp-pools-card">
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
                            <Link
                                v-if="pool.network"
                                :href="route('admin.dhcp.leases', { network: pool.network })"
                                data-testid="dhcp-pool-network-link"
                                class="text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                            >
                                {{ pool.network }}
                            </Link>
                            <span v-else>&mdash;</span>
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
                            :title="String(pool.total)"
                        >
                            {{ formatPoolTotal(pool.total) }}
                        </td>
                        <td class="pl-6">
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-[6px] flex-1 overflow-hidden rounded-[3px] bg-[var(--color-surface-hover)]"
                                >
                                    <div
                                        data-testid="dhcp-pool-bar"
                                        class="h-full rounded-[3px] transition-[width] duration-700"
                                        :style="{
                                            width: entered ? `${barWidth(pool.used, pool.total)}%` : '0%',
                                            backgroundColor: barColor(pool.utilisation),
                                            transitionTimingFunction: 'cubic-bezier(0.16, 1, 0.3, 1)',
                                            transitionDelay: `${100}ms`,
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
