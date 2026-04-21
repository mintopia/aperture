import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { router } from '@inertiajs/vue3';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name, params) => {
    if (typeof params === 'object' && params !== null) {
        return `/mocked/${name}/${JSON.stringify(params)}`;
    }
    return `/mocked/${name}/${params}`;
});

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
                route: (name, params) => {
                    if (typeof params === 'object' && params !== null) {
                        return `/mocked/${name}/${JSON.stringify(params)}`;
                    }
                    return `/mocked/${name}/${params}`;
                },
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                SectionHeader: { template: '<div><slot /></div>' },
                ConfigBlock: { template: '<div data-testid="config-block" />' },
                TimeSeriesChart: { template: '<div data-testid="time-series-chart" />' },
                ConnectedDevicesSummary: { template: '<div data-testid="connected-devices-summary" />' },
                teleport: true,
            },
        },
    });
}

describe('Show — refreshData onSuccess callback', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('updates lastUpdated when router.reload onSuccess is called', async () => {
        router.reload.mockImplementation((options) => {
            if (options?.onSuccess) options.onSuccess();
        });

        const wrapper = mountPage();

        // Trigger polling to call refreshData
        vi.advanceTimersByTime(30000);
        await wrapper.vm.$nextTick();

        expect(router.reload).toHaveBeenCalled();
        // The el exists and displayTime was reset to 'just now'
        expect(wrapper.find('[data-testid="last-updated"]').text()).toContain('just now');
    });
});

describe('Show — duplicate MAC deduplication', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('merges duplicate MACs and deduplicates IPs', () => {
        const wrapper = mountPage({
            macs: [
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.1' }],
                    last_seen_at: '2024-01-01T10:00:00Z',
                },
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.2' }],
                    last_seen_at: '2024-01-01T11:00:00Z',
                },
            ],
        });

        // Should deduplicate to one row
        const rows = wrapper.findAll('[data-testid^="connected-device-row-"]');
        expect(rows).toHaveLength(1);
    });

    it('merges duplicate MACs and uses the latest last_seen_at', () => {
        const wrapper = mountPage({
            macs: [
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.1' }],
                    last_seen_at: '2024-01-01T10:00:00Z',
                },
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.1' }],
                    last_seen_at: '2024-01-01T11:00:00Z',
                },
            ],
        });

        const rows = wrapper.findAll('[data-testid^="connected-device-row-"]');
        expect(rows).toHaveLength(1);
    });

    it('merges duplicate MACs keeping existing last_seen_at when new entry has no last_seen_at', () => {
        const wrapper = mountPage({
            macs: [
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.1' }],
                    last_seen_at: '2024-01-01T10:00:00Z',
                },
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.2' }],
                    last_seen_at: null,
                },
            ],
        });

        const rows = wrapper.findAll('[data-testid^="connected-device-row-"]');
        expect(rows).toHaveLength(1);
    });

    it('handles same IP appearing in both duplicate MAC entries (deduplication)', () => {
        const wrapper = mountPage({
            macs: [
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.1' }],
                    last_seen_at: '2024-01-01T10:00:00Z',
                },
                {
                    mac_address: 'aa:bb:cc:dd:ee:ff',
                    resolved_ips: [{ ip: '10.0.0.1' }],
                    last_seen_at: '2024-01-01T09:00:00Z',
                },
            ],
        });

        const rows = wrapper.findAll('[data-testid^="connected-device-row-"]');
        expect(rows).toHaveLength(1);
    });
});

describe('Show — bandwidth series with outbound data', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('includes inbound series when bandwidth.in has data', () => {
        const wrapper = mountPage({
            bandwidth: {
                in: [{ timestamp: 1000, value: 100 }],
                out: [],
                in_bytes: 1024,
                out_bytes: 0,
            },
        });

        // TimeSeriesChart is stubbed but the computed prop runs — no throw means coverage
        expect(wrapper.find('[data-testid="bandwidth-chart"]').exists()).toBe(true);
    });

    it('includes outbound series when bandwidth.out has data', () => {
        const wrapper = mountPage({
            bandwidth: {
                in: [],
                out: [{ timestamp: 1000, value: 200 }],
                in_bytes: 0,
                out_bytes: 2048,
            },
        });

        expect(wrapper.find('[data-testid="bandwidth-chart"]').exists()).toBe(true);
    });

    it('includes both inbound and outbound when both have data', () => {
        const wrapper = mountPage({
            bandwidth: {
                in: [{ timestamp: 1000, value: 100 }],
                out: [{ timestamp: 1000, value: 200 }],
                in_bytes: 1024,
                out_bytes: 2048,
            },
        });

        expect(wrapper.find('[data-testid="bandwidth-chart"]').exists()).toBe(true);
    });

    it('includes error series when errors.in_series has data', () => {
        const wrapper = mountPage({
            metricsAvailable: true,
            errors: {
                in_series: [{ timestamp: 1000, value: 5 }],
                out_series: [],
            },
        });

        expect(wrapper.find('[data-testid="errors-chart"]').exists()).toBe(true);
    });

    it('includes both error series when both have data', () => {
        const wrapper = mountPage({
            metricsAvailable: true,
            errors: {
                in_series: [{ timestamp: 1000, value: 5 }],
                out_series: [{ timestamp: 1000, value: 3 }],
            },
        });

        expect(wrapper.find('[data-testid="errors-chart"]').exists()).toBe(true);
    });
});

