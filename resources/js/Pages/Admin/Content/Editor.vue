<script setup>
import { ref, onBeforeUnmount } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EditorSidePanel from '@/Components/Admin/Content/EditorSidePanel.vue';
import { useGridEditor } from '@/composables/useGridEditor.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    blocks: { type: Array, default: () => [] },
    singletonTypes: { type: Array, default: () => [] },
    existingTypes: { type: Array, default: () => [] },
});

const localBlocks = ref(JSON.parse(JSON.stringify(props.blocks)));
const selectedBlock = ref(null);
const hasChanges = ref(false);
const saving = ref(false);

const { totalRows, moveBlock, computeDisplacement } = useGridEditor(localBlocks);

// Drag state
const dragging = ref(null);
const dragOver = ref(null);
const positionSnapshot = ref(null);
const previewDisplacement = ref({});

// Resize state
const resizing = ref(null);
const resizeStartPos = ref(null);
const justResized = ref(false);

// Template ref for grid element
const gridRef = ref(null);

function getBlock(id) {
    return localBlocks.value.find((b) => b.id === id);
}

function onDragStart(block, event) {
    dragging.value = block.id;
    event.dataTransfer.effectAllowed = 'move';
    positionSnapshot.value = localBlocks.value.map((b) => ({
        id: b.id,
        grid_col: b.grid_col,
        grid_row: b.grid_row,
    }));
}

function onDragOver(col, row, event) {
    event.preventDefault();
    if (!dragging.value) return;
    const block = getBlock(dragging.value);
    dragOver.value = `${col},${row}`;
    event.dataTransfer.dropEffect = 'move';
    previewDisplacement.value = computeDisplacement(dragging.value, col, row, block.col_span, block.row_span);
}

function onDrop(col, row) {
    if (!dragging.value) return;
    const block = getBlock(dragging.value);
    const displacement = computeDisplacement(dragging.value, col, row, block.col_span, block.row_span);
    moveBlock(dragging.value, col, row);
    for (const [id, newRow] of Object.entries(displacement)) {
        const displaced = getBlock(Number(id));
        if (displaced) displaced.grid_row = newRow;
    }
    hasChanges.value = true;
    dragging.value = null;
    dragOver.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
}

function cancelDrag() {
    if (positionSnapshot.value) {
        for (const snap of positionSnapshot.value) {
            const block = getBlock(snap.id);
            if (block) {
                block.grid_col = snap.grid_col;
                block.grid_row = snap.grid_row;
                if ('col_span' in snap) block.col_span = snap.col_span;
                if ('row_span' in snap) block.row_span = snap.row_span;
            }
        }
    }
    dragging.value = null;
    dragOver.value = null;
    resizing.value = null;
    resizeStartPos.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
    document.removeEventListener('mousemove', onResizeMove);
    document.removeEventListener('mouseup', onResizeEnd);
}

function onDragEnd() {
    if (dragging.value) {
        cancelDrag();
    }
}

function onResizeStart(block, event) {
    event.preventDefault();
    resizing.value = block.id;
    resizeStartPos.value = {
        x: event.clientX,
        y: event.clientY,
        colSpan: block.col_span,
        rowSpan: block.row_span,
    };
    positionSnapshot.value = localBlocks.value.map((b) => ({
        id: b.id,
        grid_col: b.grid_col,
        grid_row: b.grid_row,
        col_span: b.col_span,
        row_span: b.row_span,
    }));
    document.addEventListener('mousemove', onResizeMove);
    document.addEventListener('mouseup', onResizeEnd);
}

