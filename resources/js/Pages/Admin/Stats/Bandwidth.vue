<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

defineProps({
    topTalkers: { type: Array, default: () => [] },
});

const columns = [
    { key: 'ip', label: 'IP Address' },
    { key: 'user', label: 'User' },
    { key: 'downloaded', label: 'Downloaded' },
    { key: 'uploaded', label: 'Uploaded' },
];
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Bandwidth &amp; Top Talkers
        </h1>

        <DataTable :columns="columns" :rows="topTalkers ?? []" empty-message="No bandwidth data available">
            <template #row="{ row }">
                <td class="px-4 py-2.5 font-mono text-sm text-[var(--color-text)]">
                    {{ row.ip }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.nickname ?? '—' }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.received) }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ formatBytes(row.sent) }}
                </td>
            </template>
        </DataTable>
    </div>
</template>
