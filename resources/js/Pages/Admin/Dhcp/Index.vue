<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import ProgressBar from '@/Components/UI/ProgressBar.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import DataTable from '@/Components/UI/DataTable.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    pool: { type: Object, default: () => ({}) },
    ranges: { type: Array, default: () => [] },
});

const rangeColumns = [
    { key: 'interface', label: 'Interface' },
    { key: 'type', label: 'Type' },
    { key: 'subnet', label: 'Subnet / Prefix' },
    { key: 'range', label: 'Range' },
    { key: 'gateway', label: 'Gateway' },
    { key: 'description', label: 'Description' },
];
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            DHCP
        </h1>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Total" :value="pool?.total ?? '—'" />
            <StatCard label="Used" :value="pool?.used ?? '—'" color="accent" />
            <StatCard label="Available" :value="pool?.available ?? '—'" color="success" />
            <StatCard
                label="Utilisation"
                :value="pool?.utilisation ? (pool.utilisation * 100).toFixed(1) + '%' : '—'"
            />
        </div>

        <template v-if="pool?.used && pool?.total">
            <SectionHeader title="Pool Utilisation" accent-line />
            <ProgressBar
                label="DHCP Pool"
                :value="pool.used"
                :max="pool.total"
                :color="pool.utilisation > 0.9 ? 'danger' : pool.utilisation > 0.7 ? 'warning' : 'primary'"
                :display-value="`${pool.used}/${pool.total}`"
            />
        </template>

        <div data-testid="dhcp-ranges" class="mt-6">
            <SectionHeader title="DHCP Ranges" accent-line />
            <DataTable :columns="rangeColumns" :rows="ranges" empty-message="No DHCP ranges configured.">
                <template #row="{ row, index }">
                    <td
                        :data-testid="`range-row-${index}-interface`"
                        class="px-4 py-2.5 text-sm font-medium text-[var(--color-text)]"
                    >
                        {{ row.interface || '—' }}
                    </td>
                    <td :data-testid="`range-row-${index}-type`" class="px-4 py-2.5 text-sm">
                        <span
                            :data-testid="`range-type-badge-${index}`"
                            :class="
                                row.type === 'ipv4'
                                    ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300'
                                    : 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300'
                            "
                            class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                        >
                            {{ row.type === 'ipv4' ? 'IPv4' : 'IPv6' }}
                        </span>
                    </td>
                    <td
                        :data-testid="`range-row-${index}-subnet`"
                        class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-secondary)]"
                    >
                        {{ row.subnet || row.prefix || '—' }}
                    </td>
                    <td
                        :data-testid="`range-row-${index}-range`"
                        class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-secondary)]"
                    >
                        {{ row.range_from && row.range_to ? `${row.range_from} – ${row.range_to}` : '—' }}
                    </td>
                    <td
                        :data-testid="`range-row-${index}-gateway`"
                        class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-muted)]"
                    >
                        {{ row.gateway || '—' }}
                    </td>
                    <td
                        :data-testid="`range-row-${index}-description`"
                        class="px-4 py-2.5 text-sm text-[var(--color-text-muted)]"
                    >
                        {{ row.description || '—' }}
                    </td>
                </template>
            </DataTable>
        </div>

        <Link
            :href="route('admin.dhcp.leases')"
            class="mt-4 inline-block text-sm text-[var(--color-primary)] hover:underline"
        >
            View DHCP Leases →
        </Link>
    </div>
</template>
