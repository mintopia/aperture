<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import Pagination from '@/Components/UI/Pagination.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    users: Object,
    filters: Object,
});

const nickname = ref(props.filters?.nickname ?? '');
const ip = ref(props.filters?.ip ?? '');

const columns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'email', label: 'Email' },
    { key: 'ips', label: 'IPs' },
    { key: 'status', label: 'Status' },
];

function search() {
    router.get(
        route('admin.users.index'),
        {
            nickname: nickname.value,
            ip: ip.value,
        },
        { preserveState: true },
    );
}
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Users
        </h1>

        <div class="mb-4 flex flex-wrap gap-3">
            <input
                v-model="nickname"
                data-testid="search-nickname"
                placeholder="Filter by nickname..."
                class="rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-1.5 text-sm text-[var(--color-text)]"
                @keyup.enter="search"
            />
            <input
                v-model="ip"
                data-testid="search-ip"
                placeholder="Filter by IP..."
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
            :rows="users.data"
            clickable
            :row-href="(row) => route('admin.users.show', row.id)"
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 text-sm text-[var(--color-text)]">
                    {{ row.nickname }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.email }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.ips?.length ?? 0 }}
                </td>
                <td class="px-4 py-2.5">
                    <StatusPill
                        :status="row.blocked ? 'danger' : 'success'"
                        :label="row.blocked ? 'Blocked' : 'Active'"
                    />
                </td>
            </template>
        </DataTable>

        <Pagination :paginator="users" class="mt-4" />
    </div>
</template>
