<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import FilterBar from '@/Components/UI/FilterBar.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    logs: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
    actionOptions: { type: Array, default: () => [] },
    processOptions: { type: Array, default: () => [] },
    subjectTypeOptions: { type: Array, default: () => [] },
});

const searchQuery = ref('');
const filterValues = ref({
    action: props.filters?.action ?? '',
    process: props.filters?.process ?? '',
    subject_type: props.filters?.subject_type ?? '',
});

const filterDefinitions = computed(() => [
    {
        key: 'action',
        label: 'Action',
        options: props.actionOptions.map((a) => ({ value: a, label: a })),
    },
    {
        key: 'process',
        label: 'Process',
        options: props.processOptions.map((p) => ({ value: p, label: p })),
    },
    {
        key: 'subject_type',
        label: 'Subject Type',
        options: props.subjectTypeOptions.map((s) => ({ value: s.value, label: s.label })),
    },
]);

const columns = [
    { key: 'created_at', label: 'Timestamp' },
    { key: 'action', label: 'Action' },
    { key: 'subject', label: 'Subject' },
    { key: 'related', label: 'Related' },
    { key: 'actor', label: 'Actor' },
    { key: 'process', label: 'Process' },
];

const allLogs = computed(() => props.logs.data ?? []);

const filteredLogs = computed(() => {
    const q = searchQuery.value.toLowerCase().trim();
    if (!q) {
        return allLogs.value;
    }

    return allLogs.value.filter(
        (log) => (log.action ?? '').toLowerCase().includes(q) || (log.process ?? '').toLowerCase().includes(q),
    );
});

function search() {
    router.get(
        route('admin.audit-log.index'),
        {
            action: filterValues.value.action,
            process: filterValues.value.process,
            subject_type: filterValues.value.subject_type,
            perPage: props.filters?.perPage ?? 20,
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

function formatTimestamp(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return d.toLocaleString('en-GB', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}
</script>

<template>
    <div data-testid="audit-log-index-layout">
        <!-- Page Header -->
        <header class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Audit Log
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                    System-wide audit trail of all entity changes and actions.
                </p>
            </div>
        </header>

        <SectionHeader title="Log Entries" class="mt-6" />

        <FilterBar
            :search="searchQuery"
            search-placeholder="Filter by action or process…"
            :filters="filterDefinitions"
            :filter-values="filterValues"
            :total-count="logs.total ?? 0"
            :filtered-count="filteredLogs.length"
            data-testid="audit-log-filter-bar"
            @update:search="onSearchUpdate"
            @update:filter-values="onFilterUpdate"
        />

        <section data-testid="audit-log-table-section">
            <DataTable :columns="columns" :rows="filteredLogs" empty-message="No audit log entries found.">
                <template #row="{ row }">
                    <td
                        data-testid="audit-log-timestamp"
                        class="text-[13px] whitespace-nowrap text-[var(--color-text-secondary)]"
                    >
                        {{ formatTimestamp(row.created_at) }}
                    </td>
                    <td data-testid="audit-log-action" class="font-mono text-[13px] text-[var(--color-primary)]">
                        {{ row.action }}
                    </td>
                    <td data-testid="audit-log-subject" class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.subject_type">
                            <span class="font-semibold text-[var(--color-text)]">{{ row.subject_type }}</span>
                            <span class="font-mono text-[var(--color-text-muted)]"> #{{ row.subject_id }}</span>
                        </span>
                        <span v-else class="text-[var(--color-text-muted)]">—</span>
                    </td>
                    <td data-testid="audit-log-related" class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.related_type">
                            <span class="font-semibold text-[var(--color-text)]">{{ row.related_type }}</span>
                            <span class="font-mono text-[var(--color-text-muted)]"> #{{ row.related_id }}</span>
                        </span>
                        <span v-else class="text-[var(--color-text-muted)]">—</span>
                    </td>
                    <td data-testid="audit-log-actor" class="text-[13px] text-[var(--color-text-secondary)]">
                        <span v-if="row.actor_type">
                            <span class="font-semibold text-[var(--color-text)]">{{ row.actor_type }}</span>
                            <span class="font-mono text-[var(--color-text-muted)]"> #{{ row.actor_id }}</span>
                        </span>
                        <span v-else class="text-[var(--color-text-muted)]">System</span>
                    </td>
                    <td
                        data-testid="audit-log-process"
                        class="font-mono text-[13px] text-[var(--color-text-secondary)]"
                    >
                        {{ row.process ?? '—' }}
                    </td>
                </template>
            </DataTable>
        </section>

        <Pagination :paginator="logs" class="mt-4" />
    </div>
</template>
