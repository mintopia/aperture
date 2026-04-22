<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    detection_endpoint: props.settings?.detection_endpoint ?? '',
    jwks_url: props.settings?.jwks_url ?? '',
});

function submit() {
    form.put(route('admin.settings.ipv6-detection.update'));
}
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            IPv6 Detection
        </h1>

        <form class="space-y-4" data-testid="ipv6-settings-form" @submit.prevent="submit">
            <FormField label="Detection Endpoint" name="detection_endpoint" :error="form.errors.detection_endpoint">
                <input
                    id="detection_endpoint"
                    v-model="form.detection_endpoint"
                    data-testid="detection-endpoint-input"
                    type="url"
                    placeholder="https://{random}.ipv6.example.com"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="JWKS URL" name="jwks_url" :error="form.errors.jwks_url">
                <input
                    id="jwks_url"
                    v-model="form.jwks_url"
                    data-testid="jwks-url-input"
                    type="url"
                    placeholder="https://ipv6.example.com/.well-known/jwks.json"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <div class="pt-2">
                <button
                    type="submit"
                    data-testid="action-save"
                    :disabled="form.processing"
                    class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
                >
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</template>
