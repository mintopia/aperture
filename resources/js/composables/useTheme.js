import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { applyAccentColor, useAccentColor } from './useAccentColor';

const VALID_MODES = ['light', 'dark'];

export function useTheme() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};
    const { accentHue, accentChroma, accentLightness } = useAccentColor();

    const mode = ref(sharedTheme.mode || localStorage.getItem('themeMode') || 'dark');
    const savedMode = ref(mode.value);

    function applyTheme() {
        const el = document.documentElement;
        el.setAttribute('data-theme', 'dispatch');
        el.setAttribute('data-mode', mode.value);
        applyAccentColor(accentHue.value, accentChroma.value, accentLightness.value, mode.value);
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
