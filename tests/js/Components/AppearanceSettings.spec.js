import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import AppearanceSettings from '@/Components/AppearanceSettings.vue';
import { resetTransparencyState, useTransparency } from '@/composables/useTransparency';
import { resetGlassSheenPreferenceState } from '@/composables/useGlassSheenPreference';
import { resetAnimatedBackgroundState } from '@/composables/useAnimatedBackground';
import { resetBackgroundIntensityState } from '@/composables/useBackgroundIntensity';

const html = () => document.documentElement;
const tid = (wrapper, id) => wrapper.get(`[data-testid="${id}"]`);

describe('AppearanceSettings', () => {
    beforeEach(() => {
        localStorage.clear();
        for (const attr of ['data-transparency', 'data-sheen', 'data-animated-bg']) html().removeAttribute(attr);
        html().style.removeProperty('--bg-intensity');
        vi.stubGlobal(
            'matchMedia',
            vi.fn(() => ({ matches: false })),
        );
        resetTransparencyState();
        resetGlassSheenPreferenceState();
        resetAnimatedBackgroundState();
        resetBackgroundIntensityState();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('renders every control with a test id and sensible defaults', () => {
        const wrapper = mount(AppearanceSettings);
        expect(wrapper.find('[data-testid="appearance-section"]').exists()).toBe(true);
        expect(tid(wrapper, 'appearance-background-intensity').element.value).toBe('50');
        expect(tid(wrapper, 'appearance-background-intensity-label').text()).toBe('Default');
        expect(tid(wrapper, 'appearance-reduce-transparency').attributes('aria-checked')).toBe('false');
        expect(tid(wrapper, 'appearance-glass-sheen').attributes('aria-checked')).toBe('true');
        expect(tid(wrapper, 'appearance-animated-background').attributes('aria-checked')).toBe('true');
        expect(tid(wrapper, 'appearance-reduce-transparency').attributes('role')).toBe('switch');
    });

    it('applies the slider live, persists it, and labels the ends', async () => {
        const wrapper = mount(AppearanceSettings);
        const slider = tid(wrapper, 'appearance-background-intensity');

        await slider.setValue('100');
        expect(html().style.getPropertyValue('--bg-intensity')).toBe('1.75');
        expect(localStorage.getItem('backgroundIntensity')).toBe('100');
        expect(tid(wrapper, 'appearance-background-intensity-label').text()).toBe('Vivid');

        await slider.setValue('0');
        expect(html().style.getPropertyValue('--bg-intensity')).toBe('0.25');
        expect(tid(wrapper, 'appearance-background-intensity-label').text()).toBe('Muted');

        await slider.setValue('35');
        expect(tid(wrapper, 'appearance-background-intensity-label').text()).toBe('Balanced');
    });

    it('toggles reduce transparency through the shared preference', async () => {
        const wrapper = mount(AppearanceSettings);
        await tid(wrapper, 'appearance-reduce-transparency').trigger('click');
        expect(html().getAttribute('data-transparency')).toBe('reduced');
        expect(localStorage.getItem('reduceTransparency')).toBe('1');
        expect(tid(wrapper, 'appearance-reduce-transparency').attributes('aria-checked')).toBe('true');

        // The user menu uses the same composable; flipping it elsewhere updates this switch.
        useTransparency().toggleTransparency();
        await wrapper.vm.$nextTick();
        expect(tid(wrapper, 'appearance-reduce-transparency').attributes('aria-checked')).toBe('false');
    });

    it('toggles glass sheen and animated background', async () => {
        const wrapper = mount(AppearanceSettings);

        await tid(wrapper, 'appearance-glass-sheen').trigger('click');
        expect(html().getAttribute('data-sheen')).toBe('off');
        expect(localStorage.getItem('glassSheen')).toBe('0');
        expect(tid(wrapper, 'appearance-glass-sheen').attributes('aria-checked')).toBe('false');

        await tid(wrapper, 'appearance-animated-background').trigger('click');
        expect(html().getAttribute('data-animated-bg')).toBe('off');
        expect(localStorage.getItem('animatedBackground')).toBe('0');
        expect(tid(wrapper, 'appearance-animated-background').attributes('aria-checked')).toBe('false');

        await tid(wrapper, 'appearance-animated-background').trigger('click');
        expect(html().hasAttribute('data-animated-bg')).toBe(false);
    });
});
