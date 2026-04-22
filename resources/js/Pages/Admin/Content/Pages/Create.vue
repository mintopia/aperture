<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useForm } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

defineProps({
    breadcrumbs: { type: Array, default: () => [] },
});

const form = useForm({
    title: '',
    slug: '',
    content: '',
});

const submit = () => {
    form.post(route('admin.content.pages.store'));
};
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-semibold">Create Page</h1>
        <form @submit.prevent="submit">
            <div class="mb-4">
                <label for="title">Title</label>
                <input
                    id="title"
                    v-model="form.title"
                    type="text"
                    data-testid="input-title"
                />
            </div>
            <div class="mb-4">
                <label for="slug">Slug</label>
                <input
                    id="slug"
                    v-model="form.slug"
                    type="text"
                    data-testid="input-slug"
                />
            </div>
            <div class="mb-4">
                <label for="content">Content</label>
                <textarea
                    id="content"
                    v-model="form.content"
                    data-testid="input-content"
                />
            </div>
            <button
                type="submit"
                data-testid="action-save"
            >
                Save
            </button>
        </form>
    </div>
</template>
