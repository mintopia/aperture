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
    dns_check_url: props.settings?.dns_check_url ?? '',
    dns_warning_message: props.settings?.dns_warning_message ?? '',
});

function submit() {
    form.put(route('admin.settings.dns-detection.update'));
}
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            DNS Detection
        </h1>

        <p class="mb-6 text-[13px] text-[var(--color-text-muted)]">
            Configure a URL to check whether users are using the event DNS servers. The URL must contain
            <code class="rounded bg-[var(--color-surface-hover)] px-1 py-0.5 text-[12px]">{uuid}</code> which is
            replaced with a random value to prevent caching. Leave empty to disable.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField label="Check URL" name="dns_check_url" :error="form.errors.dns_check_url">
                <input
                    id="dns_check_url"
                    v-model="form.dns_check_url"
                    type="text"
                    data-testid="input-dns-check-url"
                    placeholder="https://{uuid}.lancache.test.entropylan.party"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="Warning Message" name="dns_warning_message" :error="form.errors.dns_warning_message">
                <textarea
                    id="dns_warning_message"
                    v-model="form.dns_warning_message"
                    rows="3"
                    data-testid="input-dns-warning-message"
                    placeholder="Your device is not using the event DNS servers. Please update your DNS settings."
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
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
    </SettingsNav>
</template>
