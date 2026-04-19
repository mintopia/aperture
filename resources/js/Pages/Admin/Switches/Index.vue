<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelative } from '@/utils/dates';
import { typeLabel, statusLabel } from '@/utils/switches';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switches: { type: Array, default: () => [] },
});

const sortColumn = ref('name');
const sortDirection = ref('asc');

const columns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'hostname', label: 'Hostname', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'enabled', label: 'Status', sortable: true },
    { key: 'port_count', label: 'Ports', sortable: true },
    { key: 'latest_sync_status', label: 'Sync', sortable: true },
];

const sortedSwitches = computed(() => {
    return [...props.switches].sort((a, b) => {
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

function syncStatusConfig(status) {
    const map = {
        completed: { pill: 'success', label: 'Completed' },
        running: { pill: 'warning', label: 'Running' },
        failed: { pill: 'danger', label: 'Failed' },
        pending: { pill: 'neutral', label: 'Pending' },
    };

    return map[status] ?? { pill: 'neutral', label: statusLabel(status) };
}
</script>

<template>
    <div data-testid="switches-index-layout" class="space-y-6">
        <header
            data-testid="switches-index-header"
            class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                >
                    Switches
                </h1>
                <p class="mt-1 text-sm text-[var(--color-text-secondary)]">Manage configured network switches.</p>
            </div>
            <Link
                :href="route('admin.switches.create')"
                data-testid="action-add-switch"
                class="inline-flex items-center justify-center rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-bg)] focus-visible:outline-none"
            >
                Add Switch
            </Link>
        </header>

        <div v-if="switches.length > 0" data-testid="switches-summary" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3.5">
                <p class="text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase">Total</p>
                <p class="mt-1 font-mono text-lg font-semibold text-[var(--color-text)]">{{ switchSummary.total }}</p>
            </div>
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3.5">
                <p class="text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase">Enabled</p>
                <p class="mt-1 font-mono text-lg font-semibold text-[var(--color-success)]">
                    {{ switchSummary.enabled }}
                </p>
            </div>
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3.5">
                <p class="text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase">Disabled</p>
                <p class="mt-1 font-mono text-lg font-semibold text-[var(--color-text-secondary)]">
                    {{ switchSummary.disabled }}
                </p>
            </div>
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-3.5">
                <p class="text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
                    Never Synced
                </p>
                <p class="mt-1 font-mono text-lg font-semibold text-[var(--color-warning)]">
                    {{ switchSummary.neverSynced }}
                </p>
            </div>
        </div>

        <EmptyState
            v-if="switches.length === 0"
            title="No switches configured"
            description="Add your first switch to get started."
        >
            <Link
                :href="route('admin.switches.create')"
                data-testid="empty-add-switch"
                class="mt-3 inline-flex items-center justify-center rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-bg)] focus-visible:outline-none"
            >
                Add Switch
            </Link>
        </EmptyState>

        <section
            v-else
            data-testid="switches-table-card"
            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]"
        >
            <SectionHeader
                title="Configured Switches"
                class="border-b border-[var(--color-border)] px-4 pt-4 pb-3 sm:px-5 sm:pt-5"
            />
            <div data-testid="switches-table" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Configured switches list with status, port health, and latest sync information.
                    </caption>
                    <thead>
                        <tr class="border-b-2 border-[var(--color-border)] bg-[var(--color-surface)]">
                            <th
                                v-for="col in columns"
                                :key="col.key"
                                :aria-sort="
                                    col.sortable && sortColumn === col.key
                                        ? sortDirection === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : 'none'
                                "
                                class="px-4 py-2.5 text-left text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                            >
                                <button
                                    v-if="col.sortable"
                                    type="button"
                                    :data-testid="`sort-${col.key}`"
                                    class="flex w-full items-center gap-1.5 text-left hover:text-[var(--color-text)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-1 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none"
                                    @click="toggleSort(col.key)"
                                >
                                    {{ col.label }}
                                    <span v-if="sortColumn === col.key" class="text-[var(--color-primary)]">
                                        {{ sortDirection === 'asc' ? '↑' : '↓' }}
                                    </span>
                                </button>
                                <div v-else class="flex items-center gap-1.5">
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
                            class="cursor-pointer border-b border-[var(--color-border)] align-middle transition-colors last:border-b-0 hover:border-l-2 hover:border-l-[var(--color-primary)] hover:bg-[var(--color-surface-hover)] focus-visible:border-l-2 focus-visible:border-l-[var(--color-primary)] focus-visible:bg-[var(--color-surface-hover)] focus-visible:outline-none"
                            @click="$inertia.visit(route('admin.switches.show', sw.id))"
                            @keydown.enter.prevent="$inertia.visit(route('admin.switches.show', sw.id))"
                            @keydown.space.prevent="$inertia.visit(route('admin.switches.show', sw.id))"
                        >
                            <td class="px-4 py-2.5 text-sm font-medium text-[var(--color-text)]">
                                {{ sw.name }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-secondary)]">
                                {{ sw.hostname }}
                            </td>
                            <td class="px-4 py-2.5">
                                <span
                                    class="inline-flex rounded-md bg-[var(--color-primary)]/10 px-2 py-0.5 text-xs font-semibold text-[var(--color-primary)]"
                                >
                                    {{ typeLabel(sw.type) }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5">
                                <StatusPill
                                    :status="sw.enabled ? 'success' : 'neutral'"
                                    :label="sw.enabled ? 'Enabled' : 'Disabled'"
                                />
                            </td>
                            <td :data-testid="'port-breakdown-' + sw.id" class="px-4 py-2.5 text-sm">
                                <template v-if="sw.port_count > 0">
                                    <span class="inline-flex items-center gap-2">
                                        <span class="text-[var(--color-success)]"
                                            >{{ sw.ports_up }}<span class="ml-0.5">↑</span></span
                                        >
                                        <span v-if="sw.ports_down > 0" class="text-[var(--color-text-muted)]"
                                            >{{ sw.ports_down }}<span class="ml-0.5">↓</span></span
                                        >
                                        <span v-if="sw.ports_error > 0" class="text-[var(--color-danger)]"
                                            >{{ sw.ports_error }}<span class="ml-0.5">⚠</span></span
                                        >
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="text-[var(--color-text-muted)]">—</span>
                                </template>
                            </td>
                            <td :data-testid="'sync-status-' + sw.id" class="px-4 py-2.5 text-sm">
                                <template v-if="sw.latest_sync_status">
                                    <div class="flex flex-col gap-0.5">
                                        <StatusPill
                                            :status="syncStatusConfig(sw.latest_sync_status).pill"
                                            :label="syncStatusConfig(sw.latest_sync_status).label"
                                        />
                                        <span v-if="sw.last_synced_at" class="text-xs text-[var(--color-text-muted)]">
                                            {{ formatRelative(sw.last_synced_at) }}
                                        </span>
                                    </div>
                                </template>
                                <template v-else>
                                    <span class="text-[var(--color-text-muted)]">Never synced</span>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
