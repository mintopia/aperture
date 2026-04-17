<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    leases: { type: Array, default: () => [] },
    ranges: { type: Array, default: () => [] },
});

const selectedRange = ref('all');
const sortColumn = ref('ip');
const sortDirection = ref('asc');
const displayLimit = ref(50);

const columns = [
    { key: 'ip', label: 'IP Address', sortable: true },
    { key: 'mac', label: 'MAC Address', sortable: true },
    { key: 'hostname', label: 'Hostname', sortable: true },
    { key: 'expires', label: 'Expires', sortable: true },
];

const filteredLeases = computed(() => {
    let filtered = props.leases;

    // Filter by range
    if (selectedRange.value !== 'all') {
        const range = props.ranges.find((r) => r.name === selectedRange.value);
        if (range && range.start && range.end) {
            filtered = filtered.filter((lease) => isIpInRange(lease.ip, range.start, range.end));
        }
    }

    // Sort
    filtered = [...filtered].sort((a, b) => {
        const aVal = a[sortColumn.value] || '';
        const bVal = b[sortColumn.value] || '';

        if (sortColumn.value === 'ip') {
            // IP sorting - convert to numbers for proper comparison
            const aNum = aVal.includes(':') ? aVal : ipToNumber(aVal);
            const bNum = bVal.includes(':') ? bVal : ipToNumber(bVal);
            return sortDirection.value === 'asc' ? (aNum > bNum ? 1 : -1) : aNum < bNum ? 1 : -1;
        }

        // String comparison for other columns
        const comparison = aVal.toString().localeCompare(bVal.toString());
        return sortDirection.value === 'asc' ? comparison : -comparison;
    });

    // Limit display
    return filtered.slice(0, displayLimit.value);
});

const hasMoreLeases = computed(() => {
    let totalFiltered = props.leases.length;

    if (selectedRange.value !== 'all') {
        const range = props.ranges.find((r) => r.name === selectedRange.value);
        if (range && range.start && range.end) {
            totalFiltered = props.leases.filter((lease) => isIpInRange(lease.ip, range.start, range.end)).length;
        }
    }

    return totalFiltered > displayLimit.value;
});

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

    // Check if IPv4 or IPv6
    if (ip.includes(':')) {
        // IPv6 - simple string comparison for now
        return ip >= start && ip <= end;
    }

    // IPv4 - convert to numbers for comparison
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

    // Check if it's a unix timestamp (numeric string)
    if (/^\d+$/.test(expires)) {
        const timestamp = parseInt(expires, 10);
        // Handle both seconds and milliseconds timestamps
        const ms = timestamp > 1e12 ? timestamp : timestamp * 1000;
        return new Date(ms).toLocaleString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    // Already formatted or ISO string - parse and reformat
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

    // Return as-is if parsing fails
    return expires;
}
</script>

<template>
    <div>
        <div class="mb-5 flex items-center justify-between gap-4">
            <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
                DHCP Leases
            </h1>
            <Link
                :href="route('admin.dhcp.index')"
                data-testid="back-to-ranges-link"
                class="text-sm text-[var(--color-primary)] hover:underline"
            >
                ← Back to Ranges
            </Link>
        </div>

        <div v-if="ranges.length > 0" class="mb-4">
            <label for="range-filter" class="mb-2 block text-sm font-medium text-[var(--color-text)]">
                Filter by Range
            </label>
            <select
                id="range-filter"
                v-model="selectedRange"
                data-testid="range-filter"
                class="rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-3 py-2 text-sm text-[var(--color-text)] focus:border-[var(--color-primary)] focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:outline-none"
            >
                <option value="all">All Ranges</option>
                <option v-for="range in ranges" :key="range.name" :value="range.name">
                    {{ range.name }} ({{ range.network }})
                </option>
            </select>
        </div>

        <div data-testid="data-table" class="overflow-x-auto rounded-lg border border-[var(--color-border)]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b-2 border-[var(--color-border)] bg-[var(--color-surface)]">
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-2.5 text-left text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                            :class="{ 'cursor-pointer hover:bg-[var(--color-surface-hover)]': col.sortable }"
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
                        <td :colspan="columns.length" class="px-4 py-12 text-center text-[var(--color-text-muted)]">
                            No active leases
                        </td>
                    </tr>
                    <tr
                        v-for="(row, index) in filteredLeases"
                        :key="index"
                        data-testid="data-table-row"
                        class="border-b border-[var(--color-border)] transition-colors last:border-b-0"
                    >
                        <td
                            :data-testid="`lease-row-${index}-ip`"
                            class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]"
                        >
                            {{ row.ip }}
                        </td>
                        <td
                            :data-testid="`lease-row-${index}-mac`"
                            class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-secondary)]"
                        >
                            {{ row.mac }}
                        </td>
                        <td
                            :data-testid="`lease-row-${index}-hostname`"
                            class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]"
                        >
                            {{ row.hostname || '—' }}
                        </td>
                        <td
                            :data-testid="`lease-row-${index}-expires`"
                            class="px-4 py-2.5 text-sm text-[var(--color-text-muted)]"
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
                class="rounded-lg border border-[var(--color-border)] bg-[var(--color-bg-secondary)] px-6 py-2 text-sm font-medium text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                @click="showMore"
            >
                Show More ({{ filteredLeases.length }} of
                {{
                    selectedRange === 'all'
                        ? leases.length
                        : leases.filter((l) =>
                              isIpInRange(
                                  l.ip,
                                  ranges.find((r) => r.name === selectedRange)?.start,
                                  ranges.find((r) => r.name === selectedRange)?.end,
                              ),
                          ).length
                }})
            </button>
        </div>
    </div>
</template>
