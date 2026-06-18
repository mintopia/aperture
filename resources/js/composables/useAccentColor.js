import { ref, onMounted } from 'vue';
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

export function applyAccentColor(hue, chroma, lightness, mode = 'dark') {
    const root = document.documentElement;

    let l = lightness;
    let c = chroma;

    if (mode === 'light') {
        l = Math.max(l - 21, 40);
        c = c + 0.02;
        root.style.setProperty('--color-primary', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-primary-hover', `oklch(${l - 7}% ${c + 0.02} ${hue})`);
        root.style.setProperty('--color-accent', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-accent-hover', `oklch(${l - 7}% ${c + 0.02} ${hue})`);
        root.style.setProperty('--color-accent-dim', `oklch(${l}% ${c} ${hue} / 0.1)`);
        root.style.setProperty('--color-accent-text', `oklch(99% 0.005 ${hue})`);
        root.style.setProperty('--color-glow', `oklch(${l}% ${c} ${hue} / 0.15)`);
    } else {
        root.style.setProperty('--color-primary', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-primary-hover', `oklch(${l - 7}% ${c + 0.03} ${hue})`);
        root.style.setProperty('--color-accent', `oklch(${l}% ${c} ${hue})`);
        root.style.setProperty('--color-accent-hover', `oklch(${l - 7}% ${c + 0.03} ${hue})`);
        root.style.setProperty('--color-accent-dim', `oklch(${l}% ${c} ${hue} / 0.14)`);
        root.style.setProperty('--color-accent-text', `oklch(98% 0.01 ${hue})`);
        root.style.setProperty('--color-glow', `oklch(${l}% ${c} ${hue} / 0.25)`);
    }
}

export function applyAccentHue(hue, mode = 'dark') {
    const preset = ACCENT_PRESETS.find((p) => p.hue === hue);
    const l = preset ? preset.l : DEFAULT_LIGHTNESS;
    const c = preset ? preset.c : DEFAULT_CHROMA;
    applyAccentColor(hue, c, l, mode);
}

export function useAccentColor() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};
    const accentHue = ref(sharedTheme.accent_hue ?? DEFAULT_HUE);
    const accentChroma = ref(sharedTheme.accent_chroma ?? DEFAULT_CHROMA);
    const accentLightness = ref(sharedTheme.accent_lightness ?? DEFAULT_LIGHTNESS);

    function setAccentColor(hue, chroma, lightness, mode = 'dark') {
        accentHue.value = hue;
        accentChroma.value = chroma;
        accentLightness.value = lightness;
        applyAccentColor(hue, chroma, lightness, mode);
    }

    onMounted(() => {
        const mode = document.documentElement.getAttribute('data-mode') || 'dark';
        applyAccentColor(accentHue.value, accentChroma.value, accentLightness.value, mode);
    });

    return {
        accentHue,
        accentChroma,
        accentLightness,
        setAccentColor,
        presets: ACCENT_PRESETS,
    };
}
