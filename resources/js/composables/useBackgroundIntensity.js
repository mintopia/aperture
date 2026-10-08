import { ref } from 'vue';
import { readStored, writeStored } from './appearanceStorage';

const STORAGE_KEY = 'backgroundIntensity';

export const MIN_INTENSITY = 0;
export const MAX_INTENSITY = 100;
export const DEFAULT_INTENSITY = 50;

// Slider 0..100 maps to a chroma multiplier. The default (50) is exactly 1, so the theme tokens
// in dispatch.css describe the mid-range look and the ends scale from there.
export const MIN_SCALE = 0.25;
export const MAX_SCALE = 1.75;

let intensity = null;

export function clampIntensity(value) {
    const number = Number(value);
    if (!Number.isFinite(number)) return DEFAULT_INTENSITY;

    return Math.min(MAX_INTENSITY, Math.max(MIN_INTENSITY, Math.round(number)));
}

export function intensityToScale(value) {
    return MIN_SCALE + (clampIntensity(value) / MAX_INTENSITY) * (MAX_SCALE - MIN_SCALE);
}

function readPreference() {
    const stored = readStored(STORAGE_KEY);

    return stored === null ? DEFAULT_INTENSITY : clampIntensity(stored);
}

function apply() {
    document.documentElement.style.setProperty('--bg-intensity', String(intensityToScale(intensity.value)));
}

export function useBackgroundIntensity() {
    if (intensity === null) {
        intensity = ref(readPreference());
        apply();
    }

    function setIntensity(value) {
        intensity.value = clampIntensity(value);
        writeStored(STORAGE_KEY, String(intensity.value));
        apply();
    }

    return { intensity, setIntensity };
}

export function resetBackgroundIntensityState() {
    intensity = null;
}
