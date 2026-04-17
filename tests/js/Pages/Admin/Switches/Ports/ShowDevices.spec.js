import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a :href="href" :data-testid="$attrs[\'data-testid\']"><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.mock('@/utils/dates', () => ({
    formatRelative: vi.fn((v) => `relative(${v})`),
}));

vi.mock('@/utils/switches', () => ({
    formatPortStatus: vi.fn((admin, status) => status ?? '—'),
    typeLabel: vi.fn((v) => v),
    formatSpeed: vi.fn((v) => v ?? '—'),
    formatDuplex: vi.fn((v) => v ?? '—'),
    formatVlan: vi.fn((vlan) => `${vlan}`),
}));

vi.mock('@/helpers.js', () => ({
    formatBytes: vi.fn((v) => `${v} B`),
}));

const defaultProps = {
    switchConfig: {
        id: 1,
        name: 'Core Switch',
        hostname: '10.0.0.1',
    },
    port: {
        id: 1,
        interface: 'Gi0/1',
        description: 'User Port',
        status: 'up',
        admin_status: 'up',
        speed: '1000',
        vlan: 100,
        poe: 'on',
        config_text: null,
    },
    macs: [],
    bandwidth: { in: [], out: [], in_bytes: 0, out_bytes: 0 },
    errors: { input: 0, output: 0, crc: 0, collisions: 0, in_series: [], out_series: [] },
    metricsAvailable: false,
};

