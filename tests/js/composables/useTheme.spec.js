import { describe, it, expect, vi, beforeEach } from 'vitest';
import { usePage } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';

vi.mock('@inertiajs/vue3', () => ({
    usePage: vi.fn(() => ({ props: { theme: null } })),
}));

vi.mock('@/composables/useAccentHue', () => ({
    applyAccentHue: vi.fn(),
}));

const localStorageMock = (() => {
    let store = {};
    return {
        getItem: vi.fn((key) => store[key] ?? null),
        setItem: vi.fn((key, value) => {
            store[key] = String(value);
        }),
        removeItem: vi.fn((key) => {
            delete store[key];
        }),
        clear: vi.fn(() => {
            store = {};
        }),
    };
})();

Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock });

describe('useTheme', () => {
    beforeEach(() => {
        localStorageMock.clear();
        localStorageMock.getItem.mockClear();
        localStorageMock.setItem.mockClear();
        document.documentElement.removeAttribute('data-theme');
        document.documentElement.removeAttribute('data-mode');
        usePage.mockReturnValue({ props: { theme: null } });
    });

    it('returns mode ref', () => {
        const { mode } = useTheme();
        expect(mode.value).toBeDefined();
    });

    it('defaults to dark mode', () => {
        const { mode } = useTheme();
        expect(mode.value).toBe('dark');
    });

    it('uses shared page props mode when available', () => {
        usePage.mockReturnValue({
            props: { theme: { mode: 'light', accent_hue: 230 } },
        });
        const { mode } = useTheme();
        expect(mode.value).toBe('light');
    });

    it('uses localStorage mode when no page props', () => {
        localStorage.setItem('themeMode', 'light');
        const { mode } = useTheme();
        expect(mode.value).toBe('light');
    });

    it('toggleMode switches between dark and light', () => {
        const { mode, toggleMode } = useTheme();
        expect(mode.value).toBe('dark');
        toggleMode();
        expect(mode.value).toBe('light');
        toggleMode();
        expect(mode.value).toBe('dark');
    });

    it('setMode sets valid mode', () => {
        const { mode, setMode } = useTheme();
        setMode('light');
        expect(mode.value).toBe('light');
    });

    it('setMode ignores invalid mode', () => {
        const { mode, setMode } = useTheme();
        setMode('invalid');
        expect(mode.value).toBe('dark');
    });

    it('always sets data-theme to dispatch', () => {
        useTheme();
        expect(document.documentElement.getAttribute('data-theme')).toBe('dispatch');
    });

    it('sets data-mode on document.documentElement', () => {
        useTheme();
        expect(document.documentElement.getAttribute('data-mode')).toBe('dark');
    });

    it('toggleMode saves to localStorage', () => {
        const { toggleMode } = useTheme();
        toggleMode();
        expect(localStorage.getItem('themeMode')).toBe('light');
    });

    it('previewMode applies mode without saving to localStorage', () => {
        const { previewMode } = useTheme();
        localStorageMock.setItem.mockClear();
        previewMode('light');
        expect(document.documentElement.getAttribute('data-mode')).toBe('light');
        expect(localStorageMock.setItem).not.toHaveBeenCalledWith('themeMode', 'light');
    });

    it('cancelPreview restores original mode', () => {
        const { previewMode, cancelPreview, mode } = useTheme();
        const original = mode.value;
        previewMode('light');
        cancelPreview();
        expect(document.documentElement.getAttribute('data-mode')).toBe(original);
    });
});
