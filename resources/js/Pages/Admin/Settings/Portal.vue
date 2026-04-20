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
    portal_session_timeout: props.settings?.portal_session_timeout ?? 86400,
    portal_redirect_url: props.settings?.portal_redirect_url ?? '',
});

function submit() {
    form.put(route('admin.settings.portal.update'));
}
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            Portal Settings
        </h1>

        <form class="space-y-4" @submit.prevent="submit">
            <FormField
                label="Session Timeout (seconds)"
                name="portal_session_timeout"
                required
                :error="form.errors.portal_session_timeout"
            >
                <input
                    id="portal_session_timeout"
                    v-model.number="form.portal_session_timeout"
                    type="number"
                    min="300"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="Redirect URL" name="portal_redirect_url" :error="form.errors.portal_redirect_url">
                <input
                    id="portal_redirect_url"
                    v-model="form.portal_redirect_url"
                    type="url"
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