function mountShow(propsOverride = {}) {
    return mount(Show, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            mocks: {
                route: (name, params) =>
                    `/mocked/${name}/${typeof params === 'object' ? JSON.stringify(params) : params}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div />', props: ['items'] },
                SectionHeader: {
                    template: '<div data-testid="section-header">{{ title }}<slot /></div>',
                    props: ['title', 'accentLine'],
                },
                StatusPill: {
                    template: '<span data-testid="status-pill" :data-status="status">{{ label }}</span>',
                    props: ['status', 'label'],
                },
                StatCard: { template: '<div />', props: ['label', 'value', 'color'] },
                ConfigBlock: { template: '<div />', props: ['code'] },
                TimeSeriesChart: {
                    template: '<div />',
                    props: ['series', 'yAxisLabel', 'height', 'emptyMessage'],
                },
                teleport: true,
            },
        },
    });
}

describe('Show — Connected Devices sidebar', () => {
    it('renders "Connected Devices (N)" with correct count', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.42', hostname: 'test', user: { id: 1, nickname: 'NeonGamer42' } }],
                },
                {
                    mac_address: '11:22:33:44:55:66',
                    vlan: 200,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [],
                },
            ],
        });

        const section = wrapper.find('[data-testid="connected-devices-section"]');
        expect(section.exists()).toBe(true);
        expect(section.text()).toContain('Connected Devices (2)');
    });

    it('renders user name as link when user exists', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.42', hostname: 'neon-pc', user: { id: 7, nickname: 'NeonGamer42' } }],
                },
            ],
        });

        const link = wrapper.find('[data-testid="device-user-link-0"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('NeonGamer42');
        expect(link.attributes('href')).toContain('admin.users.show');
    });

    it('renders MAC as title when no user', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: '00:1A:2B:3C:4D:5E',
                    vlan: 200,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.55', hostname: null, user: null }],
                },
            ],
        });

        const card = wrapper.find('[data-testid="connected-device-0"]');
        expect(card.exists()).toBe(true);
        expect(card.text()).toContain('00:1A:2B:3C:4D:5E');

        const link = wrapper.find('[data-testid="device-user-link-0"]');
        expect(link.exists()).toBe(false);
    });

    it('shows "Allowed" badge when user exists', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.42', hostname: 'neon-pc', user: { id: 1, nickname: 'NeonGamer42' } }],
                },
            ],
        });

        const status = wrapper.find('[data-testid="device-status-0"]');
        expect(status.exists()).toBe(true);
        const pill = status.find('[data-testid="status-pill"]');
        expect(pill.text()).toBe('Allowed');
        expect(pill.attributes('data-status')).toBe('success');
    });

    it('shows "Unknown Device" badge when IP exists but no user', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: '00:1A:2B:3C:4D:5E',
                    vlan: 200,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.55', hostname: null, user: null }],
                },
            ],
        });

        const status = wrapper.find('[data-testid="device-status-0"]');
        expect(status.exists()).toBe(true);
        const pill = status.find('[data-testid="status-pill"]');
        expect(pill.text()).toBe('Unknown Device');
        expect(pill.attributes('data-status')).toBe('warning');
    });

    it('shows "Infrastructure" badge when no IP resolved', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: '00:1A:2B:FF:FE:01',
                    vlan: 1,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [],
                },
            ],
        });

        const status = wrapper.find('[data-testid="device-status-0"]');
        expect(status.exists()).toBe(true);
        const pill = status.find('[data-testid="status-pill"]');
        expect(pill.text()).toBe('Infrastructure');
        expect(pill.attributes('data-status')).toBe('neutral');
    });

    it('shows IP and MAC in device card', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [
                        { id: 42, ip: '10.0.1.42', hostname: 'neon-pc', user: { id: 1, nickname: 'NeonGamer42' } },
                    ],
                },
            ],
        });

        const card = wrapper.find('[data-testid="connected-device-0"]');
        expect(card.text()).toContain('10.0.1.42');
        expect(card.text()).toContain('AA:BB:CC:DD:EE:FF');
    });

    it('links resolved IPs to the IP detail page when an id is present', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [
                        { id: 42, ip: '10.0.1.42', hostname: 'neon-pc', user: { id: 1, nickname: 'NeonGamer42' } },
                    ],
                },
            ],
        });

        const link = wrapper.find('[data-testid="device-ip-link-0-0"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('10.0.1.42');
        expect(link.attributes('href')).toContain('admin.ips.show');
        expect(link.attributes('href')).toContain('42');
    });

    it('shows VLAN in device card', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [],
                },
            ],
        });

        const card = wrapper.find('[data-testid="connected-device-0"]');
        expect(card.text()).toContain('VLAN 100');
    });

    it('shows "No devices connected" when macs is empty', () => {
        const wrapper = mountShow({ macs: [] });

        const section = wrapper.find('[data-testid="connected-devices-section"]');
        expect(section.exists()).toBe(true);
        expect(section.text()).toContain('No devices connected');
    });

    it('shows last seen time for each device', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [],
                },
            ],
        });

        const card = wrapper.find('[data-testid="connected-device-0"]');
        expect(card.text()).toContain('Last seen relative(2024-01-01T00:00:00Z)');
    });

    it('renders multiple device cards with correct indices', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.42', hostname: null, user: { id: 1, nickname: 'User1' } }],
                },
                {
                    mac_address: '11:22:33:44:55:66',
                    vlan: 200,
                    last_seen_at: '2024-01-02T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.55', hostname: null, user: null }],
                },
                {
                    mac_address: 'FF:EE:DD:CC:BB:AA',
                    vlan: 1,
                    last_seen_at: '2024-01-03T00:00:00Z',
                    resolved_ips: [],
                },
            ],
        });

        expect(wrapper.find('[data-testid="connected-device-0"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="connected-device-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="connected-device-2"]').exists()).toBe(true);

        // First: user → "Allowed"
        expect(wrapper.find('[data-testid="device-status-0"] [data-testid="status-pill"]').text()).toBe('Allowed');
        // Second: IP but no user → "Unknown Device"
        expect(wrapper.find('[data-testid="device-status-1"] [data-testid="status-pill"]').text()).toBe(
            'Unknown Device',
        );
        // Third: no IP → "Infrastructure"
        expect(wrapper.find('[data-testid="device-status-2"] [data-testid="status-pill"]').text()).toBe(
            'Infrastructure',
        );
    });
});
