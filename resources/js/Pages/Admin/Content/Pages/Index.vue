<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

defineProps({
    pages: { type: Array, default: () => [] },
    breadcrumbs: { type: Array, default: () => [] },
});
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h1 class="text-xl font-semibold">Pages</h1>
            <Link
                :href="route('admin.content.pages.create')"
                class="btn"
                data-testid="action-create-page"
            >
                Create Page
            </Link>
        </div>
        <table class="w-full">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="page in pages"
                    :key="page.id"
                    data-testid="pages-table-row"
                >
                    <td>{{ page.title }}</td>
                    <td>{{ page.slug }}</td>
                    <td>
                        <Link
                            :href="route('admin.content.pages.edit', page.id)"
                            data-testid="action-edit-page"
                        >
                            Edit
                        </Link>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
