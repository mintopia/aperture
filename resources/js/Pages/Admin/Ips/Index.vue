<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ips: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const searchQuery = ref(props.filters?.address ?? '');
const filterValues = ref({
    status: props.filters?.status ?? '',
});

const filterDefinitions = [
    {
        key: 'status',
        label: 'Status',
        options: [
            { value: 'allowed', label: 'Allowed' },
            { value: 'denied', label: 'Denied' },
        ],
    },
];

const columns = [
    { key: 'address', label: 'Address' },
    { key: 'mac', label: 'MAC' },
    { key: 'user', label: 'User' },
    { key: 'downloaded', label: 'Downloaded' },
    { key: 'uploaded', label: 'Uploaded' },
    { key: 'status', label: 'Status' },
];

function search() {
    router.get(
        route('admin.ips.index'),
        {
            address: searchQuery.value,
            status: filterValues.value.status,
        },
        { preserveState: true },
    );
}

function onSearchUpdate(value) {
    searchQuery.value = value;
}

function onFilterUpdate(values) {
    filterValues.value = values;
    search();
}

function statusLabel(allowed) {
    if (allowed === true) return 'Allowed';
    if (allowed === false) return 'Denied';
    return '\u2014';
}

function statusDotClass(allowed) {
    if (allowed === true) return 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]';
    if (allowed === false) return 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]';
    return 'bg-[var(--color-text-muted)]';
}

function statusTextClass(allowed) {
    if (allowed === true) return 'text-[var(--color-success)]';
    if (allowed === false) return 'text-[var(--color-danger)]';
    return 'text-[var(--color-text-muted)]';
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
                IP Addresses
            </h1>
        </div>

        <h2
            class="font-heading mt-6 mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
            style="font-variation-settings: 'opsz' 16"
        >
            Address List
        </h2>

        <FilterBar
            :search="searchQuery"
            search-placeholder="Filter by address…"
            :filters="filterDefinitions"
            :filter-values="filterValues"
            :total-count="ips.total ?? 0"
            :filtered-count="ips.data?.length ?? 0"
            data-testid="ip-filter-bar"
            @update:search="onSearchUpdate"
            @update:filter-values="onFilterUpdate"
            @keyup.enter="search"
        />

        <DataTable
            :columns="columns"
            :rows="ips.data"
            clickable
            :row-href="(row) => route('admin.ips.show', row.address)"
        >
            <template #row="{ row }">
                <td data-testid="ip-address" class="font-mono text-[13px] text-[var(--color-primary)]">
                    {{ row.address }}
                </td>
                <td data-testid="ip-mac" class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                    {{ row.mac ?? '—' }}
                </td>
                <td data-testid="ip-user">
                    <Link
                        v-if="row.users?.[0]?.user"
                        :href="route('admin.users.show', row.users[0].user.id)"
                        class="text-[13px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                        @click.stop
                    >
                        {{ row.users[0].user.nickname }}
                    </Link>
                    <span v-else class="text-[13px] text-[var(--color-text-muted)]">—</span>
                </td>
                <td data-testid="ip-downloaded" class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.received) }}
                </td>
                <td data-testid="ip-uploaded" class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.sent) }}
                </td>
                <td data-testid="ip-status">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-[7px] w-[7px] rounded-full" :class="statusDotClass(row.allowed)" />
                        <span class="text-[12px] font-semibold" :class="statusTextClass(row.allowed)">
                            {{ statusLabel(row.allowed) }}
                        </span>
                    </span>
                </td>
            </template>
        </DataTable>

        <Pagination :paginator="ips" class="mt-4" />
    </div>
</template>
