import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import ProgressBar from '@/Components/UI/ProgressBar.vue';

describe('ProgressBar', () => {
    it('renders with required props', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 50 },
        });
        expect(wrapper.find('[data-testid="progress-bar"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('CPU');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'Memory', value: 75 },
        });
        expect(wrapper.find('[data-testid="progress-bar"]').exists()).toBe(true);
    });

    it('displays default value/max text', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 50, max: 100 },
        });
        expect(wrapper.text()).toContain('50/100');
    });

    it('displays custom displayValue when provided', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'Disk', value: 256, max: 512, displayValue: '256 GB' },
        });
        expect(wrapper.text()).toContain('256 GB');
        expect(wrapper.text()).not.toContain('256/512');
    });

    it('calculates correct width percentage', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 30, max: 100 },
        });
        const bar = wrapper.find('.h-full.rounded-full.transition-all');
        expect(bar.attributes('style')).toContain('width: 30%');
    });

    it('caps width at 100% when value exceeds max', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 150, max: 100 },
        });
        const bar = wrapper.find('.h-full.rounded-full.transition-all');
        expect(bar.attributes('style')).toContain('width: 100%');
    });

    it('uses default max of 100', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 75 },
        });
        expect(wrapper.text()).toContain('75/100');
    });

    it('handles zero value', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 0 },
        });
        const bar = wrapper.find('.h-full.rounded-full.transition-all');
        expect(bar.attributes('style')).toContain('width: 0%');
    });

    it('accepts different color variants', () => {
        const colors = ['primary', 'success', 'warning', 'danger', 'accent'];
        colors.forEach((color) => {
            const wrapper = mount(ProgressBar, {
                props: { label: 'Test', value: 50, color },
            });
            expect(wrapper.find('[data-testid="progress-bar"]').exists()).toBe(true);
        });
    });

    it('renders label text', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'Bandwidth', value: 80 },
        });
        expect(wrapper.text()).toContain('Bandwidth');
    });
});
