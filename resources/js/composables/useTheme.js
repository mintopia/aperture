import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const VALID_THEMES = ['cool-neon', 'warm-neon', 'matrix', 'amber-glow'];
const VALID_MODES = ['light', 'dark'];

export function useTheme() {
    const page = usePage();
    const sharedTheme = page.props.theme || {};

    const theme = ref(sharedTheme.name || localStorage.getItem('theme') || 'cool-neon');
    const mode = ref(sharedTheme.mode || localStorage.getItem('themeMode') || 'dark');

    function applyTheme() {
        const el = document.documentElement;
        el.setAttribute('data-theme', theme.value);
        el.setAttribute('data-mode', mode.value);
    }

    function setTheme(name) {
        if (VALID_THEMES.includes(name)) {
            theme.value = name;
            localStorage.setItem('theme', name);
            applyTheme();
        }
    }

    function toggleMode() {
        mode.value = mode.value === 'dark' ? 'light' : 'dark';
        localStorage.setItem('themeMode', mode.value);
        applyTheme();
    }

    function setMode(newMode) {
        if (VALID_MODES.includes(newMode)) {
            mode.value = newMode;
            localStorage.setItem('themeMode', newMode);
            applyTheme();
        }
    }

    watch([theme, mode], applyTheme, { immediate: true });

    return {
        theme,
        mode,
        setTheme,
        toggleMode,
        setMode,
        themes: VALID_THEMES,
    };
}
