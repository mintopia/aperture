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
    formatBytesComponents: vi.fn((v) => ({ value: `${v}`, unit: 'B' })),
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
                    props: ['title'],
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
    it('renders connected devices as a table with MAC / IPv4 / IPv6 columns', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [
                        { id: 42, ip: '10.0.1.42', hostname: 'test', user: { id: 1, nickname: 'NeonGamer42' } },
                        { ip: '10.0.1.43', hostname: 'test', user: null },
                        { id: 44, ip: '2001:db8::1', hostname: 'test-v6', user: null },
                        { ip: '2001:db8::2', hostname: 'test-v6', user: null },
                    ],
                },
            ],
        });

        const table = wrapper.find('[data-testid="connected-devices-table"]');
        expect(table.exists()).toBe(true);

        const headerCells = wrapper.findAll('thead th');
        expect(headerCells).toHaveLength(3);
        expect(headerCells[0].text()).toBe('MAC Address');
        expect(headerCells[1].text()).toBe('IPv4');
        expect(headerCells[2].text()).toBe('IPv6');

        const ipv4First = wrapper.find('[data-testid="device-ipv4-link-0-0"]');
        const ipv6First = wrapper.find('[data-testid="device-ipv6-link-0-0"]');
        const ipv4Second = wrapper.find('[data-testid="device-ipv4-link-0-1"]');
        const ipv6Second = wrapper.find('[data-testid="device-ipv6-link-0-1"]');
        expect(ipv4First.exists()).toBe(true);
        expect(ipv6First.exists()).toBe(true);
        expect(ipv4Second.exists()).toBe(true);
        expect(ipv6Second.exists()).toBe(true);
        expect(ipv4First.attributes('href')).toContain('admin.ips.show');
        expect(ipv4First.attributes('href')).toContain('10.0.1.42');
        expect(ipv6First.attributes('href')).toContain('2001:db8::1');
        expect(ipv4Second.attributes('href')).toContain('10.0.1.43');
        expect(ipv6Second.attributes('href')).toContain('2001:db8::2');
    });

    it('renders all devices including those without IPs', () => {
        const wrapper = mountShow({
            macs: [
                {
                    mac_address: 'AA:BB:CC:DD:EE:FF',
                    vlan: 100,
                    last_seen_at: '2024-01-01T00:00:00Z',
                    resolved_ips: [{ id: 1, ip: '10.0.1.42', hostname: null, user: { id: 1, nickname: 'User1' } }],
                },
                {
                    mac_address: '11:22:33:44:55:66',
                    vlan: 200,
                    last_seen_at: '2024-01-02T00:00:00Z',
                    resolved_ips: [],
                },
                {
                    mac_address: 'FF:EE:DD:CC:BB:AA',
                    vlan: 1,
                    last_seen_at: '2024-01-03T00:00:00Z',
                    resolved_ips: [{ ip: '10.0.1.99', hostname: null, user: null }],
                },
            ],
        });

        expect(wrapper.findAll('tbody tr')).toHaveLength(3);
        expect(wrapper.text()).toContain('Connected Devices (3)');
        expect(wrapper.find('[data-testid="show-devices-without-ip-toggle"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="connected-devices-table-wrapper"]').classes()).toContain('overflow-x-auto');
        expect(wrapper.find('[data-testid="device-ipv4-link-0-0"]').classes()).toContain('break-all');
    });
});
