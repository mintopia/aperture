<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ranges: { type: Array, default: () => [] },
});

const sortColumn = ref('name');
const sortDirection = ref('asc');

const rangeColumns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'network', label: 'Network', sortable: true },
    { key: 'start', label: 'Start', sortable: true },
    { key: 'end', label: 'End', sortable: true },
    { key: 'usage', label: 'Usage', sortable: true },
];

const sortedRanges = computed(() => {
    return [...props.ranges].sort((a, b) => {
        let aVal = a[sortColumn.value];
        let bVal = b[sortColumn.value];

        // Special handling for usage column - sort by percentage
        if (sortColumn.value === 'usage') {
            aVal = a.percentage || 0;
            bVal = b.percentage || 0;
        }

        // Handle null/undefined
        if (aVal == null) aVal = '';
        if (bVal == null) bVal = '';

        // Numeric comparison for percentage
        if (typeof aVal === 'number' && typeof bVal === 'number') {
            return sortDirection.value === 'asc' ? aVal - bVal : bVal - aVal;
        }

        // String comparison
        const comparison = aVal.toString().localeCompare(bVal.toString());
        return sortDirection.value === 'asc' ? comparison : -comparison;
    });
});
</script>

<template>
    <div>
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                DHCP Ranges
            </h1>
            <Link
                :href="route('admin.dhcp.leases')"
                data-testid="view-leases-button"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
            >
                View Leases
            </Link>
        </div>

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
                <td :data-testid="`range-row-${index}-name`" class="font-medium text-[var(--color-text)]">
                    {{ row.name || '—' }}
                </td>
                <td :data-testid="`range-row-${index}-network`" class="font-mono text-[var(--color-text-secondary)]">
                    {{ row.network || '—' }}
                </td>
                <td :data-testid="`range-row-${index}-start`" class="font-mono text-[var(--color-text-secondary)]">
                    {{ row.start || '—' }}
                </td>
                <td :data-testid="`range-row-${index}-end`" class="font-mono text-[var(--color-text-secondary)]">
                    {{ row.end || '—' }}
                </td>
                <td :data-testid="`range-row-${index}-usage`">
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-32 overflow-hidden rounded-full bg-[var(--color-surface-hover)]">
                                <div
                                    :data-testid="`range-usage-bar-${index}`"
                                    class="h-full rounded-full transition-all"
                                    :class="
                                        (row.percentage ?? 0) > 90
                                            ? 'bg-[var(--color-danger)]'
                                            : (row.percentage ?? 0) > 70
                                              ? 'bg-[var(--color-warning)]'
                                              : 'bg-[var(--color-success)]'
                                    "
                                    :style="{
                                        width: `${(row.percentage ?? 0) > 0 ? Math.max(Math.min(row.percentage ?? 0, 100), 2) : 0}%`,
                                    }"
                                />
                            </div>
                            <span
                                :data-testid="`range-percentage-${index}`"
                                class="font-mono text-xs whitespace-nowrap text-[var(--color-text-muted)]"
                            >
                                {{ (row.percentage ?? 0).toFixed(1) }}%
                            </span>
                        </div>
                        <span
                            :data-testid="`range-used-${index}`"
                            class="font-mono text-xs text-[var(--color-text-secondary)]"
                        >
                            {{ row.used }} / {{ row.total }}
                        </span>
                    </div>
                </td>
            </template>
        </DataTable>
    </div>
</template>
