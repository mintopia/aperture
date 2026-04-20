import { describe, it, expect, vi, beforeEach } from 'vitest';
import { usePage } from '@inertiajs/vue3';
import { ACCENT_PRESETS, applyAccentHue, useAccentHue } from '@/composables/useAccentHue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: vi.fn(() => ({ props: { theme: null } })),
}));

describe('ACCENT_PRESETS', () => {
    it('has 8 presets', () => {
        expect(ACCENT_PRESETS).toHaveLength(8);
    });

    it('includes Tangerine at hue 55', () => {
        const tangerine = ACCENT_PRESETS.find((p) => p.name === 'Tangerine');
        expect(tangerine).toBeDefined();
        expect(tangerine.hue).toBe(55);
    });

    it('each preset has name, hue, l, c properties', () => {
        ACCENT_PRESETS.forEach((p) => {
            expect(p).toHaveProperty('name');
            expect(p).toHaveProperty('hue');
            expect(p).toHaveProperty('l');
            expect(p).toHaveProperty('c');
            expect(typeof p.hue).toBe('number');
            expect(typeof p.l).toBe('number');
            expect(typeof p.c).toBe('number');
        });
    });

    it('all hues are between 0 and 360', () => {
        ACCENT_PRESETS.forEach((p) => {
            expect(p.hue).toBeGreaterThanOrEqual(0);
            expect(p.hue).toBeLessThanOrEqual(360);
        });
    });
});

describe('applyAccentHue', () => {
    beforeEach(() => {
        document.documentElement.style.cssText = '';
    });

    it('sets CSS custom properties for preset hue (dark mode)', () => {
        applyAccentHue(55, 'dark');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-primary')).toContain('oklch');
        expect(root.style.getPropertyValue('--color-primary')).toContain('55');
        expect(root.style.getPropertyValue('--color-primary-hover')).toContain('oklch');
        expect(root.style.getPropertyValue('--color-accent')).toContain('oklch');
        expect(root.style.getPropertyValue('--color-accent-dim')).toContain('0.14');
        expect(root.style.getPropertyValue('--color-glow')).toContain('0.25');
    });

    it('sets CSS custom properties for preset hue (light mode)', () => {
        applyAccentHue(55, 'light');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-primary')).toContain('oklch');
        expect(root.style.getPropertyValue('--color-accent-dim')).toContain('0.1');
        expect(root.style.getPropertyValue('--color-glow')).toContain('0.15');
    });

    it('uses preset l and c values for known hues', () => {
        applyAccentHue(350, 'dark');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-primary')).toContain('72%');
        expect(root.style.getPropertyValue('--color-primary')).toContain('0.19');
    });

    it('uses default l=72, c=0.19 for non-preset hues', () => {
        applyAccentHue(123, 'dark');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-primary')).toContain('72%');
        expect(root.style.getPropertyValue('--color-primary')).toContain('0.19');
    });

    it('sets accent-text property', () => {
        applyAccentHue(230, 'dark');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-accent-text')).toContain('98%');
        expect(root.style.getPropertyValue('--color-accent-text')).toContain('230');
    });
});

describe('useAccentHue', () => {
    beforeEach(() => {
        document.documentElement.style.cssText = '';
        document.documentElement.removeAttribute('data-mode');
        usePage.mockReturnValue({ props: { theme: null } });
    });

    it('defaults to hue 55', () => {
        const { accentHue } = useAccentHue();
        expect(accentHue.value).toBe(55);
    });

    it('reads accent_hue from page props', () => {
        usePage.mockReturnValue({
            props: { theme: { accent_hue: 230 } },
        });
        const { accentHue } = useAccentHue();
        expect(accentHue.value).toBe(230);
    });

    it('setAccentHue updates the ref and applies CSS', () => {
        const { accentHue, setAccentHue } = useAccentHue();
        setAccentHue(295, 'dark');
        expect(accentHue.value).toBe(295);
        expect(document.documentElement.style.getPropertyValue('--color-primary')).toContain('295');
    });

    it('exposes presets array', () => {
        const { presets } = useAccentHue();
        expect(presets).toBe(ACCENT_PRESETS);
        expect(presets).toHaveLength(8);
    });
});
