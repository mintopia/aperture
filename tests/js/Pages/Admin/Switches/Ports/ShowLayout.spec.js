import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name) => `/mocked/${name}`);

const defaultProps = {
    switchConfig: { id: 1, name: 'sw-core', type: 'access' },
    port: {
        interface: 'Gi1/0/1',
        admin_status: 'up',
        status: 'connected',
        speed: '1G',
        vlan: '10',
        poe: 'on',
        description: 'Desk 42',
        last_synced_at: '2024-01-01T00:00:00Z',
    },
    macs: [],
    bandwidth: {},
    errors: {},
    metricsAvailable: false,
    prevPort: null,
    nextPort: null,
};

function mountPage(overrides = {}) {
    return mount(Show, {
        props: {
            ...defaultProps,
            ...overrides,
        },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div><slot /></div>' },
                SectionHeader: { template: '<div><slot /></div>' },
                StatusPill: { template: '<div />' },
                ConfigBlock: { template: '<div />' },
                TimeSeriesChart: { template: '<div />' },
                ConnectedDevicesSummary: { template: '<div />' },
                teleport: true,
            },
        },
    });
}

describe('Show — layout contract', () => {
    it('renders explicit left/right layout containers', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="layout-columns"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-column-left"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-column-right"]').exists()).toBe(true);
    });

    it('renders section blocks in their designated columns', () => {
        const wrapper = mountPage();

        const leftColumn = wrapper.find('[data-testid="layout-column-left"]');
        const rightColumn = wrapper.find('[data-testid="layout-column-right"]');

        expect(leftColumn.find('[data-testid="section-connected-devices"]').exists()).toBe(true);
        expect(leftColumn.find('[data-testid="section-interface-output"]').exists()).toBe(true);

        expect(rightColumn.find('[data-testid="section-bandwidth"]').exists()).toBe(true);
        expect(rightColumn.find('[data-testid="section-errors"]').exists()).toBe(true);
        expect(rightColumn.find('[data-testid="section-interface-config"]').exists()).toBe(true);
    });

    it('uses a wider left column than right on xl screens', () => {
        const wrapper = mountPage();

        const columns = wrapper.find('[data-testid="layout-columns"]');
        expect(columns.classes()).toContain('xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]');
    });

    it('orders sections as requested within each column', () => {
        const wrapper = mountPage();
        const leftIds = wrapper
            .find('[data-testid="layout-column-left"]')
            .findAll(':scope > div')
            .map((node) => node.attributes('data-testid'));
        const rightIds = wrapper
            .find('[data-testid="layout-column-right"]')
            .findAll(':scope > div')
            .map((node) => node.attributes('data-testid'));

        expect(leftIds).toEqual(['section-connected-devices', 'section-interface-output']);
        expect(rightIds).toEqual(['section-bandwidth', 'section-errors', 'section-interface-config']);
    });

    it('keeps layout rows stacked on mobile while defining desktop column splits', () => {
        const wrapper = mountPage();

        const columns = wrapper.find('[data-testid="layout-columns"]');

        expect(columns.exists()).toBe(true);
        expect(columns.classes()).toContain('grid-cols-1');
        expect(columns.classes().some((klass) => klass.startsWith('xl:grid-cols-'))).toBe(true);
    });

    it('shows state-aware toggle action labels explicitly (Shut / Unshut)', () => {
        const upWrapper = mountPage({
            port: {
                ...defaultProps.port,
                admin_status: 'up',
            },
        });
        expect(upWrapper.find('[data-testid="action-toggle"]').isVisible()).toBe(true);
        expect(upWrapper.find('[data-testid="action-toggle"]').text()).toBe('Shut');

        const downWrapper = mountPage({
            port: {
                ...defaultProps.port,
                admin_status: 'down',
            },
        });
        expect(downWrapper.find('[data-testid="action-toggle"]').isVisible()).toBe(true);
        expect(downWrapper.find('[data-testid="action-toggle"]').text()).toBe('Unshut');
    });

    it('shows interface config in an always-expanded block (no collapse toggle)', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="running-config-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="interface-config-collapsible"]').exists()).toBe(false);
    });
});
