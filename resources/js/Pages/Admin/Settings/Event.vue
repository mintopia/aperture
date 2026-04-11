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
    event_name: props.settings?.event_name ?? '',
    event_description: props.settings?.event_description ?? '',
});

function submit() {
    form.put(route('admin.settings.event.update'));
}
</script>

<template>
    <SettingsNav>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Event Settings
        </h1>

        <form
            class="space-y-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            @submit.prevent="submit"
        >
            <FormField label="Event Name" name="event_name" required :error="form.errors.event_name">
                <input
                    id="event_name"
                    v-model="form.event_name"
                    type="text"
                    class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                />
            </FormField>

            <FormField label="Description" name="event_description" :error="form.errors.event_description">
                <textarea
                    id="event_description"
                    v-model="form.event_description"
                    rows="5"
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
