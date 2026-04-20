import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import AlertBanner from '@/Components/UI/AlertBanner.vue';

describe('AlertBanner', () => {
    it('renders with default type', () => {
        const wrapper = mount(AlertBanner, {
            slots: { default: 'Warning message' },
        });
        expect(wrapper.find('[data-testid="alert-banner"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Warning message');
    });

    it('has data-testid attribute', () => {
        const wrapper = mount(AlertBanner, {
            slots: { default: 'Test' },
        });
        expect(wrapper.find('[data-testid="alert-banner"]').exists()).toBe(true);
    });

    it('renders warning type', () => {
        const wrapper = mount(AlertBanner, {
            props: { type: 'warning' },
            slots: { default: 'Careful!' },
        });
        const banner = wrapper.find('[data-testid="alert-banner"]');
        expect(banner.exists()).toBe(true);
        expect(wrapper.text()).toContain('Careful!');
    });

    it('renders info type', () => {
        const wrapper = mount(AlertBanner, {
            props: { type: 'info' },
            slots: { default: 'FYI' },
        });
        expect(wrapper.find('[data-testid="alert-banner"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('FYI');
    });

    it('renders danger type', () => {
        const wrapper = mount(AlertBanner, {
            props: { type: 'danger' },
            slots: { default: 'Error occurred' },
        });
        expect(wrapper.find('[data-testid="alert-banner"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Error occurred');
    });

    it('renders success type', () => {
        const wrapper = mount(AlertBanner, {
            props: { type: 'success' },
            slots: { default: 'All good!' },
        });
        expect(wrapper.find('[data-testid="alert-banner"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('All good!');
    });

    it('renders slot content', () => {
        const wrapper = mount(AlertBanner, {
            slots: { default: '<strong>Bold alert</strong>' },
        });
        expect(wrapper.find('strong').exists()).toBe(true);
        expect(wrapper.find('strong').text()).toBe('Bold alert');
    });

    it('uses full border without left stripe', () => {
        const wrapper = mount(AlertBanner, {
            props: { type: 'warning' },
            slots: { default: 'Test' },
        });
        const banner = wrapper.find('[data-testid="alert-banner"]');
        expect(banner.classes()).toContain('border');
        expect(banner.classes()).not.toContain('border-l-[3px]');
        expect(banner.classes()).not.toContain('border-l-2');
        expect(banner.classes()).not.toContain('border-l-4');
    });

    it('uses rounded border-radius and 16px padding', () => {
        const wrapper = mount(AlertBanner, {
            props: { type: 'info' },
            slots: { default: 'Test' },
        });
        const banner = wrapper.find('[data-testid="alert-banner"]');
        expect(banner.classes()).toContain('rounded');
        expect(banner.classes()).not.toContain('rounded-lg');
        expect(banner.classes()).toContain('p-4');
    });
});
