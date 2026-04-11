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
    theme_name: props.settings?.theme_name ?? 'cool-neon',
    theme_mode: props.settings?.theme_mode ?? 'dark',
});

const themes = [
    { name: 'default', label: 'Default', colors: ['#6366f1', '#e11d48', '#059669'] },
    { name: 'cool-neon', label: 'Cool Neon', colors: ['#06b6d4', '#8b5cf6', '#22d3ee'] },
    { name: 'warm-neon', label: 'Warm Neon', colors: ['#ec4899', '#a855f7', '#f43f5e'] },
    { name: 'matrix', label: 'Matrix', colors: ['#22c55e', '#84cc16', '#14b8a6'] },
    { name: 'amber-glow', label: 'Amber Glow', colors: ['#f59e0b', '#ef4444', '#d97706'] },
];

const modes = ['light', 'dark'];

function submit() {
    form.put(route('admin.settings.theme.update'));
}
</script>

<template>
    <SettingsNav>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Theme Settings
        </h1>

        <form
            class="space-y-6 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            @submit.prevent="submit"
        >
            <FormField label="Theme" name="theme_name" required>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <button
                        v-for="theme in themes"
                        :key="theme.name"
                        type="button"
                        :data-testid="'theme-option-' + theme.name"
                        :class="
                            form.theme_name === theme.name
                                ? 'border-[var(--color-primary)] ring-2 ring-[var(--color-primary)]/20'
                                : 'border-[var(--color-border)] hover:border-[var(--color-border-hover)]'
                        "
                        class="flex flex-col items-center gap-2 rounded-lg border p-3 transition-all"
                        @click="form.theme_name = theme.name"
                    >
                        <div class="flex gap-1">
                            <span
                                v-for="color in theme.colors"
                                :key="color"
                                :style="{ backgroundColor: color }"
                                class="h-4 w-4 rounded-full"
                            />
                        </div>
                        <span class="text-xs font-medium text-[var(--color-text)]">{{ theme.label }}</span>
                    </button>
                </div>
            </FormField>

            <FormField label="Mode" name="theme_mode" required>
                <div class="flex gap-3">
                    <button
                        v-for="mode in modes"
                        :key="mode"
                        type="button"
                        :data-testid="'mode-option-' + mode"
                        :class="
                            form.theme_mode === mode
                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)]'
                                : 'border-[var(--color-border)] text-[var(--color-text-secondary)] hover:border-[var(--color-border-hover)]'
                        "
                        class="rounded-lg border px-4 py-2 text-sm font-medium capitalize transition-all"
                        @click="form.theme_mode = mode"
                    >
                        {{ mode }}
                    </button>
                </div>
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
