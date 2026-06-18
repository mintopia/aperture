import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import PortalLayout from '@/Layouts/PortalLayout.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({
        props: {
            auth: { user: { name: 'Test User' } },
            footer: null,
        },
    }),
}));

vi.mock('@/Components/AppLogo.vue', () => ({
    default: { name: 'AppLogo', template: '<div />' },
}));

vi.mock('@/Components/ThemeToggle.vue', () => ({
    default: { name: 'ThemeToggle', template: '<div />' },
}));

vi.mock('@/Components/UserMenu.vue', () => ({
    default: { name: 'UserMenu', props: ['user'], template: '<div />' },
}));

const mockRoute = (name) => `/${name.replace(/\./g, '/')}`;

function mountPortalLayout(slots = { default: '<p>Content</p>' }) {
    return mount(PortalLayout, {
        slots,
        global: {
            config: {
                globalProperties: {
                    route: mockRoute,
                },
            },
        },
    });
}

describe('PortalLayout.vue', () => {
    it('renders the skip-nav link as the first child', () => {
        const wrapper = mountPortalLayout();
        const skipNav = wrapper.find('[data-testid="skip-nav"]');
        expect(skipNav.exists()).toBe(true);
        expect(skipNav.attributes('href')).toBe('#main-content');
        expect(skipNav.text()).toBe('Skip to content');
    });

    it('skip-nav is the first element inside the root element', () => {
        const wrapper = mountPortalLayout();
        const root = wrapper.find('[data-testid="portal-layout"]');
        const firstChild = root.element.children[0];
        expect(firstChild.getAttribute('data-testid')).toBe('skip-nav');
    });

    it('skip-nav has sr-only class by default', () => {
        const wrapper = mountPortalLayout();
        const skipNav = wrapper.find('[data-testid="skip-nav"]');
        expect(skipNav.classes()).toContain('sr-only');
    });

    it('main element has id="main-content"', () => {
        const wrapper = mountPortalLayout();
        const main = wrapper.find('main');
        expect(main.attributes('id')).toBe('main-content');
    });

    it('skip-nav href matches main-content id', () => {
        const wrapper = mountPortalLayout();
        const skipNav = wrapper.find('[data-testid="skip-nav"]');
        const main = wrapper.find('main');
        expect(skipNav.attributes('href')).toBe(`#${main.attributes('id')}`);
    });
});
