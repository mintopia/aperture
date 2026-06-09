import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Leases from '../Leases.vue';
import { isIpInPrefix } from '@/utils/dhcp.js';

const routeMock = vi.fn(() => '#');

const globalConfig = {
    stubs: ['AdminLayout', 'FilterBar', 'MetadataStrip', 'Link'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

const mockRanges = [
    {
        network: '10.0.0.0/24',
        start: '10.0.0.10',
        end: '10.0.0.200',
        prefix: null,
        type: 'ipv4',
    },
    {
        network: '2001:db8:1::/64',
        start: null,
        end: null,
        prefix: '2001:db8:1::/64',
        type: 'ipv6',
    },
];

const mockLeases = [
    { ip: '10.0.0.50', mac: 'AA:BB:CC:DD:EE:01', hostname: 'host-v4', expires: '' },
    { ip: '2001:db8:1::5', mac: 'AA:BB:CC:DD:EE:02', hostname: 'host-v6-a', expires: '' },
    { ip: '2001:DB8:1::1F', mac: 'AA:BB:CC:DD:EE:03', hostname: 'host-v6-b', expires: '' },
    { ip: '2001:db8:2::5', mac: 'AA:BB:CC:DD:EE:04', hostname: 'host-v6-other', expires: '' },
];

function mountWithNetwork(network) {
    const query = network ? '/?network=' + encodeURIComponent(network) : '/';
    window.history.replaceState({}, '', query);
    return mount(Leases, {
        props: { leases: mockLeases, ranges: mockRanges },
        global: globalConfig,
    });
}

afterEach(() => {
    window.history.replaceState({}, '', '/');
});

describe('isIpInPrefix', () => {
    it('matches an address inside the prefix', () => {
        expect(isIpInPrefix('2001:db8:1::5', '2001:db8:1::/64')).toBe(true);
    });

    it('matches case-insensitively', () => {
        expect(isIpInPrefix('2001:DB8:1::5', '2001:db8:1::/64')).toBe(true);
    });

    it('rejects an address outside the prefix', () => {
        expect(isIpInPrefix('2001:db8:2::5', '2001:db8:1::/64')).toBe(false);
    });

    it('rejects an address sharing only a partial hextet with the prefix', () => {
        expect(isIpInPrefix('2001:db8:10::5', '2001:db8:1::/64')).toBe(false);
        expect(isIpInPrefix('2001:db8:1f00::5', '2001:db8:1::/64')).toBe(false);
    });

    it('matches an address exactly at the hextet boundary', () => {
        expect(isIpInPrefix('2001:db8:1::5', '2001:db8:1::/64')).toBe(true);
    });

    it('returns false for null or empty inputs', () => {
        expect(isIpInPrefix(null, '2001:db8:1::/64')).toBe(false);
        expect(isIpInPrefix('2001:db8:1::5', null)).toBe(false);
        expect(isIpInPrefix('', '')).toBe(false);
    });

    it('returns false when the prefix has no network portion', () => {
        expect(isIpInPrefix('2001:db8:1::5', '::/0')).toBe(false);
    });
});

describe('Dhcp/Leases', () => {
    it('renders the page title', () => {
        const wrapper = mountWithNetwork(null);
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('DHCP Leases');
    });

    it('shows all leases when no range is selected', () => {
        const wrapper = mountWithNetwork(null);
        expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(4);
    });

    it('filters leases by start/end for an IPv4 range', () => {
        const wrapper = mountWithNetwork('10.0.0.0/24');
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
        expect(rows[0].text()).toContain('host-v4');
    });

    it('filters leases by prefix for an IPv6 range without start/end', () => {
        const wrapper = mountWithNetwork('2001:db8:1::/64');
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.text()).toContain('host-v6-a');
        expect(wrapper.text()).toContain('host-v6-b');
        expect(wrapper.text()).not.toContain('host-v6-other');
        expect(wrapper.text()).not.toContain('host-v4');
    });

    it('shows all leases when the selected range does not exist', () => {
        const wrapper = mountWithNetwork('192.168.99.0/24');
        expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(4);
    });
});
