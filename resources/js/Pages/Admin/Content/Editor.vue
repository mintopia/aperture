<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import EditorSidePanel from '@/Components/Admin/Content/EditorSidePanel.vue';
import { useGridEditor } from '@/composables/useGridEditor.js';
import { useApi } from '@/composables/useApi.js';

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
const showAddMenu = ref(false);
const addingBlock = ref(false);

const { totalRows, moveBlock, computeDisplacement, cellFromPointer } = useGridEditor(localBlocks);
const { post, put, delete: del } = useApi();

// Drag state
const dragging = ref(null);
const dragGhostPos = ref(null);
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
    event.preventDefault();
    dragging.value = block.id;
    positionSnapshot.value = localBlocks.value.map((b) => ({
        id: b.id,
        grid_col: b.grid_col,
        grid_row: b.grid_row,
    }));
    document.addEventListener('mousemove', onDragMove);
    document.addEventListener('mouseup', onDragEnd);
}

function onDragMove(event) {
    if (!dragging.value) return;
    const gridEl = gridRef.value;
    if (!gridEl) return;
    const block = getBlock(dragging.value);
    if (!block) return;

    const { col, row } = cellFromPointer(gridEl, event.clientX, event.clientY);
    const clampedCol = Math.max(1, Math.min(col, 4 - block.col_span));

    dragGhostPos.value = { col: clampedCol, row, colSpan: block.col_span, rowSpan: block.row_span };
    previewDisplacement.value = computeDisplacement(dragging.value, clampedCol, row, block.col_span, block.row_span);
}

function onDragEnd() {
    document.removeEventListener('mousemove', onDragMove);
    document.removeEventListener('mouseup', onDragEnd);

    if (!dragging.value || !dragGhostPos.value) {
        cancelDrag();
        return;
    }

    const block = getBlock(dragging.value);
    if (!block) {
        cancelDrag();
        return;
    }

    const { col, row } = dragGhostPos.value;
    const displacement = computeDisplacement(dragging.value, col, row, block.col_span, block.row_span);
    moveBlock(dragging.value, col, row);
    for (const [id, newRow] of Object.entries(displacement)) {
        const displaced = getBlock(Number(id));
        if (displaced) displaced.grid_row = newRow;
    }
    hasChanges.value = true;
    dragging.value = null;
    dragGhostPos.value = null;
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
    dragGhostPos.value = null;
    resizing.value = null;
    resizeStartPos.value = null;
    previewDisplacement.value = {};
    positionSnapshot.value = null;
    document.removeEventListener('mousemove', onDragMove);
    document.removeEventListener('mouseup', onDragEnd);
    document.removeEventListener('mousemove', onResizeMove);
    document.removeEventListener('mouseup', onResizeEnd);
}

