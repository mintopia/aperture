<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    blocks: { type: Array, default: () => [] },
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
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Content Blocks
            </h1>
        </div>

        <EmptyState
            v-if="!blocks?.length"
            title="No content blocks"
            description="Content blocks will appear on the portal dashboard."
        />

        <div v-else class="mt-6">
            <DataTable :columns="columns" :rows="blocks">
                <template #row="{ row }">
                    <td class="py-2.5 text-[13px] font-medium text-[var(--color-text)]">
                        {{ row.title }}
                    </td>
                    <td class="py-2.5 text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.type }}
                    </td>
                    <td class="py-2.5 text-[13px] text-[var(--color-text-muted)]">
                        {{ row.sort_order }}
                    </td>
                    <td class="py-2.5">
                        <StatusPill
                            :status="row.is_active ? 'success' : 'neutral'"
                            :label="row.is_active ? 'Active' : 'Inactive'"
                        />
                    </td>
                </template>
            </DataTable>
        </div>
    </div>
</template>
