<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatPoolTotal } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ranges: { type: Array, default: () => [] },
});

const sortColumn = ref('network');
const sortDirection = ref('asc');

const rangeColumns = [
    { key: 'network', label: 'Network', sortable: true },
    { key: 'start', label: 'Start', sortable: true },
    { key: 'end', label: 'End', sortable: true },
    { key: 'usage', label: 'Usage', sortable: true },
];

const sortedRanges = computed(() => {
    return [...props.ranges].sort((a, b) => {
        let aVal = a[sortColumn.value];
        let bVal = b[sortColumn.value];

        if (sortColumn.value === 'usage') {
            aVal = a.percentage || 0;
            bVal = b.percentage || 0;
        }

        if (aVal == null) aVal = '';
        if (bVal == null) bVal = '';

        if (typeof aVal === 'number' && typeof bVal === 'number') {
            return sortDirection.value === 'asc' ? aVal - bVal : bVal - aVal;
        }

        const comparison = aVal.toString().localeCompare(bVal.toString());
        return sortDirection.value === 'asc' ? comparison : -comparison;
    });
});

const totalUsed = computed(() => props.ranges.reduce((sum, r) => sum + (r.used ?? 0), 0));
// Totals are exact decimal numeric strings that can exceed
// Number.MAX_SAFE_INTEGER (e.g. 2^64 for an IPv6 /64), so sum with BigInt.
const totalAddresses = computed(() => props.ranges.reduce((sum, r) => sum + BigInt(r.total ?? 0), 0n));
const overallUtilisation = computed(() => {
    if (totalAddresses.value === 0n) return '—';
    const knownUsed = props.ranges.filter((r) => r.total != null).reduce((sum, r) => sum + (r.used ?? 0), 0);
    return `${((knownUsed / Number(totalAddresses.value)) * 100).toFixed(1)}%`;
});

function barColor(pct) {
    if (pct > 90) return 'var(--color-danger)';
    if (pct > 70) return 'var(--color-warning)';
    return 'var(--color-success)';
}

function usageLabel(row) {
    if (row.total != null) return `${row.used ?? '—'} / ${formatPoolTotal(row.total)}`;
    if (row.used != null) return `${row.used} used`;
    return '—';
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                DHCP Ranges
            </h1>
            <Link
                :href="route('admin.dhcp.leases')"
                data-testid="view-leases-button"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)]"
            >
                View Leases
            </Link>
        </div>

        <MetadataStrip
            v-if="ranges.length > 0"
            :items="[
                { label: 'Ranges', value: ranges.length },
                { label: 'Used', value: totalUsed, mono: true },
                { label: 'Total', value: formatPoolTotal(totalAddresses), mono: true },
                { label: 'Utilisation', value: overallUtilisation },
            ]"
        />

        <SectionHeader title="Configured Ranges" />

        <DataTable
            :columns="rangeColumns"
            :rows="sortedRanges"
            :sort-column="sortColumn"
            :sort-direction="sortDirection"
            empty-message="No DHCP ranges configured."
            @update:sort-column="sortColumn = $event"
            @update:sort-direction="sortDirection = $event"
        >
            <template #row="{ row, index }">
                <td :data-testid="`range-row-${index}-network`">
                    <Link
                        v-if="row.network"
                        :href="route('admin.dhcp.leases', { network: row.network })"
                        class="font-mono text-[13px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                    >
                        {{ row.network }}
                    </Link>
                    <span v-else class="text-[var(--color-text-muted)]">&mdash;</span>
                </td>
                <td :data-testid="`range-row-${index}-start`" class="font-mono text-[var(--color-text-secondary)]">
                    {{ row.start || '—' }}
                </td>
                <td :data-testid="`range-row-${index}-end`" class="font-mono text-[var(--color-text-secondary)]">
                    {{ row.end || '—' }}
                </td>
                <td :data-testid="`range-row-${index}-usage`">
                    <div class="flex items-center gap-2">
                        <template v-if="row.percentage != null">
                            <div class="h-[6px] w-24 overflow-hidden rounded-[3px] bg-[var(--color-surface-hover)]">
                                <div
                                    :data-testid="`range-usage-bar-${index}`"
                                    class="h-full rounded-[3px] transition-[width] duration-300"
                                    :style="{
                                        width: `${row.percentage > 0 ? Math.max(Math.min(row.percentage, 100), 2) : 0}%`,
                                        backgroundColor: barColor(row.percentage),
                                    }"
                                />
                            </div>
                            <span
                                :data-testid="`range-percentage-${index}`"
                                class="min-w-[32px] text-right font-mono text-[11px]"
                                :class="
                                    row.percentage > 90
                                        ? 'text-[var(--color-danger)]'
                                        : 'text-[var(--color-text-secondary)]'
                                "
                            >
                                {{ row.percentage.toFixed(1) }}%
                            </span>
                        </template>
                        <span
                            v-else
                            :data-testid="`range-percentage-${index}`"
                            class="min-w-[32px] text-right font-mono text-[11px] text-[var(--color-text-muted)]"
                        >
                            &mdash;
                        </span>
                        <span
                            :data-testid="`range-count-${index}`"
                            class="font-mono text-[11px] text-[var(--color-text-muted)]"
                            :title="row.total != null ? String(row.total) : undefined"
                        >
                            {{ usageLabel(row) }}
                        </span>
                    </div>
                </td>
            </template>
        </DataTable>
    </div>
</template>
