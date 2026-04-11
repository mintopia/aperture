<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    leases: Array,
});

const columns = [
    { key: 'ip', label: 'IP Address' },
    { key: 'mac', label: 'MAC Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'expires', label: 'Expires' },
];
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            DHCP Leases
        </h1>

        <DataTable :columns="columns" :rows="leases ?? []" empty-message="No active leases">
            <template #row="{ row }">
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.ip }}
                </td>
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text-secondary)]">
                    {{ row.mac }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.hostname }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-muted)]">
                    {{ row.expires }}
                </td>
            </template>
        </DataTable>
    </div>
</template>
