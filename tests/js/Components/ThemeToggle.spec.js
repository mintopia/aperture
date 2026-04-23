import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import ThemeToggle from '@/Components/ThemeToggle.vue';

const mockToggleMode = vi.fn();
let mockMode = { value: 'light' };

vi.mock('@/composables/useTheme', () => ({
    useTheme: () => ({
        get mode() {
            return mockMode.value;
        },
        toggleMode: mockToggleMode,
    }),
}));

describe('ThemeToggle', () => {
    beforeEach(() => {
        mockMode.value = 'light';
        mockToggleMode.mockClear();
    });

    function mountComponent() {
        return mount(ThemeToggle);
    }

    it('renders a button element', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('button').exists()).toBe(true);
    });

    it('has role="switch"', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('button').attributes('role')).toBe('switch');
    });

    it('has aria-checked="false" in light mode', () => {
        mockMode.value = 'light';
        const wrapper = mountComponent();
        expect(wrapper.find('button').attributes('aria-checked')).toBe('false');
    });

    it('has aria-checked="true" in dark mode', () => {
        mockMode.value = 'dark';
        const wrapper = mountComponent();
        expect(wrapper.find('button').attributes('aria-checked')).toBe('true');
    });

    it('has aria-label "Switch to dark mode" in light mode', () => {
        mockMode.value = 'light';
        const wrapper = mountComponent();
        expect(wrapper.find('button').attributes('aria-label')).toBe('Switch to dark mode');
    });

    it('has aria-label "Switch to light mode" in dark mode', () => {
        mockMode.value = 'dark';
        const wrapper = mountComponent();
        expect(wrapper.find('button').attributes('aria-label')).toBe('Switch to light mode');
    });

    it('calls toggleMode on click', async () => {
        const wrapper = mountComponent();
        await wrapper.find('button').trigger('click');
        expect(mockToggleMode).toHaveBeenCalledTimes(1);
    });

    it('shows sun icon in dark mode', () => {
        mockMode.value = 'dark';
        const wrapper = mountComponent();
        const svgs = wrapper.findAll('svg');
        expect(svgs.length).toBeGreaterThan(0);
        // Sun icon has a circle element
        expect(wrapper.find('circle').exists()).toBe(true);
    });

    it('shows moon icon in light mode', () => {
        mockMode.value = 'light';
        const wrapper = mountComponent();
        // Moon icon uses a path, no circle
        expect(wrapper.find('circle').exists()).toBe(false);
        expect(wrapper.find('path').exists()).toBe(true);
    });

    it('has a title attribute matching the aria-label', () => {
        mockMode.value = 'light';
        const wrapper = mountComponent();
        const btn = wrapper.find('button');
        expect(btn.attributes('title')).toBe(btn.attributes('aria-label'));
    });
});
