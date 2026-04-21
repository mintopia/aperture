<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    block: { type: Object, required: true },
});

const emit = defineEmits(['save', 'delete', 'close']);

const textTypes = ['custom_markdown'];

const title = ref(props.block.title);
const content = ref(props.block.content ?? '');
const colSpan = ref(props.block.col_span);
const rowSpan = ref(props.block.row_span);
const isActive = ref(props.block.is_active);

watch(
    () => props.block,
    (b) => {
        title.value = b.title;
        content.value = b.content ?? '';
        colSpan.value = b.col_span;
        rowSpan.value = b.row_span;
        isActive.value = b.is_active;
    },
);

function save() {
    emit('save', {
        id: props.block.id,
        title: title.value,
        content: content.value,
        col_span: colSpan.value,
        row_span: rowSpan.value,
        is_active: isActive.value,
    });
}
</script>

<template>
    <div
        data-testid="editor-side-panel"
        class="fixed inset-y-0 right-0 z-50 w-80 overflow-y-auto border-l border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
    >
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-[var(--color-text)]">Edit Block</h3>
            <button
                data-testid="panel-close"
                class="text-[var(--color-text-muted)] hover:text-[var(--color-text)]"
                @click="emit('close')"
            >
                &#x2715;
            </button>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Block Type
            </label>
            <span class="text-[13px] text-[var(--color-text-secondary)]">{{ block.type }}</span>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Title
            </label>
            <input
                v-model="title"
                data-testid="panel-title-input"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div v-if="textTypes.includes(block.type)" class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Content
            </label>
            <textarea
                v-model="content"
                data-testid="panel-content-input"
                rows="4"
                class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Column Span
            </label>
            <div data-testid="panel-col-span" class="flex gap-2">
                <button
                    v-for="n in 3"
                    :key="n"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        colSpan === n
                            ? 'border-[var(--color-accent)] bg-[var(--color-accent)]/10 text-[var(--color-accent)]'
                            : 'border-[var(--color-border)] text-[var(--color-text-muted)]'
                    "
                    @click="colSpan = n"
                >
                    {{ n }}
                </button>
            </div>
        </div>

        <div class="mb-4">
            <label class="mb-1 block text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Row Span
            </label>
            <input
                v-model.number="rowSpan"
                data-testid="panel-row-span"
                type="number"
                min="1"
                class="w-20 rounded-md border border-[var(--color-border)] bg-[var(--color-surface-alt)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </div>

        <div class="mb-4 flex items-center justify-between">
            <label class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                Active
            </label>
            <button
                data-testid="panel-active-toggle"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200"
                :class="isActive ? 'bg-[var(--color-success)]' : 'bg-[var(--color-surface-alt)]'"
                @click="isActive = !isActive"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200"
                    :class="isActive ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>

        <div class="flex gap-2">
            <button
                data-testid="panel-save"
                class="flex-1 rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm font-medium text-white"
                @click="save"
            >
                Save Block
            </button>
            <button
                data-testid="panel-delete"
                class="rounded-md border border-[var(--color-danger)]/30 px-3 py-2 text-sm text-[var(--color-danger)]"
                @click="emit('delete', block.id)"
            >
                Delete
            </button>
        </div>
    </div>
</template>
