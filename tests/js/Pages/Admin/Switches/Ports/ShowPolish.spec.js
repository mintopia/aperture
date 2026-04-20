import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
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

const mountPage = (overrides = {}) =>
    mount(Show, {
        props: {
            ...defaultProps,
            ...overrides,
            switchConfig: {
                ...defaultProps.switchConfig,
                ...(overrides.switchConfig ?? {}),
            },
            port: {
                ...defaultProps.port,
                ...(overrides.port ?? {}),
            },
        },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div data-testid="metadata-strip" />' },
                SectionHeader: { template: '<div><slot /></div>' },
                StatusPill: { template: '<div data-testid="status-pill" />' },
                StatCard: { template: '<div data-testid="stat-card" />' },
                ConfigBlock: { template: '<div data-testid="config-block" />' },
                TimeSeriesChart: { template: '<div data-testid="time-series-chart" />' },
                ConnectedDevicesSummary: { template: '<div data-testid="connected-devices-summary" />' },
                // Don't stub ConfirmModal - let it render for real
                teleport: true,
            },
        },
    });

describe('Ports/Show.vue - Polish', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Error status display', () => {
        it('shows "Clean — no errors detected" when all error counts are 0', () => {
            const wrapper = mountPage({
                errors: {
                    input: 0,
                    output: 0,
                    crc: 0,
                    collisions: 0,
                },
            });

            const cleanMessage = wrapper.find('[data-testid="errors-clean"]');
            expect(cleanMessage.exists()).toBe(true);
            expect(cleanMessage.text()).toBe('Clean — no errors detected');
        });

        it('does not show clean message when any error count > 0', () => {
            const wrapper = mountPage({
                errors: {
                    input: 5,
                    output: 0,
                    crc: 0,
                    collisions: 0,
                },
            });

            const cleanMessage = wrapper.find('[data-testid="errors-clean"]');
            expect(cleanMessage.exists()).toBe(false);
        });
    });

    describe('Header control polish', () => {
        it('renders simplified header action controls only', () => {
            const wrapper = mountPage({
                port: {
                    ...defaultProps.port,
                    admin_status: 'up',
                },
            });

            const headerActions = wrapper.find('[data-testid="header-actions"]');
            expect(headerActions.exists()).toBe(true);

            const actionButtons = headerActions.findAll('button');
            expect(actionButtons).toHaveLength(2);

            expect(headerActions.find('[data-testid="action-refresh"]').exists()).toBe(true);
            expect(headerActions.find('[data-testid="action-toggle"]').exists()).toBe(true);
        });

        it('header actions keep accessible title attributes', () => {
            const wrapper = mountPage({
                port: {
                    ...defaultProps.port,
                    admin_status: 'up',
                },
            });

            const refreshButton = wrapper.find('[data-testid="action-refresh"]');
            expect(refreshButton.exists()).toBe(true);
            expect(refreshButton.attributes('title')).toBe("Sync this port's data from the switch");

            const toggleButton = wrapper.find('[data-testid="action-toggle"]');
            expect(toggleButton.exists()).toBe(true);
            expect(toggleButton.attributes('title')).toBe('Administratively disable this port');
        });

        it('toggle button has correct title when port admin_status is down and remains in header actions', () => {
            const wrapper = mountPage({
                port: {
                    ...defaultProps.port,
                    admin_status: 'down',
                },
            });

            const headerActions = wrapper.find('[data-testid="header-actions"]');
            expect(headerActions.exists()).toBe(true);

            const toggleButton = wrapper.find('[data-testid="action-toggle"]');
            expect(toggleButton.exists()).toBe(true);
            expect(toggleButton.attributes('title')).toBe('Administratively enable this port');
        });

        it('does not duplicate status pill in the header controls', () => {
            const wrapper = mountPage();
            expect(wrapper.find('[data-testid="port-status"]').exists()).toBe(false);
        });
    });

    describe('Config rendering', () => {
        it('renders both Running Config and Interface Output blocks when both are provided', () => {
            const wrapper = mountPage({
                port: {
                    ...defaultProps.port,
                    config_text: 'interface Gi1/0/1\n description Desk 42',
                    interface_output: 'GigabitEthernet1/0/1 is up, line protocol is up (connected)',
                },
            });

            const configBlocks = wrapper.findAll('[data-testid="config-block"]');
            expect(configBlocks).toHaveLength(2);
        });
    });
});