function onResizeStart(block, event) {
    event.preventDefault();
    resizing.value = block.id;
    resizeStartPos.value = {
        x: event.clientX,
        y: event.clientY,
        colSpan: Number(block.col_span),
        rowSpan: Number(block.row_span),
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
    try {
        await put(`/admin/content/${data.id}`, data);
        const block = getBlock(data.id);
        if (block) {
            Object.assign(block, data);
        }
    } catch (_e) {
        // error is surfaced via useApi's error ref
    } finally {
        selectedBlock.value = null;
    }
}

async function deleteBlock(id) {
    if (!confirm('Delete this block?')) {
        return;
    }
    try {
        await del(`/admin/content/${id}`);
        localBlocks.value = localBlocks.value.filter((b) => b.id !== id);
        hasChanges.value = true;
    } catch (_e) {
        // error is surfaced via useApi's error ref
    } finally {
        selectedBlock.value = null;
    }
}

async function saveLayout() {
    saving.value = true;
    try {
        await put(route('admin.content.layout.update'), {
            blocks: localBlocks.value.map((b) => ({
                id: b.id,
                grid_col: b.grid_col,
                grid_row: b.grid_row,
                col_span: b.col_span,
                row_span: b.row_span,
            })),
        });
        hasChanges.value = false;
    } catch (_e) {
        // error is surfaced via useApi's error ref
    } finally {
        saving.value = false;
    }
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

const blockTypes = [
    { type: 'custom_markdown', label: 'Markdown', description: 'Rich text content' },
    { type: 'connection_strip', label: 'Connection Strip', description: 'Network status display' },
    { type: 'bandwidth', label: 'Bandwidth', description: 'Bandwidth usage chart' },
    { type: 'dns_filter', label: 'DNS Filter', description: 'DNS filtering toggle' },
    { type: 'map', label: 'Map', description: 'OpenStreetMap location pin' },
    { type: 'image', label: 'Image', description: 'Full-bleed image display' },
    { type: 'link_strip', label: 'Link Strip', description: 'Horizontal links bar' },
];

const availableBlockTypes = computed(() =>
    blockTypes.filter((bt) => {
        if (props.singletonTypes.includes(bt.type)) {
            return !localBlocks.value.some((b) => b.type === bt.type);
        }
        return true;
    }),
);

async function addBlock(type) {
    addingBlock.value = true;
    showAddMenu.value = false;
    try {
        const block = await post(route('admin.content.store'), {
            type,
            title: blockTypes.find((bt) => bt.type === type)?.label ?? type,
            content: '',
            is_active: true,
        });
        localBlocks.value.push(block);
        hasChanges.value = false;
        selectedBlock.value = block;
    } finally {
        addingBlock.value = false;
    }
}

const blockTypeColors = {
    custom_markdown: 'rgba(34,197,94,0.3)',
    connection_strip: 'rgba(99,102,241,0.4)',
    bandwidth: 'rgba(59,130,246,0.3)',
    dns_filter: 'rgba(236,72,153,0.3)',
    map: 'rgba(245,158,11,0.3)',
    image: 'rgba(168,85,247,0.3)',
    link_strip: 'rgba(14,165,233,0.3)',
};

function onClickOutside(event) {
    if (
        showAddMenu.value &&
        !event.target.closest('[data-testid="action-add-block"]')?.parentElement?.contains(event.target)
    ) {
        showAddMenu.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', onClickOutside, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousemove', onDragMove);
    document.removeEventListener('mouseup', onDragEnd);
    document.removeEventListener('mousemove', onResizeMove);
    document.removeEventListener('mouseup', onResizeEnd);
    document.removeEventListener('click', onClickOutside, true);
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
                <div class="relative">
                    <button
                        data-testid="action-add-block"
                        :disabled="addingBlock || availableBlockTypes.length === 0"
                        class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-1.5 text-sm font-medium text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:opacity-40"
                        @click="showAddMenu = !showAddMenu"
                    >
                        {{ addingBlock ? 'Adding...' : '+ Add Block' }}
                    </button>
                    <div
                        v-if="showAddMenu"
                        data-testid="add-block-menu"
                        class="absolute right-0 z-20 mt-1 w-56 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] py-1 shadow-lg"
                    >
                        <button
                            v-for="bt in availableBlockTypes"
                            :key="bt.type"
                            :data-testid="'add-block-' + bt.type"
                            class="flex w-full flex-col px-3 py-2 text-left transition-colors hover:bg-[var(--color-surface-hover)]"
                            @click="addBlock(bt.type)"
                        >
                            <span class="text-[13px] font-medium text-[var(--color-text)]">{{ bt.label }}</span>
                            <span class="text-[11px] text-[var(--color-text-muted)]">{{ bt.description }}</span>
                        </button>
                    </div>
                </div>
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
            <!-- Ghost outline during drag -->
            <div
                v-if="dragGhostPos"
                data-testid="drag-ghost"
                class="pointer-events-none rounded-md border-2 border-dashed border-[var(--color-accent)]"
                :style="{
                    gridColumn: `${dragGhostPos.col} / span ${dragGhostPos.colSpan}`,
                    gridRow: `${dragGhostPos.row} / span ${dragGhostPos.rowSpan}`,
                    background: 'oklch(var(--color-accent-l) var(--color-accent-c) var(--color-accent-h) / 0.1)',
                }"
            />

            <!-- Rendered blocks -->
            <div
                v-for="block in localBlocks"
                :key="block.id"
                :data-testid="'editor-block-' + block.id"
                class="relative cursor-pointer rounded-md border-2 p-3 transition-opacity duration-150"
                :style="{
                    ...blockStyle(block),
                    borderColor: blockTypeColors[block.type] ?? 'rgba(255,255,255,0.2)',
                    background: (blockTypeColors[block.type] ?? 'rgba(255,255,255,0.05)').replace(/[\d.]+\)$/, '0.08)'),
                    opacity: dragging === block.id ? 0.3 : 1,
                }"
                @click="selectBlock(block)"
            >
                <!-- Drag handle bar -->
                <div
                    :data-testid="'drag-handle-' + block.id"
                    class="absolute inset-x-0 top-0 flex h-6 cursor-grab items-center justify-center rounded-t-md opacity-30 transition-opacity hover:opacity-60"
                    @mousedown.stop="onDragStart(block, $event)"
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
                    />
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
