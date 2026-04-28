/* eslint-disable vue/one-component-per-file */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { defineComponent } from 'vue';
import { mount } from '@vue/test-utils';
import { usePage } from '@inertiajs/vue3';
import {
    ACCENT_PRESETS,
    applyAccentHue,
    useAccentColor,
} from '@/composables/useAccentColor';

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

    it('uses default l=76, c=0.16 for non-preset hues', () => {
        applyAccentHue(123, 'dark');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-primary')).toContain('76%');
        expect(root.style.getPropertyValue('--color-primary')).toContain('0.16');
    });

    it('sets accent-text property', () => {
        applyAccentHue(230, 'dark');
        const root = document.documentElement;
        expect(root.style.getPropertyValue('--color-accent-text')).toContain('98%');
        expect(root.style.getPropertyValue('--color-accent-text')).toContain('230');
    });
});

describe('useAccentColor', () => {
    beforeEach(() => {
        document.documentElement.style.cssText = '';
        document.documentElement.removeAttribute('data-mode');
        usePage.mockReturnValue({ props: { theme: null } });
    });

    it('defaults to hue 55', () => {
        const { accentHue } = useAccentColor();
        expect(accentHue.value).toBe(55);
    });

    it('reads accent_hue from page props', () => {
        usePage.mockReturnValue({
            props: { theme: { accent_hue: 230 } },
        });
        const { accentHue } = useAccentColor();
        expect(accentHue.value).toBe(230);
    });

    it('setAccentColor updates the ref and applies CSS', () => {
        const { accentHue, setAccentColor } = useAccentColor();
        setAccentColor(295, 0.18, 70, 'dark');
        expect(accentHue.value).toBe(295);
        expect(
            document.documentElement.style.getPropertyValue('--color-primary'),
        ).toContain('295');
    });

    it('exposes presets array', () => {
        const { presets } = useAccentColor();
        expect(presets).toBe(ACCENT_PRESETS);
        expect(presets).toHaveLength(8);
    });

    it('applies accent color on mount (via component context)', async () => {
        usePage.mockReturnValue({ props: { theme: { accent_hue: 230 } } });
        document.documentElement.removeAttribute('data-mode');
        document.documentElement.style.cssText = '';

        const TestComponent = defineComponent({
            setup() {
                return useAccentColor();
            },
            template: '<div></div>',
        });

        const wrapper = mount(TestComponent);
        await wrapper.vm.$nextTick();

        expect(
            document.documentElement.style.getPropertyValue('--color-primary'),
        ).toContain('230');
    });

    it('uses data-mode attribute from documentElement in onMounted', async () => {
        usePage.mockReturnValue({ props: { theme: { accent_hue: 135 } } });
        document.documentElement.setAttribute('data-mode', 'light');
        document.documentElement.style.cssText = '';

        const TestComponent = defineComponent({
            setup() {
                return useAccentColor();
            },
            template: '<div></div>',
        });

        const wrapper = mount(TestComponent);
        await wrapper.vm.$nextTick();

        // light mode: lightL = max(80 - 21, 40) = 59
        expect(
            document.documentElement.style.getPropertyValue(
                '--color-accent-dim',
            ),
        ).toContain('0.1');

        document.documentElement.removeAttribute('data-mode');
    });
});
