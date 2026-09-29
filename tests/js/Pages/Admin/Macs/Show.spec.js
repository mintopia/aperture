import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '@/Pages/Admin/Macs/Show.vue';

const routeMock = vi.fn(() => '#');

const globalConfig = {
    stubs: ['AdminLayout', 'MetadataStrip', 'DataTable', 'SectionHeader', 'StatusPill', 'Link'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

const mockMac = {
    id: 1,
    mac_address: 'AA:BB:CC:DD:EE:01',
    hostname: 'test-host',
    user: { id: 1, nickname: 'testuser' },
    source: 'dhcp',
    description: null,
    created_at: '2026-04-23T00:00:00+00:00',
};

describe('Macs/Show', () => {
    it('renders all sections', () => {
        const wrapper = mount(Show, {
            props: {
                mac: mockMac,
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="mac-show-layout"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-ips-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-dhcp-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-switch-ports-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mac-audit-section"]').exists()).toBe(true);
    });

    it('renders MAC address in header', () => {
        const wrapper = mount(Show, {
            props: {
                mac: mockMac,
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toContain('AA:BB:CC:DD:EE:01');
    });

    it('renders show header section', () => {
        const wrapper = mount(Show, {
            props: {
                mac: mockMac,
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="mac-show-header"]').exists()).toBe(true);
    });

    it('renders hostname when provided', () => {
        const wrapper = mount(Show, {
            props: {
                mac: mockMac,
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: globalConfig,
        });
        expect(wrapper.html()).toContain('test-host');
    });

    it('renders without hostname', () => {
        const wrapper = mount(Show, {
            props: {
                mac: { ...mockMac, hostname: null },
                ipAddresses: [],
                dhcpLeases: [],
                switchPorts: [],
                auditLogs: [],
            },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="mac-show-layout"]').exists()).toBe(true);
    });
});
