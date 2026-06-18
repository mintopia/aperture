<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    users: { type: Object, default: () => ({}) },
    summary: { type: Object, default: () => ({ total: 0, active: 0, blocked: 0 }) },
    filters: { type: Object, default: () => ({}) },
});

const searchQuery = ref(props.filters?.search ?? '');
const filterValues = ref({
    status: props.filters?.status ?? '',
});

function applyFilters() {
    router.get(
        route('admin.users.index'),
        {
            search: searchQuery.value,
            status: filterValues.value.status,
        },
        { preserveState: true },
    );
}

function onSearchUpdate(value) {
    searchQuery.value = value;
    applyFilters();
}

function onFilterUpdate(values) {
    filterValues.value = values;
    applyFilters();
}

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
    { key: 'bandwidth', label: 'Bandwidth (7d)' },
    { key: 'status', label: 'Status' },
];

const allUsers = computed(() => props.users.data ?? []);

const hasActiveFilter = computed(() => searchQuery.value !== '' || filterValues.value.status !== '');

const userSummary = computed(() => ({
    total: props.summary?.total ?? 0,
    active: props.summary?.active ?? 0,
    blocked: props.summary?.blocked ?? 0,
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
            v-if="userSummary.total > 0"
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
                    data-testid="summary-total-value"
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
                    data-testid="summary-active-value"
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
                    data-testid="summary-blocked-value"
                    class="font-heading text-[20px] font-bold text-[var(--color-danger)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ userSummary.blocked }}
                </p>
            </div>
        </div>

        <!-- Table section -->
        <section v-if="allUsers.length > 0 || hasActiveFilter" data-testid="users-table-section" class="mb-8">
            <FilterBar
                :search="searchQuery"
                search-placeholder="Search users…"
                :filters="filterDefinitions"
                :filter-values="filterValues"
                :total-count="users.total ?? allUsers.length"
                :filtered-count="allUsers.length"
                @update:search="onSearchUpdate"
                @update:filter-values="onFilterUpdate"
            />

            <DataTable
                :columns="columns"
                :rows="allUsers"
                clickable
                :row-href="(row) => route('admin.users.show', row.id)"
                :row-aria-label="(row) => `Open user ${row.nickname}`"
                empty-message="No users match your search."
            >
                <template #row="{ row }">
                    <td data-testid="user-nickname" class="text-[13px] font-semibold text-[var(--color-text)]">
                        {{ row.nickname }}
                    </td>
                    <td data-testid="user-email" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.email }}
                    </td>
                    <td data-testid="user-ips-count" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.ips?.length ?? 0 }}
                    </td>
                    <td data-testid="user-bandwidth">
                        <div class="flex items-baseline gap-3">
                            <span>
                                <span
                                    class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                    >Down</span
                                >
                                <span class="ml-0.5 font-mono text-[13px] text-[var(--color-success)]">{{
                                    formatBytes(row.weekly_received ?? 0)
                                }}</span>
                            </span>
                            <span>
                                <span
                                    class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                    >Up</span
                                >
                                <span class="ml-0.5 font-mono text-[13px] text-[var(--color-info)]">{{
                                    formatBytes(row.weekly_sent ?? 0)
                                }}</span>
                            </span>
                        </div>
                    </td>
                    <td data-testid="user-status">
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="h-[7px] w-[7px] rounded-full"
                                :class="
                                    row.internet_blocked
                                        ? 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]'
                                        : 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                "
                            />
                            <span
                                class="text-[12px] font-semibold"
                                :class="
                                    row.internet_blocked ? 'text-[var(--color-danger)]' : 'text-[var(--color-success)]'
                                "
                            >
                                {{ row.internet_blocked ? 'Denied' : 'Allowed' }}
                            </span>
                        </span>
                    </td>
                </template>
            </DataTable>
        </section>

        <Pagination :paginator="users" class="mt-4" />
    </div>
</template>
