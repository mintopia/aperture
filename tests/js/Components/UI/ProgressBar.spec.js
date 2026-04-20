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
        const bar = wrapper.find('[data-testid="progress-bar-fill"]');
        expect(bar.attributes('style')).toContain('width: 30%');
    });

    it('caps width at 100% when value exceeds max', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'CPU', value: 150, max: 100 },
        });
        const bar = wrapper.find('[data-testid="progress-bar-fill"]');
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
        const bar = wrapper.find('[data-testid="progress-bar-fill"]');
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

    it('applies Dispatch mockup track styling', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'Pool', value: 50 },
        });
        const track = wrapper.find('[data-testid="progress-bar-fill"]').element.parentElement;
        expect(track.className).toContain('h-[6px]');
        expect(track.className).toContain('rounded-[3px]');
        expect(track.className).toContain('bg-[var(--color-surface-hover)]');
        expect(track.className).toContain('overflow-hidden');
        expect(track.className).toContain('mt-[6px]');
    });

    it('applies Dispatch mockup fill styling', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'Pool', value: 50, color: 'success' },
        });
        const fill = wrapper.find('[data-testid="progress-bar-fill"]');
        expect(fill.classes()).toContain('h-full');
        expect(fill.classes()).toContain('rounded-[3px]');
        expect(fill.classes()).toContain('transition-[width]');
        expect(fill.classes()).toContain('duration-300');
    });

    it('applies semantic color classes for fill', () => {
        const colorCssMap = {
            success: 'bg-[var(--color-success)]',
            warning: 'bg-[var(--color-warning)]',
            danger: 'bg-[var(--color-danger)]',
        };
        Object.entries(colorCssMap).forEach(([color, cssClass]) => {
            const wrapper = mount(ProgressBar, {
                props: { label: 'Pool', value: 50, color },
            });
            const fill = wrapper.find('[data-testid="progress-bar-fill"]');
            expect(fill.classes()).toContain(cssClass);
        });
    });

    it('has data-testid on fill element', () => {
        const wrapper = mount(ProgressBar, {
            props: { label: 'Pool', value: 50 },
        });
        expect(wrapper.find('[data-testid="progress-bar-fill"]').exists()).toBe(true);
    });
});
