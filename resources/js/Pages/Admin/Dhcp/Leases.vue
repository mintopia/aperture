<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import { normalizeMac } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    leases: { type: Array, default: () => [] },
    ranges: { type: Array, default: () => [] },
});

const initialNetwork =
    typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('network') || '' : '';

const search = ref('');
const filterValues = ref({ range: initialNetwork });
const sortColumn = ref('ip');
const sortDirection = ref('asc');
const displayLimit = ref(50);

const columns = [
    { key: 'ip', label: 'IP Address', sortable: true },
    { key: 'mac', label: 'MAC Address', sortable: true },
    { key: 'hostname', label: 'Hostname', sortable: true },
    { key: 'expires', label: 'Expires', sortable: true },
];

const rangeFilterDef = computed(() => {
    if (props.ranges.length === 0) return [];
    return [
        {
            key: 'range',
            label: 'Range',
            allLabel: 'All Ranges',
            options: props.ranges.map((r) => ({
                value: r.network,
                label: r.network,
            })),
        },
    ];
});

const totalFilteredCount = computed(() => {
    let filtered = props.leases;

    const selectedRange = filterValues.value.range;
    if (selectedRange) {
        const range = props.ranges.find((r) => r.network === selectedRange);
        if (range && range.start && range.end) {
            filtered = filtered.filter((lease) => isIpInRange(lease.ip, range.start, range.end));
        }
    }

    const term = search.value.toLowerCase().trim();
    if (term) {
        filtered = filtered.filter((lease) => {
            const ip = (lease.ip || '').toLowerCase();
            const mac = (lease.mac || '').toLowerCase();
            const hostname = (lease.hostname || '').toLowerCase();
            return ip.includes(term) || mac.includes(term) || hostname.includes(term);
        });
    }

    return filtered.length;
});

const filteredLeases = computed(() => {
    let filtered = props.leases;

    // Filter by range
    const selectedRange = filterValues.value.range;
    if (selectedRange) {
        const range = props.ranges.find((r) => r.network === selectedRange);
        if (range && range.start && range.end) {
            filtered = filtered.filter((lease) => isIpInRange(lease.ip, range.start, range.end));
        }
    }

    // Filter by search
    const term = search.value.toLowerCase().trim();
    if (term) {
        filtered = filtered.filter((lease) => {
            const ip = (lease.ip || '').toLowerCase();
            const mac = (lease.mac || '').toLowerCase();
            const hostname = (lease.hostname || '').toLowerCase();
            return ip.includes(term) || mac.includes(term) || hostname.includes(term);
        });
    }

    // Sort
    filtered = [...filtered].sort((a, b) => {
        const aVal = a[sortColumn.value] || '';
        const bVal = b[sortColumn.value] || '';

        if (sortColumn.value === 'ip') {
            const aNum = aVal.includes(':') ? aVal : ipToNumber(aVal);
            const bNum = bVal.includes(':') ? bVal : ipToNumber(bVal);
            return sortDirection.value === 'asc' ? (aNum > bNum ? 1 : -1) : aNum < bNum ? 1 : -1;
        }

        const comparison = aVal.toString().localeCompare(bVal.toString());
        return sortDirection.value === 'asc' ? comparison : -comparison;
    });

    // Limit display
    return filtered.slice(0, displayLimit.value);
});

const hasMoreLeases = computed(() => totalFilteredCount.value > displayLimit.value);

function toggleSort(columnKey) {
    if (sortColumn.value === columnKey) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = columnKey;
        sortDirection.value = 'asc';
    }
}

function showMore() {
    displayLimit.value += 50;
}

function isIpInRange(ip, start, end) {
    if (!ip || !start || !end) return false;

    if (ip.includes(':')) {
        return ip >= start && ip <= end;
    }

    const ipNum = ipToNumber(ip);
    const startNum = ipToNumber(start);
    const endNum = ipToNumber(end);

    return ipNum >= startNum && ipNum <= endNum;
}

