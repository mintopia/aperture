<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    detection_enabled: props.settings?.detection_enabled ?? false,
    detection_endpoint: props.settings?.detection_endpoint ?? '',
    jwks_url: props.settings?.jwks_url ?? '',
});

function submit() {
    form.put(route('admin.settings.ipv6-detection.update'));
}
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            IPv6 Detection
        </h1>

        <form class="mt-6 space-y-4" data-testid="ipv6-settings-form" @submit.prevent="submit">
            <FormField label="Enable Detection" name="detection_enabled" :error="form.errors.detection_enabled">
                <label class="relative inline-flex cursor-pointer items-center gap-3">
                    <input
                        v-model="form.detection_enabled"
                        data-testid="detection-enabled-toggle"
                        type="checkbox"
                        class="peer sr-only"
                        :true-value="true"
                        :false-value="false"
                    />
                    <span
                        class="h-5 w-9 rounded-full bg-[var(--color-border)] transition-colors peer-checked:bg-[var(--color-primary)] after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform after:content-[''] peer-checked:after:translate-x-4"
                    />
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ form.detection_enabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </label>
            </FormField>

            <FormField label="Detection Endpoint" name="detection_endpoint" :error="form.errors.detection_endpoint">
                <input
                    v-model="form.detection_endpoint"
                    data-testid="detection-endpoint-input"
                    type="url"
                    placeholder="https://{random}.ipv6.example.com"
                    class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-[13px] text-[var(--color-text)] transition-colors outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="JWKS URL" name="jwks_url" :error="form.errors.jwks_url">
                <input
                    v-model="form.jwks_url"
                    data-testid="jwks-url-input"
                    type="url"
                    placeholder="https://ipv6.example.com/.well-known/jwks.json"
                    class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-[13px] text-[var(--color-text)] transition-colors outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <div class="pt-2">
                <button
                    data-testid="save-button"
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-[var(--color-primary)] px-4 py-2 text-[13px] font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                >
                    Save Settings
                </button>
            </div>
        </form>
    </SettingsNav>
</template>
