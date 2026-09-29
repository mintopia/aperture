import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import AdminLayout from '@/Layouts/AdminLayout.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: { user: { name: 'Test User' } },
        },
    }),
    router: { on: vi.fn() },
}));

vi.mock('@/Components/Admin/Breadcrumbs.vue', () => ({
    default: { name: 'Breadcrumbs', template: '<div />' },
}));

vi.mock('@/Components/ThemeToggle.vue', () => ({
    default: { name: 'ThemeToggle', template: '<div />' },
}));

vi.mock('@/Components/Admin/Sidebar.vue', () => ({
    default: { name: 'Sidebar', template: '<div />' },
}));

vi.mock('@/Components/Admin/GlobalSearch.vue', () => ({
    default: { name: 'GlobalSearch', template: '<div />' },
}));

vi.mock('@/Components/UserMenu.vue', () => ({
    default: { name: 'UserMenu', props: ['user'], template: '<div />' },
}));

vi.mock('@/Components/UI/FlashMessages.vue', () => ({
    default: { name: 'FlashMessages', template: '<div />' },
}));

describe('AdminLayout.vue', () => {
    it('renders the skip-nav link as the first child', () => {
        const wrapper = mount(AdminLayout, { slots: { default: '<p>Content</p>' } });
        const skipNav = wrapper.find('[data-testid="skip-nav"]');
        expect(skipNav.exists()).toBe(true);
        expect(skipNav.attributes('href')).toBe('#main-content');
        expect(skipNav.text()).toBe('Skip to content');
    });

    it('skip-nav is the first element inside the root element', () => {
        const wrapper = mount(AdminLayout, { slots: { default: '<p>Content</p>' } });
        const root = wrapper.find('[data-testid="admin-layout"]');
        const firstChild = root.element.children[0];
        expect(firstChild.getAttribute('data-testid')).toBe('skip-nav');
    });

    it('skip-nav has sr-only class by default', () => {
        const wrapper = mount(AdminLayout, { slots: { default: '<p>Content</p>' } });
        const skipNav = wrapper.find('[data-testid="skip-nav"]');
        expect(skipNav.classes()).toContain('sr-only');
    });

    it('main element has id="main-content"', () => {
        const wrapper = mount(AdminLayout, { slots: { default: '<p>Content</p>' } });
        const main = wrapper.find('main');
        expect(main.attributes('id')).toBe('main-content');
    });

    it('skip-nav href matches main-content id', () => {
        const wrapper = mount(AdminLayout, { slots: { default: '<p>Content</p>' } });
        const skipNav = wrapper.find('[data-testid="skip-nav"]');
        const main = wrapper.find('main');
        expect(skipNav.attributes('href')).toBe(`#${main.attributes('id')}`);
    });
});