function ipToNumber(ip) {
    return ip.split('.').reduce((acc, octet) => acc * 256 + parseInt(octet, 10), 0);
}

function formatExpiry(expires) {
    if (!expires) return 'Never';

    if (/^\d+$/.test(expires)) {
        const timestamp = parseInt(expires, 10);
        const ms = timestamp > 1e12 ? timestamp : timestamp * 1000;
        return new Date(ms).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    try {
        const date = new Date(expires);
        if (!isNaN(date.getTime())) {
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        }
    } catch {
        // Fall through to return as-is
    }

    return expires;
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
                DHCP Leases
            </h1>
            <Link
                :href="route('admin.dhcp.index')"
                data-testid="back-to-ranges-link"
                class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
            >
                &larr; Back to Ranges
            </Link>
        </div>

        <MetadataStrip
            :items="[
                { label: 'Total Leases', value: leases.length },
                { label: 'Ranges', value: ranges.length },
                {
                    label: 'Unique MACs',
                    value: new Set(leases.map((l) => normalizeMac(l.mac))).size,
                },
            ]"
        />

        <FilterBar
            :search="search"
            search-placeholder="Search leases…"
            :filters="rangeFilterDef"
            :filter-values="filterValues"
            :total-count="leases.length"
            :filtered-count="totalFilteredCount"
            @update:search="search = $event"
            @update:filter-values="filterValues = $event"
        />

        <div data-testid="data-table" class="overflow-x-auto">
            <table class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            v-for="(col, colIdx) in columns"
                            :key="col.key"
                            class="border-b border-[var(--color-border-hover)] py-2 text-left text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                            :class="[col.sortable ? 'cursor-pointer' : '', colIdx > 0 ? 'pl-6' : '']"
                            @click="col.sortable ? toggleSort(col.key) : null"
                        >
                            <div class="flex items-center gap-1.5">
                                {{ col.label }}
                                <span v-if="col.sortable && sortColumn === col.key" class="text-[var(--color-primary)]">
                                    {{ sortDirection === 'asc' ? '↑' : '↓' }}
                                </span>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="filteredLeases.length === 0" data-testid="data-table-empty">
                        <td :colspan="columns.length" class="py-12 text-center text-[var(--color-text-muted)]">
                            No active leases
                        </td>
                    </tr>
                    <tr
                        v-for="(row, index) in filteredLeases"
                        :key="index"
                        data-testid="data-table-row"
                        class="transition-colors"
                    >
                        <td
                            :data-testid="`lease-row-${index}-ip`"
                            class="border-b border-[var(--color-border)] py-[10px] align-top font-mono text-[13px] text-[var(--color-text)]"
                        >
                            {{ row.ip }}
                        </td>
                        <td
                            :data-testid="`lease-row-${index}-mac`"
                            class="border-b border-[var(--color-border)] py-[10px] pl-6 align-top font-mono text-[13px] text-[var(--color-text-secondary)]"
                        >
                            {{ normalizeMac(row.mac) }}
                        </td>
                        <td
                            :data-testid="`lease-row-${index}-hostname`"
                            class="border-b border-[var(--color-border)] py-[10px] pl-6 align-top text-[13px] text-[var(--color-text-secondary)]"
                        >
                            {{ row.hostname || '—' }}
                        </td>
                        <td
                            :data-testid="`lease-row-${index}-expires`"
                            class="border-b border-[var(--color-border)] py-[10px] pl-6 align-top text-[13px] text-[var(--color-text-muted)]"
                        >
                            {{ formatExpiry(row.expires) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="hasMoreLeases" class="mt-4 text-center">
            <button
                data-testid="show-more-button"
                class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
                @click="showMore"
            >
                Show More ({{ filteredLeases.length }} of {{ totalFilteredCount }})
            </button>
        </div>
    </div>
</template>
