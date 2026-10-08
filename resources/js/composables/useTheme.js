import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

export const ACCENT_PRESETS = [
    { name: 'Pink', hue: 350, l: 72, c: 0.19 },
    { name: 'Coral', hue: 20, l: 73, c: 0.17 },
    { name: 'Tangerine', hue: 55, l: 76, c: 0.16 },
    { name: 'Lime', hue: 135, l: 80, c: 0.18 },
    { name: 'Teal', hue: 185, l: 76, c: 0.12 },
    { name: 'Sky', hue: 230, l: 72, c: 0.14 },
    { name: 'Violet', hue: 295, l: 70, c: 0.18 },
    { name: 'Magenta', hue: 325, l: 70, c: 0.2 },
];

const DEFAULT_HUE = 55;
const DEFAULT_CHROMA = 0.16;
const DEFAULT_LIGHTNESS = 76;

const VALID_MODES = ['light', 'dark'];

const MODE_OFFSETS = {
    dark: { lightness: 0, chroma: 0, hoverChroma: 0.03, dim: 0.14, glow: 0.25, text: '98% 0.01' },
    light: { lightness: -21, chroma: 0.02, hoverChroma: 0.02, dim: 0.1, glow: 0.15, text: '99% 0.005' },
};

const round = (value) => Math.round(value * 1000) / 1000;

export function applyAccentColor(hue, chroma, lightness, mode = 'dark') {
    const root = document.documentElement;
    const offset = MODE_OFFSETS[mode] ?? MODE_OFFSETS.dark;

    const l = mode === 'light' ? Math.max(lightness + offset.lightness, 40) : lightness;
    const c = round(chroma + offset.chroma);
    const base = `oklch(${l}% ${c} ${hue}`;
    const hover = `oklch(${l - 7}% ${round(c + offset.hoverChroma)} ${hue})`;

    root.style.setProperty('--accent-hue', String(hue));
    root.style.setProperty('--color-primary', `${base})`);
    root.style.setProperty('--color-primary-hover', hover);
    root.style.setProperty('--color-accent', `${base})`);
    root.style.setProperty('--color-accent-dim', `${base} / ${offset.dim})`);
    root.style.setProperty('--color-accent-text', `oklch(${offset.text} ${hue})`);
    root.style.setProperty('--color-glow', `${base} / ${offset.glow})`);
}

export function useTheme() {
    const sharedTheme = usePage().props.theme || {};
    const accentHue = sharedTheme.accent_hue ?? DEFAULT_HUE;
    const accentChroma = sharedTheme.accent_chroma ?? DEFAULT_CHROMA;
    const accentLightness = sharedTheme.accent_lightness ?? DEFAULT_LIGHTNESS;

    const mode = ref(sharedTheme.mode || localStorage.getItem('themeMode') || 'dark');
    const savedMode = ref(mode.value);

    function applyTheme() {
        const el = document.documentElement;
        el.setAttribute('data-theme', 'dispatch');
        el.setAttribute('data-mode', mode.value);
        applyAccentColor(accentHue, accentChroma, accentLightness, mode.value);
    }

    function toggleMode() {
        mode.value = mode.value === 'dark' ? 'light' : 'dark';
        savedMode.value = mode.value;
        localStorage.setItem('themeMode', mode.value);
        applyTheme();
    }

    function previewMode(newMode) {
        if (VALID_MODES.includes(newMode)) {
            mode.value = newMode;
            applyTheme();
        }
    }

    function cancelPreview() {
        mode.value = savedMode.value;
        applyTheme();
    }

    watch(mode, () => applyTheme(), { immediate: true });

    return {
        mode,
        toggleMode,
        previewMode,
        cancelPreview,
    };
}
