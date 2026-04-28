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
    <nav
        v-if="paginator.last_page > 1"
        aria-label="Pagination"
        data-testid="pagination"
        class="flex flex-wrap items-center justify-between gap-4 pt-3"
    >
        <span data-testid="pagination-info" class="font-mono text-[11px] text-[var(--color-text-muted)]">
            Showing {{ paginator.from }}–{{ paginator.to }} of {{ paginator.total }}
        </span>
        <div class="flex items-center gap-1">
            <template v-for="link in paginator.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    data-testid="pagination-link"
                    :aria-label="
                        link.label.includes('Previous')
                            ? 'Previous page'
                            : link.label.includes('Next')
                              ? 'Next page'
                              : `Page ${link.label}`
                    "
                    :class="
                        link.active
                            ? 'bg-[var(--color-primary)]/[0.14] font-semibold text-[var(--color-primary)]'
                            : 'text-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)]'
                    "
                    class="rounded-md border border-[var(--color-border-hover)] px-4 py-2 text-[13px] font-semibold transition-colors"
                    preserve-state
                    v-html="link.label"
                />
                <span
                    v-else
                    class="rounded-md border border-transparent px-4 py-2 text-[13px] font-semibold text-[var(--color-text-muted)] opacity-40"
                    v-html="link.label"
                />
            </template>
        </div>
    </nav>
</template>
