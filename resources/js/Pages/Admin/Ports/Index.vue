<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    ports: Array,
});

const columns = [
    { key: 'interface', label: 'Interface' },
    { key: 'status', label: 'Status' },
    { key: 'speed', label: 'Speed' },
];

function statusType(status) {
    if (status === 'up') return 'success';
    if (status === 'down') return 'danger';
    return 'warning';
}
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Switch Ports
        </h1>

        <DataTable
            :columns="columns"
            :rows="ports ?? []"
            clickable
            :row-href="(row) => route('admin.ports.show', row.interface)"
        >
            <template #row="{ row }">
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.interface }}
                </td>
                <td class="px-4 py-2.5">
                    <StatusPill :status="statusType(row.status)" :label="row.status" />
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.speed }}
                </td>
            </template>
        </DataTable>
    </div>
</template>
