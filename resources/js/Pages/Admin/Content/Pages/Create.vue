<script setup>
import { watch, ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';
import MarkdownEditor from '@/Components/UI/MarkdownEditor.vue';

defineOptions({ layout: AdminLayout });

const form = useForm({
    title: '',
    slug: '',
    content: '',
});

const slugManuallyEdited = ref(false);

function generateSlug(title) {
    return title
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

watch(
    () => form.title,
    (title) => {
        if (!slugManuallyEdited.value) {
            form.slug = generateSlug(title);
        }
    },
);

function onSlugInput(event) {
    slugManuallyEdited.value = true;
    form.slug = event.target.value;
}

function submit() {
    form.post(route('admin.content.pages.store'));
}
</script>

<template>
    <div data-testid="page-create">
        <!-- Page Header -->
        <header class="mb-6 flex items-start justify-between gap-6">
            <div>
                <h1
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    New Page
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Create a new static content page.</p>
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
                            :value="form.slug"
                            type="text"
                            data-testid="input-slug"
                            placeholder="page-slug"
                            class="min-w-0 flex-1 bg-transparent py-2 pr-3 font-mono text-[13px] text-[var(--color-text)] outline-none"
                            @input="onSlugInput"
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
            </div>
        </form>
    </div>
</template>
