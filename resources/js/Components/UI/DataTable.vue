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
    <div data-testid="data-table" class="overflow-x-auto rounded-lg border border-[var(--color-border)]">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b-2 border-[var(--color-border)] bg-[var(--color-surface)]">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        :class="[col.class, col.srOnly ? 'sr-only' : '']"
                        :aria-sort="getAriaSortValue(col)"
                        class="px-4 py-2.5 text-left text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >
                        <button
                            v-if="col.sortable"
                            :data-testid="`sort-${col.key}`"
                            type="button"
                            class="cursor-pointer hover:text-[var(--color-text)] focus-visible:outline-none"
                            @click="toggleSort(col.key)"
                        >
                            {{ col.label }}
                            <span v-if="sortColumn === col.key" class="text-[var(--color-primary)]">
                                {{ sortDirection === 'asc' ? '↑' : '↓' }}
                            </span>
                        </button>
                        <span v-else>{{ col.label }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="rows.length === 0" data-testid="data-table-empty">
                    <td :colspan="columns.length" class="px-4 py-12 text-center text-[var(--color-text-muted)]">
                        {{ emptyMessage }}
                    </td>
                </tr>
                <tr
                    v-for="(row, i) in rows"
                    :key="row.id ?? i"
                    data-testid="data-table-row"
                    :tabindex="props.clickable && props.rowHref ? 0 : undefined"
                    :role="props.clickable && props.rowHref ? 'link' : undefined"
                    :aria-label="getRowAriaLabel(row, i)"
                    :class="[
                        'border-b border-[var(--color-border)] transition-colors last:border-b-0',
                        props.clickable
                            ? 'cursor-pointer hover:border-l-2 hover:border-l-[var(--color-primary)] hover:bg-[var(--color-surface-hover)] focus-visible:border-l-2 focus-visible:border-l-[var(--color-primary)] focus-visible:bg-[var(--color-surface-hover)] focus-visible:outline-none'
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
