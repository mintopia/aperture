<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: Object,
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
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Portal Settings
        </h1>

        <form
            class="space-y-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            @submit.prevent="submit"
        >
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
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <FormField label="Redirect URL" name="portal_redirect_url" :error="form.errors.portal_redirect_url">
                <input
                    id="portal_redirect_url"
                    v-model="form.portal_redirect_url"
                    type="url"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <button
                type="submit"
                data-testid="action-save"
                :disabled="form.processing"
                class="rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-hover)]"
            >
                Save Settings
            </button>
        </form>
    </SettingsNav>
</template>
