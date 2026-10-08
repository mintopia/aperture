import { ref } from 'vue';
import { prefersReducedMotion, readStored, writeStored } from './appearanceStorage';

const STORAGE_KEY = 'animatedBackground';

let animated = null;

function readPreference() {
    const stored = readStored(STORAGE_KEY);
    if (stored === '1') return true;
    if (stored === '0') return false;

    return !prefersReducedMotion();
}

function apply() {
    const root = document.documentElement;
    if (animated.value) {
        root.removeAttribute('data-animated-bg');
    } else {
        root.setAttribute('data-animated-bg', 'off');
    }
}

export function useAnimatedBackground() {
    if (animated === null) {
        animated = ref(readPreference());
        apply();
    }

    function setAnimated(value) {
        animated.value = Boolean(value);
        writeStored(STORAGE_KEY, animated.value ? '1' : '0');
        apply();
    }

    return { animated, setAnimated };
}

export function resetAnimatedBackgroundState() {
    animated = null;
}
