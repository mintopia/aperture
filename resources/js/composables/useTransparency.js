import { ref } from 'vue';

const STORAGE_KEY = 'reduceTransparency';

let reduced = null;

function readPreference() {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === '1') return true;
        if (stored === '0') return false;
    } catch {
        // Storage can be unavailable (private mode, blocked site data); fall back to the OS setting.
    }

    return window.matchMedia?.('(prefers-reduced-transparency: reduce)').matches ?? false;
}

function apply() {
    const root = document.documentElement;
    if (reduced.value) {
        root.setAttribute('data-transparency', 'reduced');
    } else {
        root.removeAttribute('data-transparency');
    }
}

export function useTransparency() {
    if (reduced === null) {
        reduced = ref(readPreference());
        apply();
    }

    function toggleTransparency() {
        reduced.value = !reduced.value;
        try {
            localStorage.setItem(STORAGE_KEY, reduced.value ? '1' : '0');
        } catch {
            // Preference still applies for this page view.
        }
        apply();
    }

    return { reduced, toggleTransparency };
}

export function resetTransparencyState() {
    reduced = null;
}
