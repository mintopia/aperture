<script setup>
import { ref, computed, onBeforeUnmount } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme.js';
import { ACCENT_PRESETS, applyAccentColor } from '@/composables/useAccentColor.js';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    theme_mode: props.settings?.theme_mode ?? 'dark',
    accent_hue: props.settings?.accent_hue ?? 55,
    accent_chroma: props.settings?.accent_chroma ?? 0.19,
    accent_lightness: props.settings?.accent_lightness ?? 72,
    site_title: props.settings?.site_title ?? 'Aperture',
    custom_css: props.settings?.custom_css ?? '',
});

const { previewMode, cancelPreview } = useTheme();

const originalHue = ref(props.settings?.accent_hue ?? 55);
const originalChroma = ref(props.settings?.accent_chroma ?? 0.19);
const originalLightness = ref(props.settings?.accent_lightness ?? 72);

const modes = ['light', 'dark'];

const activePreset = computed(() =>
    ACCENT_PRESETS.find(
        (p) => p.hue === form.accent_hue && p.c === form.accent_chroma && p.l === form.accent_lightness,
    ),
);

function applyCurrentColor() {
    applyAccentColor(form.accent_hue, form.accent_chroma, form.accent_lightness, form.theme_mode);
}

function selectMode(mode) {
    form.theme_mode = mode;
    previewMode(mode);
    applyCurrentColor();
}

function selectPreset(preset) {
    form.accent_hue = preset.hue;
    form.accent_chroma = preset.c;
    form.accent_lightness = preset.l;
    applyCurrentColor();
}

function onHueInput(event) {
    form.accent_hue = Number(event.target.value);
    applyCurrentColor();
}

function onChromaInput(event) {
    form.accent_chroma = Number(Number(event.target.value).toFixed(2));
    applyCurrentColor();
}

function onLightnessInput(event) {
    form.accent_lightness = Number(event.target.value);
    applyCurrentColor();
}

function submit() {
    originalHue.value = form.accent_hue;
    originalChroma.value = form.accent_chroma;
    originalLightness.value = form.accent_lightness;
    form.put(route('admin.settings.theme.update'));
}

onBeforeUnmount(() => {
    cancelPreview();
    applyAccentColor(
        originalHue.value,
        originalChroma.value,
        originalLightness.value,
        props.settings?.theme_mode ?? 'dark',
    );
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
                        :style="{ backgroundColor: `oklch(${preset.l}% ${preset.c} ${preset.hue})` }"
                        :class="[
                            'h-8 w-8 rounded-full transition-all',
                            activePreset && activePreset.hue === preset.hue
                                ? 'ring-2 ring-[var(--color-primary)] ring-offset-2 ring-offset-[var(--color-bg)]'
                                : 'hover:scale-110',
                        ]"
                        @click="selectPreset(preset)"
                    />
                </div>

                <div class="mt-4 space-y-3">
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <span
                                class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                >Hue</span
                            >
                            <span class="font-mono text-xs text-[var(--color-text-muted)]">{{ form.accent_hue }}°</span>
                        </div>
                        <input
                            type="range"
                            min="0"
                            max="360"
                            :value="form.accent_hue"
                            data-testid="accent-hue-slider"
                            class="h-2 w-full cursor-pointer appearance-none rounded-full"
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
                            @input="onHueInput"
                        />
                    </div>

                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <span
                                class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                >Saturation</span
                            >
                            <span class="font-mono text-xs text-[var(--color-text-muted)]">{{
                                form.accent_chroma
                            }}</span>
                        </div>
                        <input
                            type="range"
                            min="0.01"
                            max="0.37"
                            step="0.01"
                            :value="form.accent_chroma"
                            data-testid="accent-chroma-slider"
                            class="h-2 w-full cursor-pointer appearance-none rounded-full"
                            :style="{
                                background: `linear-gradient(to right, oklch(${form.accent_lightness}% 0.01 ${form.accent_hue}), oklch(${form.accent_lightness}% 0.19 ${form.accent_hue}), oklch(${form.accent_lightness}% 0.37 ${form.accent_hue}))`,
                            }"
                            @input="onChromaInput"
                        />
                    </div>

                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <span
                                class="text-[11px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                >Lightness</span
                            >
                            <span class="font-mono text-xs text-[var(--color-text-muted)]"
                                >{{ form.accent_lightness }}%</span
                            >
                        </div>
                        <input
                            type="range"
                            min="40"
                            max="95"
                            :value="form.accent_lightness"
                            data-testid="accent-lightness-slider"
                            class="h-2 w-full cursor-pointer appearance-none rounded-full"
                            :style="{
                                background: `linear-gradient(to right, oklch(40% ${form.accent_chroma} ${form.accent_hue}), oklch(67% ${form.accent_chroma} ${form.accent_hue}), oklch(95% ${form.accent_chroma} ${form.accent_hue}))`,
                            }"
                            @input="onLightnessInput"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <div
                            data-testid="accent-preview-swatch"
                            class="h-10 w-10 rounded-lg border border-[var(--color-border)]"
                            :style="{
                                backgroundColor: `oklch(${form.accent_lightness}% ${form.accent_chroma} ${form.accent_hue})`,
                            }"
                        />
                        <span class="font-mono text-xs text-[var(--color-text-muted)]">
                            oklch({{ form.accent_lightness }}% {{ form.accent_chroma }} {{ form.accent_hue }})
                        </span>
                    </div>
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
