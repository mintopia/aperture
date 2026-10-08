import { ref } from 'vue';
import { readStored, writeStored } from './appearanceStorage';

const STORAGE_KEY = 'glassSheen';

let enabled = null;

function apply() {
    const root = document.documentElement;
    if (enabled.value) {
        root.removeAttribute('data-sheen');
    } else {
        root.setAttribute('data-sheen', 'off');
    }
}

export function useGlassSheenPreference() {
    if (enabled === null) {
        enabled = ref(readStored(STORAGE_KEY) !== '0');
        apply();
    }

    function setSheen(value) {
        enabled.value = Boolean(value);
        writeStored(STORAGE_KEY, enabled.value ? '1' : '0');
        apply();
    }

    return { sheenEnabled: enabled, setSheen };
}

export function resetGlassSheenPreferenceState() {
    enabled = null;
}