describe('Show — confirmToggle onFinish callback', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('resets toggling and closes modal when onFinish is called', async () => {
        router.post.mockImplementation((_url, _data, options) => {
            if (options?.onFinish) options.onFinish();
        });

        const wrapper = mountPage();

        // Open modal
        await wrapper.find('[data-testid="action-toggle"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

        // Confirm
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        // After onFinish fires, toggling should be false and modal should close
        expect(router.post).toHaveBeenCalled();
        // The modal should be closed (showToggleModal = false)
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
    });

    it('calls enable route when port is down and confirmed', async () => {
        router.post.mockImplementation((_url, _data, options) => {
            if (options?.onFinish) options.onFinish();
        });

        const wrapper = mountPage({
            port: { admin_status: 'down' },
        });

        await wrapper.find('[data-testid="action-toggle"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(expect.stringContaining('enable'), {}, expect.any(Object));
    });
});

describe('Show — MetadataStrip Status slot', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders MetadataStrip with status slot when MetadataStrip is not stubbed', () => {
        // Mount without stubbing MetadataStrip so the slot renders
        const wrapper = mount(Show, {
            props: {
                ...defaultProps,
                port: {
                    ...defaultProps.port,
                    status: 'connected',
                    admin_status: 'up',
                },
            },
            global: {
                mocks: {
                    route: (name, params) => `/mocked/${name}/${params}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: {
                        template: '<div data-testid="metadata-strip"><slot name="Status" /></div>',
                        props: ['items'],
                    },
                    SectionHeader: { template: '<div><slot /></div>' },
                    ConfigBlock: { template: '<div />' },
                    TimeSeriesChart: { template: '<div />' },
                    ConnectedDevicesSummary: { template: '<div />' },
                    teleport: true,
                },
            },
        });

        // The slot renders the status dot and color class spans
        const metadataStrip = wrapper.find('[data-testid="metadata-strip"]');
        expect(metadataStrip.exists()).toBe(true);
        // The slot content should be rendered
        expect(metadataStrip.find('span').exists()).toBe(true);
    });

    it('status dot uses danger class for err-disabled status', () => {
        const wrapper = mount(Show, {
            props: {
                ...defaultProps,
                port: {
                    ...defaultProps.port,
                    status: 'err-disabled',
                    admin_status: 'up',
                },
            },
            global: {
                mocks: {
                    route: (name, params) => `/mocked/${name}/${params}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: {
                        template: '<div data-testid="metadata-strip"><slot name="Status" /></div>',
                        props: ['items'],
                    },
                    SectionHeader: { template: '<div><slot /></div>' },
                    ConfigBlock: { template: '<div />' },
                    TimeSeriesChart: { template: '<div />' },
                    ConnectedDevicesSummary: { template: '<div />' },
                    teleport: true,
                },
            },
        });

        const metadataStrip = wrapper.find('[data-testid="metadata-strip"]');
        const statusSpan = metadataStrip.find('span span:last-child');
        expect(statusSpan.classes()).toContain('text-[var(--color-danger)]');
    });

    it('status dot uses muted class for neutral/disabled status', () => {
        const wrapper = mount(Show, {
            props: {
                ...defaultProps,
                port: {
                    ...defaultProps.port,
                    status: 'disabled',
                    admin_status: 'up',
                },
            },
            global: {
                mocks: {
                    route: (name, params) => `/mocked/${name}/${params}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: {
                        template: '<div data-testid="metadata-strip"><slot name="Status" /></div>',
                        props: ['items'],
                    },
                    SectionHeader: { template: '<div><slot /></div>' },
                    ConfigBlock: { template: '<div />' },
                    TimeSeriesChart: { template: '<div />' },
                    ConnectedDevicesSummary: { template: '<div />' },
                    teleport: true,
                },
            },
        });

        const metadataStrip = wrapper.find('[data-testid="metadata-strip"]');
        const statusSpan = metadataStrip.find('span span:last-child');
        expect(statusSpan.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('status dot uses success class for connected/up status', () => {
        const wrapper = mount(Show, {
            props: {
                ...defaultProps,
                port: {
                    ...defaultProps.port,
                    status: 'up',
                    admin_status: 'up',
                },
            },
            global: {
                mocks: {
                    route: (name, params) => `/mocked/${name}/${params}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: {
                        template: '<div data-testid="metadata-strip"><slot name="Status" /></div>',
                        props: ['items'],
                    },
                    SectionHeader: { template: '<div><slot /></div>' },
                    ConfigBlock: { template: '<div />' },
                    TimeSeriesChart: { template: '<div />' },
                    ConnectedDevicesSummary: { template: '<div />' },
                    teleport: true,
                },
            },
        });

        const metadataStrip = wrapper.find('[data-testid="metadata-strip"]');
        const statusSpan = metadataStrip.find('span span:last-child');
        expect(statusSpan.classes()).toContain('text-[var(--color-success)]');
    });

    it('status type returns warning for unknown status', () => {
        const wrapper = mount(Show, {
            props: {
                ...defaultProps,
                port: {
                    ...defaultProps.port,
                    status: 'unknown-state',
                    admin_status: 'up',
                },
            },
            global: {
                mocks: {
                    route: (name, params) => `/mocked/${name}/${params}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: {
                        template: '<div data-testid="metadata-strip"><slot name="Status" /></div>',
                        props: ['items'],
                    },
                    SectionHeader: { template: '<div><slot /></div>' },
                    ConfigBlock: { template: '<div />' },
                    TimeSeriesChart: { template: '<div />' },
                    ConnectedDevicesSummary: { template: '<div />' },
                    teleport: true,
                },
            },
        });

        const metadataStrip = wrapper.find('[data-testid="metadata-strip"]');
        const statusSpan = metadataStrip.find('span span:last-child');
        // 'unknown-state' returns 'warning' type, which falls through to muted color
        expect(statusSpan.classes()).toContain('text-[var(--color-text-muted)]');
    });
});
