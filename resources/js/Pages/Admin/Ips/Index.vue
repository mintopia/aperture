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
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                IP Addresses
            </h1>
        </div>

        <div class="mb-5 flex flex-wrap items-center gap-2.5">
            <input
                v-model="address"
                data-testid="search-address"
                placeholder="Filter by address..."
                class="rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                @keyup.enter="search"
            />
            <input
                v-model="nickname"
                data-testid="search-nickname"
                placeholder="Filter by user..."
                class="rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                @keyup.enter="search"
            />
            <button
                data-testid="action-search"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
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
                <td class="font-mono text-[13px] text-[var(--color-primary)]">
                    {{ row.address }}
                </td>
                <td class="text-[13px] text-[var(--color-text-secondary)]">
                    {{ row.users?.[0]?.user?.nickname ?? '—' }}
                </td>
                <td class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.received) }}
                </td>
                <td class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.sent) }}
                </td>
                <td>
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
