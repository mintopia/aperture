<script setup>
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatBytes } from '@/helpers.js';
import { ipStatusLabel, ipStatusDotClass, ipStatusTextClass } from '@/utils/ipStatus';

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
    search();
}

function onFilterUpdate(values) {
    filterValues.value = values;
    search();
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
            <Link
                :href="route('admin.ips.create')"
                data-testid="action-create-ip"
                class="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-bold text-[var(--color-accent-text)] transition-all hover:bg-[var(--color-primary-hover)]"
            >
                Add IP Address
            </Link>
        </div>

        <SectionHeader title="Address List" class="mt-6" />

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
                <td data-testid="ip-mac" class="font-mono text-[13px]">
                    <Link
                        v-if="row.mac"
                        :href="route('admin.macs.show', row.mac)"
                        class="text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                        @click.stop
                    >
                        {{ row.mac }}
                    </Link>
                    <span v-else class="text-[var(--color-text-muted)]">—</span>
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
                        <span class="h-[7px] w-[7px] rounded-full" :class="ipStatusDotClass(row.allowed)" />
                        <span class="text-[12px] font-semibold" :class="ipStatusTextClass(row.allowed)">
                            {{ ipStatusLabel(row.allowed) }}
                        </span>
                    </span>
                </td>
            </template>
        </DataTable>

        <Pagination :paginator="ips" class="mt-4" />
    </div>
</template>
