<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    paginator: {
        type: Object,
        required: true,
        /* Laravel paginator JSON: { current_page, last_page, from, to, total, links[] } */
    },
});
</script>

<template>
    <div
        v-if="paginator.last_page > 1"
        data-testid="pagination"
        class="flex flex-wrap items-center justify-between gap-4 border-t border-[var(--color-border)] pt-3 text-sm"
    >
        <span data-testid="pagination-info" class="text-[11px] text-[var(--color-text-muted)]">
            Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
        </span>
        <div class="flex items-center gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    data-testid="pagination-link"
                    :class="
                        link.active
                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 font-semibold text-[var(--color-primary)]'
                            : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-[var(--color-primary)] hover:text-[var(--color-primary)]'
                    "
                    class="rounded-md border px-2.5 py-1 text-[11px] transition-colors"
                    preserve-state
                    v-html="link.label"
                />
                <span
                    v-else
                    class="rounded-md px-2.5 py-1 text-[11px] text-[var(--color-text-muted)] opacity-40"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>
</template>
