<script setup>
import { ref, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme.js';
import { ACCENT_PRESETS, applyAccentHue } from '@/composables/useAccentHue.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const initialHue = props.settings?.accent_hue ?? 55;

const form = useForm({
    theme_mode: props.settings?.theme_mode ?? 'dark',
    accent_hue: initialHue,
    site_title: props.settings?.site_title ?? 'Aperture',
    custom_css: props.settings?.custom_css ?? '',
});

const { previewMode, cancelPreview } = useTheme();

const originalHue = ref(initialHue);

const modes = ['light', 'dark'];

function selectMode(mode) {
    form.theme_mode = mode;
    previewMode(mode);
    applyAccentHue(form.accent_hue, mode);
}

function selectPreset(hue) {
    form.accent_hue = hue;
    applyAccentHue(hue, form.theme_mode);
}

function onSliderInput(event) {
    const hue = Number(event.target.value);
    form.accent_hue = hue;
    applyAccentHue(hue, form.theme_mode);
}

function submit() {
    originalHue.value = form.accent_hue;
    form.put(route('admin.settings.theme.update'));
}

onBeforeUnmount(() => {
    cancelPreview();
    applyAccentHue(originalHue.value, props.settings?.theme_mode ?? 'dark');
});
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            Theme Settings
        </h1>

        <form class="space-y-6" @submit.prevent="submit">
            <FormField label="Accent Color" name="accent_hue">
                <div class="flex flex-wrap gap-3">
                    <button
                        v-for="preset in ACCENT_PRESETS"
                        :key="preset.hue"
                        type="button"
                        :data-testid="'accent-preset-' + preset.hue"
                        :title="preset.name"
                        :style="{
                            backgroundColor: `oklch(${preset.l}% ${preset.c} ${preset.hue})`,
                        }"
                        :class="[
                            'h-8 w-8 rounded-full transition-all',
                            form.accent_hue === preset.hue
                                ? 'ring-2 ring-[var(--color-primary)] ring-offset-2 ring-offset-[var(--color-bg)]'
                                : 'hover:scale-110',
                        ]"
                        @click="selectPreset(preset.hue)"
                    />
                </div>

                <input
                    type="range"
                    min="0"
                    max="360"
                    :value="form.accent_hue"
                    data-testid="accent-hue-slider"
                    class="mt-3 h-2 w-full cursor-pointer appearance-none rounded-full"
                    style="
                        background: linear-gradient(
                            to right,
                            oklch(70% 0.18 0),
                            oklch(70% 0.18 60),
                            oklch(70% 0.18 120),
                            oklch(70% 0.18 180),
                            oklch(70% 0.18 240),
                            oklch(70% 0.18 300),
                            oklch(70% 0.18 360)
                        );
                    "
                    @input="onSliderInput"
                />
                <p class="mt-1 text-xs text-[var(--color-text-muted)]">Hue: {{ form.accent_hue }}</p>
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
                                ? 'bg-[var(--color-accent-dim)] font-semibold text-[var(--color-primary)]'
                                : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text)]'
                        "
                        class="rounded-md px-4 py-[7px] text-[13px] font-medium capitalize transition-all"
                        @click="selectMode(mode)"
                    >
                        {{ mode }}
                    </button>
                </div>
            </FormField>

            <FormField label="Site Title" name="site_title" :error="form.errors.site_title">
                <input
                    id="site_title"
                    v-model="form.site_title"
                    type="text"
                    data-testid="input-site_title"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="Custom CSS" name="custom_css" :error="form.errors.custom_css">
                <textarea
                    id="custom_css"
                    v-model="form.custom_css"
                    data-testid="input-custom_css"
                    rows="8"
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
