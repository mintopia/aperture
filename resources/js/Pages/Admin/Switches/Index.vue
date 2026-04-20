<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import { formatRelative } from '@/utils/dates';
import { typeLabel, statusLabel } from '@/utils/switches';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switches: { type: Array, default: () => [] },
});

const sortColumn = ref('name');
const sortDirection = ref('asc');
const searchQuery = ref('');
const filterValues = ref({});

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'hostname', label: 'Hostname', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'enabled', label: 'Status', sortable: true },
    { key: 'port_count', label: 'Ports', sortable: true },
    { key: 'latest_sync_status', label: 'Sync', sortable: true },
];

const availableTypes = computed(() => {
    const types = [...new Set(props.switches.map((sw) => sw.type))].sort();
    return types.map((t) => ({ value: t, label: typeLabel(t) }));
});

const syncStatusOptions = [
    { value: 'completed', label: 'Completed' },
    { value: 'running', label: 'Running' },
    { value: 'failed', label: 'Failed' },
    { value: 'never', label: 'Never Synced' },
];

const filterDefinitions = computed(() => [
    { key: 'type', label: 'Type', options: availableTypes.value },
    {
        key: 'status',
        label: 'Status',
        options: [
            { value: 'enabled', label: 'Enabled' },
            { value: 'disabled', label: 'Disabled' },
        ],
    },
    { key: 'sync', label: 'Sync', options: syncStatusOptions },
]);

const filteredSwitches = computed(() => {
    let result = props.switches;
    const q = searchQuery.value.toLowerCase().trim();

    if (q) {
        result = result.filter((sw) => sw.name.toLowerCase().includes(q) || sw.hostname.toLowerCase().includes(q));
    }

    if (filterValues.value.type) {
        result = result.filter((sw) => sw.type === filterValues.value.type);
    }

    if (filterValues.value.status === 'enabled') {
        result = result.filter((sw) => sw.enabled);
    } else if (filterValues.value.status === 'disabled') {
        result = result.filter((sw) => !sw.enabled);
    }

    if (filterValues.value.sync === 'never') {
        result = result.filter((sw) => !sw.latest_sync_status);
    } else if (filterValues.value.sync) {
        result = result.filter((sw) => sw.latest_sync_status === filterValues.value.sync);
    }

    return result;
});

const sortedSwitches = computed(() => {
    return [...filteredSwitches.value].sort((a, b) => {
        let aVal = a[sortColumn.value];
        let bVal = b[sortColumn.value];

        if (aVal == null) aVal = '';
        if (bVal == null) bVal = '';

        if (typeof aVal === 'number' && typeof bVal === 'number') {
            return sortDirection.value === 'asc' ? aVal - bVal : bVal - aVal;
        }

        if (typeof aVal === 'boolean') {
            aVal = aVal ? 1 : 0;
            bVal = bVal ? 1 : 0;
            return sortDirection.value === 'asc' ? aVal - bVal : bVal - aVal;
        }

        const comparison = aVal.toString().localeCompare(bVal.toString());
        return sortDirection.value === 'asc' ? comparison : -comparison;
    });
});

const switchSummary = computed(() => ({
    total: props.switches.length,
    enabled: props.switches.filter((sw) => sw.enabled).length,
    disabled: props.switches.filter((sw) => !sw.enabled).length,
    neverSynced: props.switches.filter((sw) => !sw.last_synced_at).length,
}));

function toggleSort(columnKey) {
    if (sortColumn.value === columnKey) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn.value = columnKey;
        sortDirection.value = 'asc';
    }
}

function syncStatusDotClass(status) {
    const map = {
        completed: 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]',
        running: 'bg-[var(--color-warning)] shadow-[0_0_6px_var(--color-warning)]',
        failed: 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]',
    };
    return map[status] ?? 'bg-[var(--color-text-muted)] shadow-none';
}

