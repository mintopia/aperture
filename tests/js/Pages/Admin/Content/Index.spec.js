import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Index from '@/Pages/Admin/Content/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

vi.stubGlobal('route', (name) => `/mocked/${name}`);

const routeMock = (name) => `/mocked/${name}`;

const defaultGlobal = {
    stubs: {
        AdminLayout: { template: '<div><slot /></div>' },
    },
    mocks: {
        route: routeMock,
    },
};

describe('Admin Content Index', () => {
    const defaultProps = {
        blocks: [
            {
                id: 1,
                type: 'event_info',
                title: 'Welcome',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
            },
            {
                id: 2,
                type: 'bandwidth',
                title: 'Bandwidth',
                grid_col: 2,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
            },
        ],
        singletonTypes: ['bandwidth', 'connection_strip', 'network_stats', 'dns_filter', 'connection_status'],
        existingTypes: ['event_info', 'bandwidth'],
    };

    it('renders the page title', () => {
        const wrapper = mount(Index, { props: defaultProps, global: defaultGlobal });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Content Blocks');
    });

    it('renders blocks in a table', () => {
        const wrapper = mount(Index, { props: defaultProps, global: defaultGlobal });
        expect(wrapper.text()).toContain('Welcome');
        expect(wrapper.text()).toContain('Bandwidth');
    });

    it('has an add block button', () => {
        const wrapper = mount(Index, { props: defaultProps, global: defaultGlobal });
        expect(wrapper.find('[data-testid="action-add-block"]').exists()).toBe(true);
    });

    it('has a link to the grid editor', () => {
        const wrapper = mount(Index, { props: defaultProps, global: defaultGlobal });
        expect(wrapper.find('[data-testid="link-grid-editor"]').exists()).toBe(true);
    });

    it('has delete buttons per block', () => {
        const wrapper = mount(Index, { props: defaultProps, global: defaultGlobal });
        const deleteButtons = wrapper.findAll('[data-testid^="action-delete-"]');
        expect(deleteButtons.length).toBe(2);
    });

    it('has active toggles per block', () => {
        const wrapper = mount(Index, { props: defaultProps, global: defaultGlobal });
        const toggles = wrapper.findAll('[data-testid^="toggle-active-"]');
        expect(toggles.length).toBe(2);
    });
});
