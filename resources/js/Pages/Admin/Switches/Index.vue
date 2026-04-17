<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
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
    <div>
        <div class="mb-5 flex items-center justify-between">
            <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
                Switches
            </h1>
            <Link
                :href="route('admin.switches.create')"
                data-testid="action-add-switch"
                class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[var(--color-primary-hover)]"
            >
                Add Switch
            </Link>
        </div>

        <EmptyState
            v-if="switches.length === 0"
            title="No switches configured"
            description="Add your first switch to get started."
        >
            <Link
                :href="route('admin.switches.create')"
                data-testid="empty-add-switch"
                class="mt-3 inline-block rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[var(--color-primary-hover)]"
            >
                Add Switch
            </Link>
        </EmptyState>

        <div v-else data-testid="switches-table" class="overflow-x-auto rounded-lg border border-[var(--color-border)]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b-2 border-[var(--color-border)] bg-[var(--color-surface)]">
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-2.5 text-left text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                            :class="{ 'cursor-pointer hover:bg-[var(--color-surface-hover)]': col.sortable }"
                            @click="col.sortable ? toggleSort(col.key) : null"
                        >
                            <div class="flex items-center gap-1.5">
                                {{ col.label }}
                                <span v-if="col.sortable && sortColumn === col.key" class="text-[var(--color-primary)]">
                                    {{ sortDirection === 'asc' ? '↑' : '↓' }}
                                </span>
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
                        class="cursor-pointer border-b border-[var(--color-border)] transition-colors last:border-b-0 hover:border-l-2 hover:border-l-[var(--color-primary)] hover:bg-[var(--color-surface-hover)]"
                        @click="$inertia.visit(route('admin.switches.show', sw.id))"
                        @keydown.enter="$inertia.visit(route('admin.switches.show', sw.id))"
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
    </div>
</template>
