import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Breadcrumbs from '@/Components/Admin/Breadcrumbs.vue';

const mockBreadcrumbs = vi.fn(() => []);

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({
        props: {
            get breadcrumbs() {
                return mockBreadcrumbs();
            },
        },
    }),
}));

function mountBreadcrumbs(crumbs = []) {
    mockBreadcrumbs.mockReturnValue(crumbs);
    return mount(Breadcrumbs);
}

describe('Breadcrumbs.vue', () => {
    it('does not render when there are no breadcrumbs', () => {
        const wrapper = mountBreadcrumbs([]);
        expect(wrapper.find('[data-testid="breadcrumbs"]').exists()).toBe(false);
    });

    it('renders the nav element when breadcrumbs are present', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Home' }]);
        expect(wrapper.get('[data-testid="breadcrumbs"]').exists()).toBe(true);
    });

    it('has the correct container classes: font-mono, text-[13px], font-normal, text-[var(--color-text-muted)]', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Home' }]);
        const ol = wrapper.get('ol');

        expect(ol.classes()).toContain('font-mono');
        expect(ol.classes()).toContain('text-[13px]');
        expect(ol.classes()).toContain('font-normal');
        expect(ol.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('renders ancestor links with correct styling', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Admin', href: '/admin' }, { label: 'Users' }]);
        const link = wrapper.get('[data-testid="breadcrumb-link"]');

        expect(link.classes()).toContain('text-[var(--color-text-secondary)]');
        expect(link.classes()).toContain('no-underline');
        expect(link.classes()).toContain('hover:text-[var(--color-text)]');
    });

    it('renders the current page (last segment) as a span with muted text', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Admin', href: '/admin' }, { label: 'Users' }]);
        const current = wrapper.get('[data-testid="breadcrumb-current"]');

        expect(current.text()).toBe('Users');
        expect(current.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('renders separator between segments with muted text color', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Admin', href: '/admin' }, { label: 'Users' }]);
        const items = wrapper.findAll('li');
        const separator = items[1].find('span');

        expect(separator.text()).toBe('/');
        expect(separator.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('does not render a separator before the first segment', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Admin', href: '/admin' }, { label: 'Users' }]);
        const firstItem = wrapper.findAll('li')[0];
        const spans = firstItem.findAll('span');

        // The first li should not contain a separator span with '/'
        const separators = spans.filter((s) => s.text() === '/');
        expect(separators).toHaveLength(0);
    });

    it('renders multiple ancestor links correctly', () => {
        const wrapper = mountBreadcrumbs([
            { label: 'Admin', href: '/admin' },
            { label: 'Users', href: '/admin/users' },
            { label: 'Edit' },
        ]);
        const links = wrapper.findAll('[data-testid="breadcrumb-link"]');

        expect(links).toHaveLength(2);
        expect(links[0].text()).toBe('Admin');
        expect(links[0].attributes('href')).toBe('/admin');
        expect(links[1].text()).toBe('Users');
        expect(links[1].attributes('href')).toBe('/admin/users');
    });

    it('does not render any card wrappers or accent borders', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Admin', href: '/admin' }, { label: 'Users' }]);
        const nav = wrapper.get('nav');

        // No card-like wrapper classes
        expect(nav.classes().join(' ')).not.toMatch(/border-l|card|shadow|rounded/);
    });

    it('has correct aria-label for accessibility', () => {
        const wrapper = mountBreadcrumbs([{ label: 'Home' }]);
        const nav = wrapper.get('nav');

        expect(nav.attributes('aria-label')).toBe('Breadcrumb');
    });
});
