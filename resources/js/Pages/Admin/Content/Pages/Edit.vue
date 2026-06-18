<script setup>
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    page: { type: Object, required: true },
});

const form = useForm({
    title: props.page.title,
    slug: props.page.slug,
    content: props.page.content ?? '',
});

const showDeleteModal = ref(false);
const deleting = ref(false);

function submit() {
    form.put(route('admin.content.pages.update', props.page.id));
}

function confirmDelete() {
    deleting.value = true;
    router.delete(route('admin.content.pages.destroy', props.page.id), {
        onFinish: () => {
            deleting.value = false;
            showDeleteModal.value = false;
        },
    });
}
</script>

<template>
    <div data-testid="page-edit">
        <!-- Page Header -->
        <header class="mb-6 flex items-start justify-between gap-6">
            <div>
                <h1
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Edit Page
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Update this static content page.</p>
            </div>
        </header>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- Title + Slug (side by side) -->
            <div class="grid gap-4 md:grid-cols-2">
                <FormField label="Title" name="title" :required="true" :error="form.errors.title">
                    <input
                        id="title"
                        v-model="form.title"
                        type="text"
                        data-testid="input-title"
                        placeholder="Page title"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    />
                </FormField>

                <FormField label="Slug" name="slug" :required="true" :error="form.errors.slug">
                    <div
                        class="flex items-center rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] transition focus-within:border-[var(--color-primary)]"
                    >
                        <span
                            class="pl-3 font-mono text-[12px] whitespace-nowrap text-[var(--color-text-muted)] select-none"
                            >/content/</span
                        >
                        <input
                            id="slug"
                            v-model="form.slug"
                            type="text"
                            data-testid="input-slug"
                            placeholder="page-slug"
                            class="min-w-0 flex-1 bg-transparent py-2 pr-3 font-mono text-[13px] text-[var(--color-text)] outline-none"
                        />
                    </div>
                </FormField>
            </div>

            <!-- Content -->
            <div>
                <MarkdownEditor v-model="form.content" placeholder="Write your page content here...">
                    <template #label>
                        <span
                            class="block text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                        >
                            Content
                        </span>
                    </template>
                </MarkdownEditor>
                <p v-if="form.errors.content" class="mt-1 text-xs text-[var(--color-danger)]">
                    {{ form.errors.content }}
                </p>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    data-testid="action-save"
                    class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save Page' }}
                </button>
                <Link
                    :href="route('admin.content.pages.index')"
                    data-testid="action-cancel"
                    class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition hover:bg-[var(--color-surface-hover)]"
                >
                    Cancel
                </Link>
                <div class="ml-auto">
                    <button
                        type="button"
                        data-testid="action-delete"
                        class="rounded-md border border-[var(--color-danger)]/30 px-4 py-[7px] text-[13px] font-semibold text-[var(--color-danger)] transition hover:bg-[var(--color-danger)]/10"
                        @click="showDeleteModal = true"
                    >
                        Delete Page
                    </button>
                </div>
            </div>
        </form>

        <!-- Delete confirmation modal -->
        <ConfirmModal
            :show="showDeleteModal"
            title="Delete Page"
            message="Are you sure you want to delete this page? This action cannot be undone."
            confirm-label="Delete Page"
            cancel-label="Cancel"
            variant="danger"
            :loading="deleting"
            @confirm="confirmDelete"
            @cancel="showDeleteModal = false"
        />
    </div>
</template>
