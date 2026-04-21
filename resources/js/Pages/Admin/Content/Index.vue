<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    singletonTypes: { type: Array, default: () => [] },
    existingTypes: { type: Array, default: () => [] },
});

const columns = [
    { key: 'title', label: 'Title' },
    { key: 'type', label: 'Type' },
    { key: 'position', label: 'Position' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '' },
];

const showAddDialog = ref(false);

const availableTypes = [
    { value: 'event_info', label: 'Event Info' },
    { value: 'custom_markdown', label: 'Custom Markdown' },
    { value: 'connection_strip', label: 'Connection Strip' },
    { value: 'bandwidth', label: 'Bandwidth' },
    { value: 'network_stats', label: 'Network Stats' },
    { value: 'dns_filter', label: 'DNS Filter' },
    { value: 'connection_status', label: 'Connection Status' },
];

const addableTypes = availableTypes.filter((t) => {
    if (props.singletonTypes.includes(t.value) && props.existingTypes.includes(t.value)) {
        return false;
    }
    return true;
});

async function deleteBlock(id) {
    if (!confirm('Are you sure you want to delete this block?')) return;
    await fetch(`/admin/content/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
    });
    window.location.reload();
}

async function toggleActive(block) {
    await fetch(`/admin/content/${block.id}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ is_active: !block.is_active }),
    });
    window.location.reload();
}

async function addBlock(type) {
    const label = availableTypes.find((t) => t.value === type)?.label ?? type;
    await fetch('/admin/content', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ type, title: label }),
    });
    showAddDialog.value = false;
    window.location.reload();
}
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
            <div class="flex gap-2">
                <Link
                    :href="route('admin.content.editor')"
                    data-testid="link-grid-editor"
                    class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium text-[var(--color-text)] transition-colors hover:border-[var(--color-border-hover)]"
                >
                    Grid Editor
                </Link>
                <button
                    data-testid="action-add-block"
                    class="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm font-medium text-white"
                    @click="showAddDialog = !showAddDialog"
                >
                    + Add Block
                </button>
            </div>
        </div>

        <!-- Add block dropdown -->
        <div
            v-if="showAddDialog"
            class="mb-4 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
        >
            <p class="mb-2 text-sm font-medium text-[var(--color-text)]">Select block type:</p>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="t in addableTypes"
                    :key="t.value"
                    class="rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm text-[var(--color-text)] transition-colors hover:border-[var(--color-border-hover)]"
                    @click="addBlock(t.value)"
                >
                    {{ t.label }}
                </button>
            </div>
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
                        Col {{ row.grid_col }}, Row {{ row.grid_row }} ({{ row.col_span }}&times;{{ row.row_span }})
                    </td>
                    <td class="py-2.5">
                        <button :data-testid="'toggle-active-' + row.id" @click="toggleActive(row)">
                            <StatusPill
                                :status="row.is_active ? 'success' : 'neutral'"
                                :label="row.is_active ? 'Active' : 'Inactive'"
                            />
                        </button>
                    </td>
                    <td class="py-2.5 text-right">
                        <button
                            :data-testid="'action-delete-' + row.id"
                            class="text-xs text-[var(--color-danger)] hover:underline"
                            @click="deleteBlock(row.id)"
                        >
                            Delete
                        </button>
                    </td>
                </template>
            </DataTable>
        </div>
    </div>
</template>
