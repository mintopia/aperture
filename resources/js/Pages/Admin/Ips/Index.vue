<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ips: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const address = ref(props.filters?.address ?? '');
const nickname = ref(props.filters?.nickname ?? '');

const columns = [
    { key: 'address', label: 'Address' },
    { key: 'user', label: 'User' },
    { key: 'downloaded', label: 'Downloaded' },
    { key: 'uploaded', label: 'Uploaded' },
    { key: 'status', label: 'Status' },
];

function search() {
    router.get(
        route('admin.ips.index'),
        {
            address: address.value,
            nickname: nickname.value,
        },
        { preserveState: true },
    );
}
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            IP Addresses
        </h1>

        <div class="mb-4 flex flex-wrap gap-3">
            <input
                v-model="address"
                data-testid="search-address"
                placeholder="Filter by address..."
                class="rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-1.5 text-sm text-[var(--color-text)]"
                @keyup.enter="search"
            />
            <input
                v-model="nickname"
                data-testid="search-nickname"
                placeholder="Filter by user..."
                class="rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-1.5 text-sm text-[var(--color-text)]"
                @keyup.enter="search"
            />
            <button
                data-testid="action-search"
                class="rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-hover)]"
                @click="search"
            >
                Search
            </button>
        </div>

        <DataTable
            :columns="columns"
            :rows="ips.data"
            clickable
            :row-href="(row) => route('admin.ips.show', row.address)"
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.address }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.users?.[0]?.user?.nickname ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.received) }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.sent) }}
                </td>
                <td class="px-4 py-2.5">
                    <StatusPill
                        :status="row.allowed ? 'success' : 'danger'"
                        :label="row.allowed ? 'Allowed' : 'Denied'"
                    />
                </td>
            </template>
        </DataTable>

        <Pagination :paginator="ips" class="mt-4" />
    </div>
</template>
