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

function findPreset(hue) {
    return ACCENT_PRESETS.find((p) => p.hue === hue);
}

export function applyAccentHue(hue, mode = 'dark') {
    const root = document.documentElement;
    const preset = findPreset(hue);

    let l, c;
    if (preset) {
        l = preset.l;
        c = preset.c;
    } else {
        l = 72;
        c = 0.19;
    }

    if (mode === 'light') {
        const lightL = Math.max(l - 21, 40);
        const lightC = c + 0.02;
        root.style.setProperty('--color-primary', `oklch(${lightL}% ${lightC} ${hue})`);
        root.style.setProperty('--color-primary-hover', `oklch(${lightL - 7}% ${lightC + 0.02} ${hue})`);
        root.style.setProperty('--color-accent', `oklch(${lightL}% ${lightC} ${hue})`);
        root.style.setProperty('--color-accent-hover', `oklch(${lightL - 7}% ${lightC + 0.02} ${hue})`);
        root.style.setProperty('--color-accent-dim', `oklch(${lightL}% ${lightC} ${hue} / 0.1)`);
        root.style.setProperty('--color-accent-text', `oklch(99% 0.005 ${hue})`);
        root.style.setProperty('--color-glow', `oklch(${lightL}% ${lightC} ${hue} / 0.15)`);
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

export function useAccentHue() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};
    const accentHue = ref(sharedTheme.accent_hue ?? DEFAULT_HUE);

    function setAccentHue(hue, mode = 'dark') {
        accentHue.value = hue;
        applyAccentHue(hue, mode);
    }

    onMounted(() => {
        const mode = document.documentElement.getAttribute('data-mode') || 'dark';
        applyAccentHue(accentHue.value, mode);
    });

    return {
        accentHue,
        setAccentHue,
        presets: ACCENT_PRESETS,
    };
}
