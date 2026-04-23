<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    pages: { type: Array, default: () => [] },
});

const form = useForm({
    site_title: props.settings?.site_title ?? '',
    terms_type: props.settings?.terms_type ?? 'url',
    terms_value: props.settings?.terms_value ?? '',
    privacy_type: props.settings?.privacy_type ?? 'url',
    privacy_value: props.settings?.privacy_value ?? '',
});

function submit() {
    form.put(route('admin.content.settings.update'));
}
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            General Settings
        </h1>

        <form class="space-y-4" @submit.prevent="submit">
            <!-- Branding -->
            <h2
                data-testid="section-heading-branding"
                class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
            >
                Branding
            </h2>

            <FormField label="Site Title" name="site_title" :error="form.errors.site_title">
                <input
                    id="site_title"
                    v-model="form.site_title"
                    type="text"
                    data-testid="input-site-title"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
                <p class="mt-1 text-[11px] text-[var(--color-text-muted)]">
                    Displayed in the portal header and browser tab
                </p>
            </FormField>

            <!-- Legal -->
            <h2
                data-testid="section-heading-legal"
                class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
            >
                Legal
            </h2>

            <!-- Terms and Conditions -->
            <FormField label="Terms and Conditions" name="terms_type" :error="form.errors.terms_type">
                <div class="space-y-2">
                    <select
                        id="terms_type"
                        v-model="form.terms_type"
                        data-testid="select-terms-type"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    >
                        <option value="page">Content Page</option>
                        <option value="url">Custom URL</option>
                    </select>

                    <select
                        v-if="form.terms_type === 'page'"
                        id="terms_value_page"
                        v-model="form.terms_value"
                        data-testid="select-terms-page"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    >
                        <option value="">Select a page…</option>
                        <option v-for="page in pages" :key="page.id" :value="page.slug">
                            {{ page.title }}
                        </option>
                    </select>

                    <input
                        v-if="form.terms_type === 'url'"
                        id="terms_value_url"
                        v-model="form.terms_value"
                        type="url"
                        data-testid="input-terms-url"
                        placeholder="https://example.com/terms"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    />
                </div>
                <p class="mt-1 text-[11px] text-[var(--color-text-muted)]">
                    Link shown in the portal for terms and conditions
                </p>
            </FormField>

            <!-- Privacy Policy -->
            <FormField label="Privacy Policy" name="privacy_type" :error="form.errors.privacy_type">
                <div class="space-y-2">
                    <select
                        id="privacy_type"
                        v-model="form.privacy_type"
                        data-testid="select-privacy-type"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    >
                        <option value="page">Content Page</option>
                        <option value="url">Custom URL</option>
                    </select>

                    <select
                        v-if="form.privacy_type === 'page'"
                        id="privacy_value_page"
                        v-model="form.privacy_value"
                        data-testid="select-privacy-page"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    >
                        <option value="">Select a page…</option>
                        <option v-for="page in pages" :key="page.id" :value="page.slug">
                            {{ page.title }}
                        </option>
                    </select>

                    <input
                        v-if="form.privacy_type === 'url'"
                        id="privacy_value_url"
                        v-model="form.privacy_value"
                        type="url"
                        data-testid="input-privacy-url"
                        placeholder="https://example.com/privacy"
                        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                    />
                </div>
                <p class="mt-1 text-[11px] text-[var(--color-text-muted)]">
                    Link shown in the portal for privacy policy
                </p>
            </FormField>

            <button
                type="submit"
                data-testid="action-save"
                :disabled="form.processing"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
            >
                Save Settings
            </button>
        </form>
    </div>
</template>
