import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AppLogo from '@/Components/AppLogo.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { appName: 'My Network' } }),
}));

describe('AppLogo', () => {
    it('renders site title from page props', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.text()).toBe('My Network');
    });

    it('does not render an SVG icon', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.find('svg').exists()).toBe(false);
    });

    it('has data-testid', () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.find('[data-testid="app-logo"]').exists()).toBe(true);
    });
});
