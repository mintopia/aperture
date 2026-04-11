<script setup>
import { router } from '@inertiajs/vue3';

defineProps({
    columns: {
        type: Array,
        required: true,
        /* Array<{ key: string, label: string, class?: string, srOnly?: boolean }> */
    },
    rows: { type: Array, required: true },
    clickable: { type: Boolean, default: false },
    rowHref: { type: Function, default: null },
    emptyMessage: { type: String, default: 'No records found.' },
});
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
                        class="px-4 py-2.5 text-left text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >
                        {{ col.label }}
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
                    :tabindex="clickable ? 0 : undefined"
                    :role="clickable ? 'link' : undefined"
                    :class="[
                        'border-b border-[var(--color-border)] transition-colors last:border-b-0',
                        clickable
                            ? 'cursor-pointer hover:border-l-2 hover:border-l-[var(--color-primary)] hover:bg-[var(--color-surface-hover)]'
                            : '',
                    ]"
                    @click="clickable && rowHref ? router.visit(rowHref(row)) : null"
                    @keydown.enter="clickable && rowHref ? router.visit(rowHref(row)) : null"
                >
                    <slot name="row" :row="row" :index="i" />
                </tr>
            </tbody>
        </table>
    </div>
</template>
