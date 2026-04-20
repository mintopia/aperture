import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { applyAccentHue } from './useAccentHue';

const VALID_MODES = ['light', 'dark'];

export function useTheme() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};

    const mode = ref(sharedTheme.mode || localStorage.getItem('themeMode') || 'dark');
    const savedMode = ref(mode.value);
    const accentHue = ref(sharedTheme.accent_hue ?? 55);

    function applyTheme() {
        const el = document.documentElement;
        el.setAttribute('data-theme', 'dispatch');
        el.setAttribute('data-mode', mode.value);
        applyAccentHue(accentHue.value, mode.value);
    }

    function toggleMode() {
        mode.value = mode.value === 'dark' ? 'light' : 'dark';
        savedMode.value = mode.value;
        localStorage.setItem('themeMode', mode.value);
        applyTheme();
    }

    function setMode(newMode) {
        if (VALID_MODES.includes(newMode)) {
            mode.value = newMode;
            savedMode.value = newMode;
            localStorage.setItem('themeMode', newMode);
            applyTheme();
        }
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
        setMode,
        previewMode,
        cancelPreview,
    };
}
