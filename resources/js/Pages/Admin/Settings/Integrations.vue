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
    opnsense_endpoint: props.settings?.opnsense_endpoint ?? '',
    ntopng_endpoint: props.settings?.ntopng_endpoint ?? '',
    borealis_endpoint: props.settings?.borealis_endpoint ?? '',
    librenms_endpoint: props.settings?.librenms_endpoint ?? '',
});

function submit() {
    form.put(route('admin.settings.integrations.update'));
}
</script>

<template>
    <SettingsNav>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Integration Settings
        </h1>

        <form
            class="space-y-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            @submit.prevent="submit"
        >
            <FormField label="OPNsense Endpoint" name="opnsense_endpoint" :error="form.errors.opnsense_endpoint">
                <input
                    id="opnsense_endpoint"
                    v-model="form.opnsense_endpoint"
                    type="url"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <FormField label="ntopng Endpoint" name="ntopng_endpoint" :error="form.errors.ntopng_endpoint">
                <input
                    id="ntopng_endpoint"
                    v-model="form.ntopng_endpoint"
                    type="url"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <FormField label="Borealis Endpoint" name="borealis_endpoint" :error="form.errors.borealis_endpoint">
                <input
                    id="borealis_endpoint"
                    v-model="form.borealis_endpoint"
                    type="url"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <FormField label="LibreNMS Endpoint" name="librenms_endpoint" :error="form.errors.librenms_endpoint">
                <input
                    id="librenms_endpoint"
                    v-model="form.librenms_endpoint"
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
