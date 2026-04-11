<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    blocks: Array,
});

const columns = [
    { key: 'title', label: 'Title' },
    { key: 'type', label: 'Type' },
    { key: 'sort', label: 'Sort Order' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Content Blocks
        </h1>

        <EmptyState
            v-if="!blocks?.length"
            title="No content blocks"
            description="Content blocks will appear on the portal dashboard."
        />

        <DataTable v-else :columns="columns" :rows="blocks">
            <template #row="{ row }">
                <td class="px-4 py-2.5 text-sm font-medium text-[var(--color-text)]">
                    {{ row.title }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-secondary)]">
                    {{ row.type }}
                </td>
                <td class="px-4 py-2.5 text-sm text-[var(--color-text-muted)]">
                    {{ row.sort_order }}
                </td>
                <td class="px-4 py-2.5">
                    <StatusPill
                        :status="row.is_active ? 'success' : 'neutral'"
                        :label="row.is_active ? 'Active' : 'Inactive'"
                    />
                </td>
            </template>
        </DataTable>
    </div>
</template>
