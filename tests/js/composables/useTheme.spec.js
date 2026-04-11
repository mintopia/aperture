import { describe, it, expect, vi, beforeEach } from 'vitest';
import { usePage } from '@inertiajs/vue3';
import { useTheme } from '@/composables/useTheme';

vi.mock('@inertiajs/vue3', () => ({
    usePage: vi.fn(() => ({ props: { theme: null } })),
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

    it('returns theme and mode refs', () => {
        const { theme, mode } = useTheme();
        expect(theme.value).toBeDefined();
        expect(mode.value).toBeDefined();
    });

    it('defaults to cool-neon theme and dark mode', () => {
        const { theme, mode } = useTheme();
        expect(theme.value).toBe('cool-neon');
        expect(mode.value).toBe('dark');
    });

    it('uses shared page props when available', () => {
        usePage.mockReturnValue({
            props: { theme: { name: 'matrix', mode: 'light' } },
        });
        const { theme, mode } = useTheme();
        expect(theme.value).toBe('matrix');
        expect(mode.value).toBe('light');
    });

    it('uses localStorage values when no page props', () => {
        localStorage.setItem('theme', 'warm-neon');
        localStorage.setItem('themeMode', 'light');
        const { theme, mode } = useTheme();
        expect(theme.value).toBe('warm-neon');
        expect(mode.value).toBe('light');
    });

    it('setTheme updates theme for valid theme names', () => {
        const { theme, setTheme } = useTheme();
        setTheme('matrix');
        expect(theme.value).toBe('matrix');
    });

    it('setTheme ignores invalid theme names', () => {
        const { theme, setTheme } = useTheme();
        setTheme('invalid-theme');
        expect(theme.value).toBe('cool-neon');
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

    it('applyTheme sets data-theme and data-mode on document.documentElement', () => {
        useTheme();
        expect(document.documentElement.getAttribute('data-theme')).toBe('cool-neon');
        expect(document.documentElement.getAttribute('data-mode')).toBe('dark');
    });

    it('setTheme saves to localStorage', () => {
        const { setTheme } = useTheme();
        setTheme('amber-glow');
        expect(localStorage.getItem('theme')).toBe('amber-glow');
    });

    it('toggleMode saves to localStorage', () => {
        const { toggleMode } = useTheme();
        toggleMode();
        expect(localStorage.getItem('themeMode')).toBe('light');
    });

    it('exposes themes array (VALID_THEMES)', () => {
        const { themes } = useTheme();
        expect(themes).toEqual(['cool-neon', 'warm-neon', 'matrix', 'amber-glow']);
    });
});
