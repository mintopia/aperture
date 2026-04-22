import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '@/Pages/Admin/Content/Pages/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

const defaultPages = [
    {
        id: 1,
        title: 'About Us',
        slug: 'about-us',
        updated_at: '2026-04-20T10:00:00Z',
    },
    {
        id: 2,
        title: 'Contact',
        slug: 'contact',
        updated_at: '2026-04-18T08:30:00Z',
    },
];

describe('Pages/Index', () => {
    function mountIndex(pages = defaultPages) {
        return mount(Index, {
            props: { pages },
            global: {
                mocks: {
                    route: (name, params) => {
                        if (name === 'admin.content.pages.create') return '/admin/content/pages/create';
                        if (name === 'admin.content.pages.edit') return `/admin/content/pages/${params}/edit`;
                        return `/mocked/${name}`;
                    },
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });
    }

    it('renders page heading "Pages"', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('h1').text()).toBe('Pages');
    });

    it('renders the pages-index container', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="pages-index"]').exists()).toBe(true);
    });

    it('renders page list with titles', () => {
        const wrapper = mountIndex();
        const titles = wrapper.findAll('[data-testid="page-title"]');
        expect(titles).toHaveLength(2);
        expect(titles[0].text()).toBe('About Us');
        expect(titles[1].text()).toBe('Contact');
    });

    it('shows slug with /content/ prefix in monospace', () => {
        const wrapper = mountIndex();
        const rows = wrapper.findAll('[data-testid^="page-row-"]');
        expect(rows).toHaveLength(2);
        const firstRow = wrapper.find('[data-testid="page-row-about-us"]');
        expect(firstRow.exists()).toBe(true);
        expect(firstRow.text()).toContain('/content/about-us');
    });

    it('shows empty state when no pages', () => {
        const wrapper = mountIndex([]);
        expect(wrapper.find('[data-testid="pages-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pages-table"]').exists()).toBe(false);
    });

    it('shows table when pages exist', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="pages-table"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="pages-empty"]').exists()).toBe(false);
    });

    it('has new page button with data-testid="action-new-page"', () => {
        const wrapper = mountIndex();
        const btn = wrapper.find('[data-testid="action-new-page"]');
        expect(btn.exists()).toBe(true);
        expect(btn.text()).toContain('New Page');
    });

    it('new page button links to create route', () => {
        const wrapper = mountIndex();
        const btn = wrapper.find('[data-testid="action-new-page"]');
        expect(btn.attributes('href')).toBe('/admin/content/pages/create');
    });

    it('each row links to edit page', () => {
        const wrapper = mountIndex();
        const row = wrapper.find('[data-testid="page-row-about-us"]');
        expect(row.attributes('href')).toBe('/admin/content/pages/1/edit');
    });
});
