import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { router } from '@inertiajs/vue3';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name) => `/mocked/${name}`);

describe('Admin Switch Port Show confirmation modal integration', () => {
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
        macs: [
            {
                mac_address: 'AA:BB:CC:DD:EE:01',
                vlan: '10',
                last_seen_at: '2024-01-01T00:00:00Z',
                resolved_ips: [{ ip: '10.0.0.1', user: { id: 1, nickname: 'Alice' } }],
            },
            {
                mac_address: 'AA:BB:CC:DD:EE:02',
                vlan: '10',
                last_seen_at: '2024-01-01T00:00:00Z',
                resolved_ips: [{ ip: '10.0.0.2', user: null }],
            },
        ],
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
                    teleport: true,
                },
            },
        });

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows shutdown confirmation modal when clicking shutdown on an up port', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Shut Down');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('canceling shutdown confirmation closes modal without posting', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('shows enable confirmation modal when clicking unshut on a down port', async () => {
        const wrapper = mountPage({
            port: {
                admin_status: 'down',
            },
        });

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toMatch(/enable|unshut/i);
        expect(wrapper.find('[data-testid="confirm-modal-message"]').text()).toMatch(/enable|unshut|restore/i);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('modal lists connected device names for toggle confirmation', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');

        const modal = wrapper.find('[data-testid="confirm-modal"]');
        expect(modal.exists()).toBe(true);
        expect(modal.text()).toContain('Alice');
    });

    it('modal lists MAC addresses for devices without users during toggle confirmation', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');

        const modal = wrapper.find('[data-testid="confirm-modal"]');
        expect(modal.exists()).toBe(true);
        expect(modal.text()).toContain('AA:BB:CC:DD:EE:02');
    });

    it('confirms shutdown action triggers router.post', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(expect.stringContaining('shutdown'), {}, expect.any(Object));
    });

    it('confirms enable action triggers router.post after confirmation', async () => {
        const wrapper = mountPage({
            port: {
                admin_status: 'down',
            },
        });

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(expect.stringContaining('enable'), {}, expect.any(Object));
    });
});
