<script setup>
import { ref, computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    users: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const searchQuery = ref(props.filters?.nickname ?? '');
const filterValues = ref({
    status: props.filters?.status ?? '',
});

const filterDefinitions = computed(() => [
    {
        key: 'status',
        label: 'Status',
        options: [
            { value: 'active', label: 'Active' },
            { value: 'blocked', label: 'Blocked' },
        ],
    },
]);

const columns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'email', label: 'Email' },
    { key: 'ips', label: 'IPs' },
    { key: 'status', label: 'Status' },
];

const allUsers = computed(() => props.users.data ?? []);

const filteredUsers = computed(() => {
    let result = allUsers.value;
    const q = searchQuery.value.toLowerCase().trim();

    if (q) {
        result = result.filter((u) => u.nickname.toLowerCase().includes(q) || u.email.toLowerCase().includes(q));
    }

    if (filterValues.value.status === 'active') {
        result = result.filter((u) => !u.internet_blocked);
    } else if (filterValues.value.status === 'blocked') {
        result = result.filter((u) => u.internet_blocked);
    }

    return result;
});

const userSummary = computed(() => ({
    total: allUsers.value.length,
    active: allUsers.value.filter((u) => !u.internet_blocked).length,
    blocked: allUsers.value.filter((u) => u.internet_blocked).length,
}));
</script>

<template>
    <div data-testid="users-index-layout">
        <!-- Page Header -->
        <header data-testid="users-index-header" class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Users
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Manage registered user accounts.</p>
            </div>
        </header>

        <!-- Summary strip -->
        <div
            v-if="allUsers.length > 0"
            data-testid="users-summary"
            class="mt-6 mb-7 flex flex-wrap gap-y-3 border-b border-[var(--color-border)] pb-5"
        >
            <div
                class="mr-8 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-1/3 max-sm:border-0 max-sm:pr-0"
            >
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Total
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ userSummary.total }}
                </p>
            </div>
            <div
                class="mr-8 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-1/3 max-sm:border-0 max-sm:pr-0"
            >
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Active
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-success)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ userSummary.active }}
                </p>
            </div>
            <div class="max-sm:basis-1/3">
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Blocked
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-danger)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ userSummary.blocked }}
                </p>
            </div>
        </div>

        <!-- Table section -->
        <section v-if="allUsers.length > 0" data-testid="users-table-section" class="mb-8">
            <FilterBar
                :search="searchQuery"
                search-placeholder="Search users…"
                :filters="filterDefinitions"
                :filter-values="filterValues"
                :total-count="allUsers.length"
                :filtered-count="filteredUsers.length"
                @update:search="searchQuery = $event"
                @update:filter-values="filterValues = $event"
            />

            <DataTable
                :columns="columns"
                :rows="filteredUsers"
                clickable
                :row-href="(row) => route('admin.users.show', row.id)"
                :row-aria-label="(row) => `Open user ${row.nickname}`"
                empty-message="No users match your search."
            >
                <template #row="{ row }">
                    <td class="text-[13px] font-semibold text-[var(--color-text)]">
                        {{ row.nickname }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.email }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.ips?.length ?? 0 }}
                    </td>
                    <td>
                        <StatusPill
                            :status="row.internet_blocked ? 'danger' : 'success'"
                            :label="row.internet_blocked ? 'Blocked' : 'Active'"
                        />
                    </td>
                </template>
            </DataTable>
        </section>

        <Pagination :paginator="users" class="mt-4" />
    </div>
</template>
