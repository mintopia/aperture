<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import { Link } from '@inertiajs/vue3';
import { formatRelative, formatDate } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

defineProps({
    pages: { type: Array, default: () => [] },
});

const columns = [
    { key: 'title', label: 'Title' },
    { key: 'slug', label: 'Slug' },
    { key: 'updated', label: 'Updated', class: 'text-right w-32' },
];
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
        <section v-else data-testid="pages-table">
            <DataTable
                :columns="columns"
                :rows="pages"
                clickable
                :row-href="(row) => route('admin.content.pages.edit', row.id)"
                :row-aria-label="(row) => `Open page ${row.title}`"
                empty-message="No pages found."
            >
                <template #row="{ row }">
                    <td
                        :data-testid="`page-row-${row.slug}`"
                        class="text-[13px] font-semibold text-[var(--color-text)]"
                    >
                        <span data-testid="page-title">{{ row.title }}</span>
                    </td>
                    <td class="w-56">
                        <span class="font-mono text-[12px] text-[var(--color-primary)]">/content/{{ row.slug }}</span>
                    </td>
                    <td class="w-32 text-right text-[13px] text-[var(--color-text-secondary)]">
                        <span :title="formatDate(row.updated_at)">{{ formatRelative(row.updated_at) }}</span>
                    </td>
                </template>
            </DataTable>
        </section>
    </div>
</template>
