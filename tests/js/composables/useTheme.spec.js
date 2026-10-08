import { describe, it, expect, vi, beforeEach } from 'vitest';
import { usePage } from '@inertiajs/vue3';
import { ACCENT_PRESETS, applyAccentColor, useTheme } from '@/composables/useTheme';

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
        document.documentElement.style.cssText = '';
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

    it('previewMode ignores invalid mode', () => {
        const { previewMode, mode } = useTheme();
        previewMode('invalid');
        expect(mode.value).toBe('dark');
    });

    it('applies accent from shared theme props', () => {
        usePage.mockReturnValue({
            props: { theme: { accent_hue: 230, accent_chroma: 0.14, accent_lightness: 72 } },
        });
        useTheme();
        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('oklch(72% 0.14 230)');
    });

    it('falls back to default accent without shared props', () => {
        useTheme();
        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('oklch(76% 0.16 55)');
    });
});

describe('ACCENT_PRESETS', () => {
    it('has 8 presets with hue, lightness and chroma', () => {
        expect(ACCENT_PRESETS).toHaveLength(8);
        ACCENT_PRESETS.forEach((p) => {
            expect(p.hue).toBeGreaterThanOrEqual(0);
            expect(p.hue).toBeLessThanOrEqual(360);
            expect(p.l).toBeGreaterThan(0);
            expect(p.c).toBeGreaterThan(0);
        });
    });
});

describe('applyAccentColor', () => {
    const get = (name) => document.documentElement.style.getPropertyValue(name);

    beforeEach(() => {
        document.documentElement.style.cssText = '';
    });

    it('applies dark mode values', () => {
        applyAccentColor(55, 0.16, 76, 'dark');
        expect(get('--color-primary')).toBe('oklch(76% 0.16 55)');
        expect(get('--color-accent')).toBe('oklch(76% 0.16 55)');
        expect(get('--color-primary-hover')).toBe('oklch(69% 0.19 55)');
        expect(get('--color-accent-dim')).toBe('oklch(76% 0.16 55 / 0.14)');
        expect(get('--color-accent-text')).toBe('oklch(98% 0.01 55)');
        expect(get('--color-glow')).toBe('oklch(76% 0.16 55 / 0.25)');
    });

    it('applies light mode offsets', () => {
        applyAccentColor(55, 0.16, 76, 'light');
        expect(get('--color-primary')).toBe('oklch(55% 0.18 55)');
        expect(get('--color-primary-hover')).toBe('oklch(48% 0.2 55)');
        expect(get('--color-accent-dim')).toBe('oklch(55% 0.18 55 / 0.1)');
        expect(get('--color-accent-text')).toBe('oklch(99% 0.005 55)');
        expect(get('--color-glow')).toBe('oklch(55% 0.18 55 / 0.15)');
    });

    it('clamps light mode lightness at 40', () => {
        applyAccentColor(55, 0.16, 50, 'light');
        expect(get('--color-primary')).toBe('oklch(40% 0.18 55)');
    });

    it('sets the accent hue variable', () => {
        applyAccentColor(55, 0.16, 76, 'dark');
        expect(get('--accent-hue')).toBe('55');
    });

    it('does not set the removed accent-hover variable', () => {
        applyAccentColor(55, 0.16, 76);
        expect(get('--color-accent-hover')).toBe('');
    });
});
