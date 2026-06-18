<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    macs: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const searchQuery = ref(props.filters?.search ?? '');
const filterValues = ref({
    source: props.filters?.source ?? '',
});

const filterDefinitions = [
    {
        key: 'source',
        label: 'Source',
        options: [
            { value: 'dhcp', label: 'DHCP' },
            { value: 'static', label: 'Static' },
            { value: 'snmp', label: 'SNMP' },
        ],
    },
];

const columns = [
    { key: 'mac_address', label: 'MAC Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'current_ips', label: 'Current IP(s)' },
    { key: 'user', label: 'User' },
    { key: 'source', label: 'Source' },
];

function onSearchUpdate(value) {
    searchQuery.value = value;
    router.get(
        route('admin.macs.index'),
        { search: value, source: filterValues.value.source },
        { preserveState: true },
    );
}

function onFilterUpdate(values) {
    filterValues.value = values;
    router.get(
        route('admin.macs.index'),
        { search: searchQuery.value, source: values.source },
        { preserveState: true },
    );
}
</script>

<template>
    <div data-testid="macs-index-layout">
        <!-- Page Header -->
        <header data-testid="macs-index-header" class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    MAC Addresses
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                    Browse and search MAC address records.
                </p>
            </div>
        </header>

        <SectionHeader title="Address List" class="mt-6" />

        <FilterBar
            :search="searchQuery"
            search-placeholder="Filter by MAC, hostname, or user…"
            :filters="filterDefinitions"
            :filter-values="filterValues"
            :total-count="macs.total ?? 0"
            :filtered-count="macs.data?.length ?? 0"
            data-testid="macs-filter-bar"
            @update:search="onSearchUpdate"
            @update:filter-values="onFilterUpdate"
        />

        <DataTable
            :columns="columns"
            :rows="macs.data"
            clickable
            :row-href="(row) => route('admin.macs.show', row.mac_address)"
            :row-aria-label="(row) => `Open MAC ${row.mac_address}`"
            empty-message="No MAC addresses found."
        >
            <template #row="{ row }">
                <td data-testid="mac-address" class="font-mono text-[13px] text-[var(--color-primary)]">
                    {{ row.mac_address }}
                </td>
                <td data-testid="mac-hostname" class="text-[13px] text-[var(--color-text-secondary)]">
                    {{ row.hostname ?? '—' }}
                </td>
                <td data-testid="mac-current-ips" class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                    <span v-if="row.current_ips && row.current_ips.length">
                        <Link
                            v-for="ip in row.current_ips"
                            :key="ip.id"
                            :href="route('admin.ips.show', ip.address)"
                            data-testid="mac-ip-link"
                            class="mr-2 inline-block text-[var(--color-primary)] hover:underline"
                            @click.stop
                        >
                            {{ ip.address }}
                        </Link>
                    </span>
                    <span v-else>—</span>
                </td>
                <td data-testid="mac-user">
                    <Link
                        v-if="row.user"
                        :href="route('admin.users.show', row.user.id)"
                        class="text-[13px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                        @click.stop
                    >
                        {{ row.user.nickname }}
                    </Link>
                    <span v-else class="text-[13px] text-[var(--color-text-muted)]">—</span>
                </td>
                <td data-testid="mac-source" class="text-[13px] text-[var(--color-text-secondary)]">
                    {{ row.source ?? '—' }}
                </td>
            </template>
        </DataTable>

        <Pagination :paginator="macs" class="mt-4" />
    </div>
</template>
