<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { formatRelative, formatDate } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

defineProps({
    pages: { type: Array, default: () => [] },
});
</script>

<template>
    <div data-testid="pages-index">
        <!-- Page Header -->
        <header class="mb-6 flex items-start justify-between gap-6">
            <div>
                <h1
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Pages
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Manage static content pages.</p>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    :href="route('admin.content.pages.create')"
                    data-testid="action-new-page"
                    class="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-bold text-[var(--color-bg)] transition-all hover:bg-[var(--color-primary-hover)]"
                >
                    + New Page
                </Link>
            </div>
        </header>

        <!-- Empty state -->
        <div
            v-if="pages.length === 0"
            data-testid="pages-empty"
            class="flex flex-col items-center justify-center px-6 py-12 text-center"
        >
            <p class="font-heading text-base text-[var(--color-text-secondary)]">No pages yet</p>
            <p class="mt-1 max-w-[260px] text-[11px] text-[var(--color-text-muted)]">
                Create your first static content page to get started.
            </p>
            <div class="mt-4">
                <Link
                    :href="route('admin.content.pages.create')"
                    class="inline-flex items-center gap-1.5 rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-bold text-[var(--color-bg)] transition-all hover:bg-[var(--color-primary-hover)]"
                >
                    + New Page
                </Link>
            </div>
        </div>

        <!-- Table -->
        <section v-else>
            <!-- Table header -->
            <div data-testid="pages-table" class="overflow-x-auto">
                <div class="flex border-b border-[var(--color-border-hover)] pb-2">
                    <div
                        class="flex-1 text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                    >
                        Title
                    </div>
                    <div
                        class="w-56 text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                    >
                        Slug
                    </div>
                    <div
                        class="w-32 text-right text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                    >
                        Updated
                    </div>
                </div>

                <!-- Rows -->
                <Link
                    v-for="page in pages"
                    :key="page.id"
                    :href="route('admin.content.pages.edit', page.id)"
                    :data-testid="`page-row-${page.slug}`"
                    class="flex items-center border-b border-[var(--color-border)]/40 py-2.5 transition-colors last:border-b-0 hover:bg-[var(--color-surface-hover)]"
                >
                    <div class="flex-1 text-[13px] font-semibold text-[var(--color-text)]">
                        <span data-testid="page-title">{{ page.title }}</span>
                    </div>
                    <div class="w-56">
                        <span class="font-mono text-[12px] text-[var(--color-primary)]">/content/{{ page.slug }}</span>
                    </div>
                    <div class="w-32 text-right text-[13px] text-[var(--color-text-secondary)]">
                        <span :title="formatDate(page.updated_at)">{{ formatRelative(page.updated_at) }}</span>
                    </div>
                </Link>
            </div>
        </section>
    </div>
</template>
