import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import Pagination from '@/Components/UI/Pagination.vue';

const stubs = { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } };

const makePaginator = (overrides = {}) => ({
    current_page: 1,
    last_page: 3,
    from: 1,
    to: 15,
    total: 45,
    links: [
        { label: '&laquo; Previous', url: null, active: false },
        { label: '1', url: '/page/1', active: true },
        { label: '2', url: '/page/2', active: false },
        { label: '3', url: '/page/3', active: false },
        { label: 'Next &raquo;', url: '/page/2', active: false },
    ],
    ...overrides,
});

describe('Pagination', () => {
    it('renders when last_page > 1', () => {
        const wrapper = mount(Pagination, {
            props: { paginator: makePaginator() },
            global: { stubs },
        });
        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(true);
    });

    it('does not render when last_page is 1', () => {
        const wrapper = mount(Pagination, {
            props: { paginator: makePaginator({ last_page: 1 }) },
            global: { stubs },
        });
        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(false);
    });

    it('shows pagination info text', () => {
        const wrapper = mount(Pagination, {
            props: { paginator: makePaginator() },
            global: { stubs },
        });
        const info = wrapper.find('[data-testid="pagination-info"]');
        expect(info.text()).toContain('1');
        expect(info.text()).toContain('15');
        expect(info.text()).toContain('45');
    });

    it('renders pagination links for pages with urls', () => {
        const wrapper = mount(Pagination, {
            props: { paginator: makePaginator() },
            global: { stubs },
        });
        const links = wrapper.findAll('[data-testid="pagination-link"]');
        expect(links.length).toBeGreaterThanOrEqual(3);
    });

    it('renders disabled items as spans for links without urls', () => {
        const wrapper = mount(Pagination, {
            props: { paginator: makePaginator() },
            global: { stubs },
        });
        // "Previous" has no url so it should be a span, not a Link
        const spans = wrapper.findAll('span');
        expect(spans.length).toBeGreaterThanOrEqual(1);
    });

    it('applies active style to current page link', () => {
        const wrapper = mount(Pagination, {
            props: { paginator: makePaginator() },
            global: { stubs },
        });
        const links = wrapper.findAll('[data-testid="pagination-link"]');
        const activePage = links.find((l) => l.text() === '1');
        expect(activePage).toBeDefined();
        expect(activePage.classes()).toContain('font-semibold');
    });
});
