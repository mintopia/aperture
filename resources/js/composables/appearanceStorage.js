export function readStored(key) {
    try {
        return localStorage.getItem(key);
    } catch {
        // Storage can be unavailable (private mode, blocked site data); callers fall back to defaults.
        return null;
    }
}

export function writeStored(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // The preference still applies for this page view.
    }
}

export function prefersReducedMotion() {
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
}
