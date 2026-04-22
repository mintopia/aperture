<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useForm } from '@inertiajs/vue3';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    page: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
});

const form = useForm({
    title: props.page.title,
    slug: props.page.slug,
    content: props.page.content ?? '',
});

const submit = () => {
    form.put(route('admin.content.pages.update', props.page.id));
};

const destroy = () => {
    if (confirm('Are you sure you want to delete this page?')) {
        form.delete(route('admin.content.pages.destroy', props.page.id));
    }
};
</script>

<template>
    <div>
        <h1 class="mb-4 text-xl font-semibold">Edit Page</h1>
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
            <button
                type="button"
                data-testid="action-delete"
                @click="destroy"
            >
                Delete
            </button>
        </form>
    </div>
</template>