function onResizeMove(event) {
    if (!resizing.value) return;
    const block = getBlock(resizing.value);
    if (!block) return;

    const gridEl = gridRef.value;
    if (!gridEl) return;
    const cellWidth = gridEl.clientWidth / 3;
    const cellHeight = 80;

    const dx = event.clientX - resizeStartPos.value.x;
    const dy = event.clientY - resizeStartPos.value.y;

    const newColSpan = Math.max(
        1,
        Math.min(3 - block.grid_col + 1, resizeStartPos.value.colSpan + Math.round(dx / cellWidth)),
    );
    const newRowSpan = Math.max(1, resizeStartPos.value.rowSpan + Math.round(dy / cellHeight));

    if (newColSpan !== block.col_span || newRowSpan !== block.row_span) {
        block.col_span = newColSpan;
        block.row_span = newRowSpan;
        previewDisplacement.value = computeDisplacement(
            block.id,
            block.grid_col,
            block.grid_row,
            newColSpan,
            newRowSpan,
        );
    }
}

function onResizeEnd() {
    if (!resizing.value) return;
    const block = getBlock(resizing.value);
    if (block) {
        for (const [id, newRow] of Object.entries(previewDisplacement.value)) {
            const displaced = getBlock(Number(id));
            if (displaced) displaced.grid_row = newRow;
        }
        hasChanges.value = true;
    }
    resizing.value = null;
    resizeStartPos.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
    justResized.value = true;
    requestAnimationFrame(() => {
        justResized.value = false;
    });
    document.removeEventListener('mousemove', onResizeMove);
    document.removeEventListener('mouseup', onResizeEnd);
}

function selectBlock(block) {
    if (justResized.value) return;
    selectedBlock.value = block;
}

function closePanel() {
    selectedBlock.value = null;
}

async function saveBlock(data) {
    await fetch(`/admin/content/${data.id}`, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify(data),
    });
    const block = getBlock(data.id);
    if (block) {
        Object.assign(block, data);
    }
    selectedBlock.value = null;
}

async function deleteBlock(id) {
    if (!confirm('Delete this block?')) {
        return;
    }
    await fetch(`/admin/content/${id}`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
    });
    localBlocks.value = localBlocks.value.filter((b) => b.id !== id);
    selectedBlock.value = null;
    hasChanges.value = true;
}

async function saveLayout() {
    saving.value = true;
    await fetch(route('admin.content.layout.update'), {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({
            blocks: localBlocks.value.map((b) => ({
                id: b.id,
                grid_col: b.grid_col,
                grid_row: b.grid_row,
                col_span: b.col_span,
                row_span: b.row_span,
            })),
        }),
    });
    saving.value = false;
    hasChanges.value = false;
}

function blockStyle(block) {
    const row = previewDisplacement.value[block.id] ?? block.grid_row;
    return {
        gridColumn: `${block.grid_col} / span ${block.col_span}`,
        gridRow: `${row} / span ${block.row_span}`,
        transition: dragging.value || resizing.value ? 'grid-row-start 200ms ease' : 'none',
    };
}

const displayRows = () => Math.max(totalRows.value + 1, 3);

const blockTypeColors = {
    custom_markdown: 'rgba(34,197,94,0.3)',
    connection_strip: 'rgba(99,102,241,0.4)',
    bandwidth: 'rgba(59,130,246,0.3)',
    dns_filter: 'rgba(236,72,153,0.3)',
};

onBeforeUnmount(() => {
    document.removeEventListener('mousemove', onResizeMove);
    document.removeEventListener('mouseup', onResizeEnd);
});
</script>

