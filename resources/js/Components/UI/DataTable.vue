<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    columns: {
        type: Array,
        required: true,
        /* Array<{ key: string, label: string, class?: string, srOnly?: boolean, sortable?: boolean }> */
    },
    rows: { type: Array, required: true },
    clickable: { type: Boolean, default: false },
    rowHref: { type: Function, default: null },
    rowAriaLabel: { type: Function, default: null },
    rowClass: { type: Function, default: null },
    emptyMessage: { type: String, default: 'No records found.' },
    sortColumn: { type: String, default: null },
    sortDirection: {
        type: String,
        default: 'asc',
        validator: (v) => ['asc', 'desc'].includes(v),
    },
});

const emit = defineEmits(['update:sort-column', 'update:sort-direction']);

function toggleSort(columnKey) {
    if (props.sortColumn === columnKey) {
        emit('update:sort-direction', props.sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
        emit('update:sort-column', columnKey);
        emit('update:sort-direction', 'asc');
    }
}

function getAriaSortValue(col) {
    if (!col.sortable) return undefined;
    if (props.sortColumn !== col.key) return 'none';
    return props.sortDirection === 'asc' ? 'ascending' : 'descending';
}

function navigateRow(row) {
    if (!props.clickable || !props.rowHref) return;
    router.visit(props.rowHref(row));
}

function getRowAriaLabel(row, index) {
    if (!props.clickable || !props.rowHref) return undefined;
    if (props.rowAriaLabel) return props.rowAriaLabel(row, index);
    return `Open row ${index + 1}`;
}
</script>

<template>
    <div data-testid="data-table" class="overflow-x-auto">
        <table class="w-full border-collapse text-[13px]">
            <thead>
                <tr>
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :class="[col.class, col.srOnly ? 'sr-only' : '']"
                        :aria-sort="getAriaSortValue(col)"
                        class="border-b border-[var(--color-border-hover)] py-2 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                    >
                        <button
                            v-if="col.sortable"
                            type="button"
                            :data-testid="'sort-' + col.key"
                            class="flex w-full cursor-pointer items-center gap-1 rounded text-left hover:text-[var(--color-text)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-1 focus-visible:outline-none"
                            @click="toggleSort(col.key)"
                        >
                            {{ col.label }}
                            <span v-if="sortColumn === col.key" class="ml-0.5 text-[var(--color-primary)]">
                                {{ sortDirection === 'asc' ? '\u2191' : '\u2193' }}
                            </span>
                        </button>
                        <template v-else>{{ col.label }}</template>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0" data-testid="data-table-empty">
                    <td :colspan="columns.length" class="py-12 text-center text-[var(--color-text-muted)]">
                        {{ emptyMessage }}
                    </td>
                </tr>
                <tr
                    v-for="(row, i) in rows"
                    :key="row.id ?? i"
                    data-testid="data-table-row"
                    :tabindex="props.clickable && props.rowHref ? 0 : undefined"
                    :role="props.clickable && props.rowHref ? 'row' : undefined"
                    :aria-label="getRowAriaLabel(row, i)"
                    :class="[
                        'transition-colors',
                        props.clickable
                            ? 'cursor-pointer hover:bg-[var(--color-surface-hover)] focus-visible:bg-[var(--color-surface-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-inset'
                            : '',
                        props.rowClass ? props.rowClass(row) : '',
                    ]"
                    @click="navigateRow(row)"
                    @keydown.enter.prevent="navigateRow(row)"
                    @keydown.space.prevent="navigateRow(row)"
                >
                    <slot name="row" :row="row" :index="i" />
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
:deep(tbody td) {
    padding-top: 10px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-border);
    vertical-align: top;
}
:deep(tbody tr:last-child td) {
    border-bottom: none;
}
:deep(tbody td:not(:first-child)),
:deep(thead th:not(:first-child)) {
    padding-left: 24px;
}
</style>
