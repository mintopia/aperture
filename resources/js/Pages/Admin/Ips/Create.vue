<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const form = useForm({
    address: '',
    comment: '',
    allow: false,
    limit: false,
});

function submit() {
    form.post(route('admin.ips.store'));
}
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Add IP Address
        </h1>

        <form
            class="space-y-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            @submit.prevent="submit"
        >
            <FormField label="IP Address" name="address" required :error="form.errors.address">
                <input
                    id="address"
                    v-model="form.address"
                    type="text"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <FormField label="Comment" name="comment" :error="form.errors.comment">
                <input
                    id="comment"
                    v-model="form.comment"
                    type="text"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-sm text-[var(--color-text)]">
                    <input
                        v-model="form.allow"
                        data-testid="field-allow"
                        type="checkbox"
                        class="rounded border-[var(--color-border)]"
                    />
                    Allow Internet
                </label>
                <label class="flex items-center gap-2 text-sm text-[var(--color-text)]">
                    <input
                        v-model="form.limit"
                        data-testid="field-limit"
                        type="checkbox"
                        class="rounded border-[var(--color-border)]"
                    />
                    Rate Limit
                </label>
            </div>

            <button
                type="submit"
                data-testid="action-submit"
                :disabled="form.processing"
                class="rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-hover)]"
            >
                Add IP Address
            </button>
        </form>
    </div>
</template>