function syncStatusTextClass(status) {
    const map = {
        completed: 'text-[var(--color-success)]',
        running: 'text-[var(--color-warning)]',
        failed: 'text-[var(--color-danger)]',
    };
    return map[status] ?? 'text-[var(--color-text-muted)]';
}

function syncStatusLabel(status) {
    const map = {
        completed: 'Completed',
        running: 'Running',
        failed: 'Failed',
        pending: 'Pending',
    };
    return map[status] ?? statusLabel(status);
}
</script>

<template>
    <div data-testid="switches-index-layout">
        <!-- Page Header -->
        <header data-testid="switches-index-header" class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Switches
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Manage configured network switches.</p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    :href="route('admin.switches.create')"
                    data-testid="action-add-switch"
                    class="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-bold text-[var(--color-bg)] transition-all hover:bg-[var(--color-primary-hover)]"
                >
                    Add Switch
                </Link>
            </div>
        </header>

        <!-- Summary strip -->
        <div
            v-if="switches.length > 0"
            data-testid="switches-summary"
            class="mt-6 mb-7 flex border-b border-[var(--color-border)] pb-5"
        >
            <div class="mr-8 border-r border-[var(--color-border)] pr-8">
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Total
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ switchSummary.total }}
                </p>
            </div>
            <div class="mr-8 border-r border-[var(--color-border)] pr-8">
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Enabled
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-success)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ switchSummary.enabled }}
                </p>
            </div>
            <div class="mr-8 border-r border-[var(--color-border)] pr-8">
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Disabled
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-text-muted)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ switchSummary.disabled }}
                </p>
            </div>
            <div>
                <p
                    class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >
                    Never Synced
                </p>
                <p
                    class="font-heading text-[20px] font-bold text-[var(--color-warning)]"
                    style="font-variation-settings: 'opsz' 28"
                >
                    {{ switchSummary.neverSynced }}
                </p>
            </div>
        </div>

        <!-- Empty state -->
        <EmptyState
            v-if="switches.length === 0"
            title="No switches configured"
            description="Add your first switch to get started."
        >
            <Link
                :href="route('admin.switches.create')"
                data-testid="empty-add-switch"
                class="mt-3 inline-flex items-center gap-1.5 rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-bold text-[var(--color-bg)] transition-all hover:bg-[var(--color-primary-hover)]"
            >
                Add Switch
            </Link>
        </EmptyState>

        <!-- Table section -->
        <section v-else data-testid="switches-table-card" class="mb-8">
            <h2
                class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                style="font-variation-settings: 'opsz' 16"
            >
                Configured Switches
            </h2>

            <FilterBar
                :search="searchQuery"
                search-placeholder="Search switches…"
                :filters="filterDefinitions"
                :filter-values="filterValues"
                :total-count="switches.length"
                :filtered-count="filteredSwitches.length"
                @update:search="searchQuery = $event"
                @update:filter-values="filterValues = $event"
            />

            <div data-testid="switches-table" class="overflow-x-auto">
                <table class="w-full border-collapse text-[13px]">
                    <caption class="sr-only">
                        Configured switches list with status, port health, and latest sync information.
                    </caption>
                    <thead>
                        <tr>
                            <th
                                v-for="(col, idx) in columns"
                                :key="col.key"
                                :aria-sort="
                                    col.sortable && sortColumn === col.key
                                        ? sortDirection === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                                :class="[
                                    'py-2 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase',
                                    'border-b border-[var(--color-border-hover)]',
                                    idx > 0 ? 'pl-6' : '',
                                ]"
                            >
                                <button
                                    v-if="col.sortable"
                                    type="button"
                                    :data-testid="`sort-${col.key}`"
                                    class="flex w-full cursor-pointer items-center gap-1 text-left hover:text-[var(--color-text)] focus-visible:outline-none"
                                    @click="toggleSort(col.key)"
                                >
                                    {{ col.label }}
                                    <span v-if="sortColumn === col.key" class="ml-0.5 text-[var(--color-primary)]">
                                        {{ sortDirection === 'asc' ? '↑' : '↓' }}
                                    </span>
                                </button>
                                <div v-else class="flex items-center gap-1">
                                    {{ col.label }}
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="sw in sortedSwitches"
                            :key="sw.id"
                            :data-testid="'switch-row-' + sw.id"
                            tabindex="0"
                            role="link"
                            :aria-label="`Open switch ${sw.name}`"
                            class="cursor-pointer border-b border-[var(--color-border)] align-top transition-colors last:border-b-0 hover:bg-[var(--color-surface-hover)] focus-visible:bg-[var(--color-surface-hover)] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[var(--color-primary)] focus-visible:outline-none"
                            @click="$inertia.visit(route('admin.switches.show', sw.id))"
                            @keydown.enter.prevent="$inertia.visit(route('admin.switches.show', sw.id))"
                            @keydown.space.prevent="$inertia.visit(route('admin.switches.show', sw.id))"
                        >
                            <!-- Name -->
                            <td class="py-2.5 text-[13px] font-semibold text-[var(--color-text)]">
                                {{ sw.name }}
                            </td>
                            <!-- Hostname -->
                            <td class="py-2.5 pl-6 font-mono text-[13px] text-[var(--color-text)]">
                                {{ sw.hostname }}
                            </td>
                            <!-- Type -->
                            <td class="py-2.5 pl-6">
                                <span
                                    class="inline-flex rounded bg-[var(--color-primary)]/[0.14] px-2 py-[2px] text-xs font-semibold text-[var(--color-primary)]"
                                >
                                    {{ typeLabel(sw.type) }}
                                </span>
                            </td>
                            <!-- Status -->
                            <td class="py-2.5 pl-6">
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        :class="[
                                            'inline-block h-[7px] w-[7px] rounded-full',
                                            sw.enabled
                                                ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                                : 'bg-[var(--color-text-muted)] shadow-none',
                                        ]"
                                    />
                                    <span
                                        :class="[
                                            'text-xs font-semibold',
                                            sw.enabled
                                                ? 'text-[var(--color-success)]'
                                                : 'text-[var(--color-text-muted)]',
                                        ]"
                                    >
                                        {{ sw.enabled ? 'Enabled' : 'Disabled' }}
                                    </span>
                                </span>
                            </td>
                            <!-- Ports -->
                            <td :data-testid="'port-breakdown-' + sw.id" class="py-2.5 pl-6">
                                <template v-if="sw.port_count > 0">
                                    <span class="inline-flex items-center gap-2 text-[13px]">
                                        <span class="text-[var(--color-success)]">{{ sw.ports_up }}↑</span>
                                        <span v-if="sw.ports_down > 0" class="text-[var(--color-text-muted)]"
                                            >{{ sw.ports_down }}↓</span
                                        >
                                        <span v-if="sw.ports_error > 0" class="text-[var(--color-danger)]"
                                            >{{ sw.ports_error }}⚠</span
                                        >
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="text-[var(--color-text-muted)]">—</span>
                                </template>
                            </td>
                            <!-- Sync -->
                            <td :data-testid="'sync-status-' + sw.id" class="py-2.5 pl-6">
                                <template v-if="sw.latest_sync_status">
                                    <div class="flex flex-col gap-px">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                :class="[
                                                    'inline-block h-[7px] w-[7px] rounded-full',
                                                    syncStatusDotClass(sw.latest_sync_status),
                                                ]"
                                            />
                                            <span
                                                :class="[
                                                    'text-xs font-semibold',
                                                    syncStatusTextClass(sw.latest_sync_status),
                                                ]"
                                            >
                                                {{ syncStatusLabel(sw.latest_sync_status) }}
                                            </span>
                                        </span>
                                        <span
                                            v-if="sw.last_synced_at"
                                            class="font-mono text-[11px] text-[var(--color-text-muted)]"
                                        >
                                            {{ formatRelative(sw.last_synced_at) }}
                                        </span>
                                    </div>
                                </template>
                                <template v-else>
                                    <span class="text-xs text-[var(--color-text-muted)]">Never synced</span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