<template>
    <div>
        <div class="mb-4 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Grid Editor
            </h1>
            <div class="flex gap-2">
                <button
                    data-testid="action-add-block"
                    class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium text-[var(--color-text)]"
                >
                    + Add Block
                </button>
                <button
                    data-testid="action-save-layout"
                    class="rounded-md px-3 py-1.5 text-sm font-medium text-[var(--color-accent-text)]"
                    :class="hasChanges ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-text-muted)]'"
                    :disabled="saving || !hasChanges"
                    @click="saveLayout"
                >
                    {{ saving ? 'Saving...' : 'Save Layout' }}
                    <span v-if="hasChanges && !saving" class="ml-1 inline-block h-2 w-2 rounded-full bg-white" />
                </button>
            </div>
        </div>

        <div
            ref="gridRef"
            data-testid="editor-grid"
            class="grid gap-3"
            tabindex="0"
            :style="{
                gridTemplateColumns: 'repeat(3, 1fr)',
                gridTemplateRows: `repeat(${displayRows()}, minmax(80px, auto))`,
            }"
            @keydown.escape="cancelDrag"
        >
            <!-- Rendered blocks -->
            <div
                v-for="block in localBlocks"
                :key="block.id"
                :data-testid="'editor-block-' + block.id"
                class="relative cursor-pointer rounded-md border-2 p-3"
                :style="{
                    ...blockStyle(block),
                    borderColor: blockTypeColors[block.type] ?? 'rgba(255,255,255,0.2)',
                    background: (blockTypeColors[block.type] ?? 'rgba(255,255,255,0.05)').replace(/[\d.]+\)$/, '0.08)'),
                }"
                draggable="true"
                @dragstart="onDragStart(block, $event)"
                @dragend="onDragEnd"
                @dragover="onDragOver(block.grid_col, block.grid_row, $event)"
                @drop="onDrop(block.grid_col, block.grid_row)"
                @click="selectBlock(block)"
            >
                <!-- Drag handle bar -->
                <div
                    :data-testid="'drag-handle-' + block.id"
                    class="absolute inset-x-0 top-0 flex h-6 cursor-grab items-center justify-center rounded-t-md opacity-30 transition-opacity hover:opacity-60"
                >
                    <svg class="h-3 w-5 text-[var(--color-text-muted)]" viewBox="0 0 20 12" fill="currentColor">
                        <rect y="0" width="20" height="2" rx="1" />
                        <rect y="5" width="20" height="2" rx="1" />
                        <rect y="10" width="20" height="2" rx="1" />
                    </svg>
                </div>
                <span
                    class="text-[10px] font-bold tracking-wider uppercase"
                    :style="{ color: blockTypeColors[block.type] ?? '#888' }"
                >
                    {{ block.type.replace('_', ' ') }}
                </span>
                <div class="mt-1 text-xs text-[var(--color-text-secondary)]">{{ block.title }}</div>
                <div class="absolute top-2 right-2 text-[10px] text-[var(--color-text-muted)]">
                    {{ block.col_span }}&times;{{ block.row_span }}
                </div>
                <!-- Resize handle — large hit area, small visual grip -->
                <div
                    :data-testid="'resize-handle-' + block.id"
                    class="absolute right-0 bottom-0 h-8 w-8 cursor-se-resize"
                    @mousedown.stop="onResizeStart(block, $event)"
                >
                    <svg
                        class="absolute right-1 bottom-1 h-3 w-3 text-[var(--color-text-muted)] opacity-30 transition-opacity hover:opacity-60"
                        viewBox="0 0 16 16"
                        fill="currentColor"
                    >
                        <path d="M14 14H10V12H12V10H14V14ZM14 8H12V6H14V8Z" />
                    </svg>
                </div>
            </div>

            <!-- Empty drop zones -->
            <template v-for="row in displayRows()" :key="'row-' + row">
                <template v-for="col in 3" :key="'cell-' + col + '-' + row">
                    <div
                        v-if="
                            !localBlocks.some(
                                (b) =>
                                    col >= b.grid_col &&
                                    col < b.grid_col + b.col_span &&
                                    row >= b.grid_row &&
                                    row < b.grid_row + b.row_span,
                            )
                        "
                        class="flex items-center justify-center rounded-md border-2 border-dashed border-[var(--color-border)]/30"
                        :style="{ gridColumn: col, gridRow: row }"
                        @dragover="onDragOver(col, row, $event)"
                        @drop="onDrop(col, row)"
                    >
                        <span class="text-[11px] text-[var(--color-text-muted)]/40">Drop here</span>
                    </div>
                </template>
            </template>
        </div>

        <!-- Side Panel -->
        <EditorSidePanel
            v-if="selectedBlock"
            :block="selectedBlock"
            @save="saveBlock"
            @delete="deleteBlock"
            @close="closePanel"
        />
    </div>
</template>
