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
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Add IP Address
            </h1>
        </div>

        <form data-testid="ip-create-form" class="mt-6 space-y-5" @submit.prevent="submit">
            <FormField label="IP Address" name="address" required :error="form.errors.address">
                <input
                    id="address"
                    v-model="form.address"
                    type="text"
                    data-testid="input-address"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="Comment" name="comment" :error="form.errors.comment">
                <input
                    id="comment"
                    v-model="form.comment"
                    type="text"
                    data-testid="input-comment"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <div class="flex gap-6">
                <label class="flex items-center gap-2 text-[13px] text-[var(--color-text)]">
                    <input
                        v-model="form.allow"
                        data-testid="field-allow"
                        type="checkbox"
                        class="rounded border-[var(--color-border-hover)]"
                    />
                    Allow Internet
                </label>
                <label class="flex items-center gap-2 text-[13px] text-[var(--color-text)]">
                    <input
                        v-model="form.limit"
                        data-testid="field-limit"
                        type="checkbox"
                        class="rounded border-[var(--color-border-hover)]"
                    />
                    Rate Limit
                </label>
            </div>

            <button
                type="submit"
                data-testid="action-submit"
                :disabled="form.processing"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-accent-text)]"
            >
                Add IP Address
            </button>
        </form>
    </div>
</template>
