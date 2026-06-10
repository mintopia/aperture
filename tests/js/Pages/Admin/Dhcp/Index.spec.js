import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Index from '@/Pages/Admin/Dhcp/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn() },
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

describe('Dhcp/Index', () => {
    const ipv4Range = {
        ip_version: 'IPv4',
        network: '10.0.0.0/24',
        start: '10.0.0.100',
        end: '10.0.0.200',
        used: 42,
        total: 101,
        percentage: 41.6,
    };

    const ipv6Range = {
        ip_version: 'IPv6',
        network: 'fd00::/64',
        start: null,
        end: null,
        used: 3,
        total: null,
        percentage: null,
    };

    const mountComponent = (props = {}) => {
        return mount(Index, {
            props: {
                ranges: [],
                ...props,
            },
            global: {
                mocks: {
                    route: (name, params) =>
                        `/mocked/${name}${params ? '?' + new URLSearchParams(params).toString() : ''}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: { template: '<div data-testid="metadata-strip"><slot /></div>', props: ['items'] },
                    teleport: true,
                },
            },
        });
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders page title', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('DHCP Ranges');
    });

    it('renders section header for configured ranges', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        const h2 = wrapper.find('h2.font-heading');
        expect(h2.exists()).toBe(true);
        expect(h2.text()).toBe('Configured Ranges');
    });

    it('renders data-table section', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="data-table"]').exists()).toBe(true);
    });

    it('shows empty message when no ranges configured', () => {
        const wrapper = mountComponent({ ranges: [] });
        expect(wrapper.find('[data-testid="data-table-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="data-table-empty"]').text()).toContain('No DHCP ranges configured.');
    });

    it('renders IPv4 range row with correct data', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows.length).toBe(1);

        expect(wrapper.find('[data-testid="range-row-0-network"]').text()).toBe('10.0.0.0/24');
        expect(wrapper.find('[data-testid="range-row-0-start"]').text()).toBe('10.0.0.100');
        expect(wrapper.find('[data-testid="range-row-0-end"]').text()).toBe('10.0.0.200');
    });

    it('renders IPv6 range row with network', () => {
        const wrapper = mountComponent({ ranges: [ipv6Range] });
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows.length).toBe(1);

        expect(wrapper.find('[data-testid="range-row-0-network"]').text()).toBe('fd00::/64');
        expect(wrapper.find('[data-testid="range-row-0-start"]').text()).toBe('—');
        expect(wrapper.find('[data-testid="range-row-0-end"]').text()).toBe('—');
    });

    it('renders multiple ranges', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range, ipv6Range] });
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows.length).toBe(2);
    });

    it('shows em dash for missing network', () => {
        const rangeNoNetwork = {
            ...ipv4Range,
            network: null,
        };
        const wrapper = mountComponent({ ranges: [rangeNoNetwork] });
        expect(wrapper.find('[data-testid="range-row-0-network"]').text()).toBe('—');
    });

    it('renders usage data for IPv4 range', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        const usage = wrapper.find('[data-testid="range-row-0-usage"]');
        expect(usage.text()).toContain('42');
        expect(usage.text()).toContain('101');
        expect(usage.text()).toContain('41.6%');
    });

    it('renders usage progress bar for IPv4 range', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        const bar = wrapper.find('[data-testid="range-usage-bar-0"]');
        expect(bar.exists()).toBe(true);
        expect(bar.attributes('style')).toContain('background-color: var(--color-success)');
    });

    it('renders danger color bar when percentage above 90%', () => {
        const highRange = { ...ipv4Range, percentage: 95 };
        const wrapper = mountComponent({ ranges: [highRange] });
        const bar = wrapper.find('[data-testid="range-usage-bar-0"]');
        expect(bar.attributes('style')).toContain('background-color: var(--color-danger)');
    });

    it('renders warning color bar when percentage above 70%', () => {
        const medRange = { ...ipv4Range, percentage: 75 };
        const wrapper = mountComponent({ ranges: [medRange] });
        const bar = wrapper.find('[data-testid="range-usage-bar-0"]');
        expect(bar.attributes('style')).toContain('background-color: var(--color-warning)');
    });

    it('renders network as a link to leases page', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        const link = wrapper.find('[data-testid="range-row-0-network"] a');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('10.0.0.0/24');
    });

    it('renders metadata strip with summary info', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range, ipv6Range] });
        expect(wrapper.find('[data-testid="metadata-strip"]').exists()).toBe(true);
    });

    it('shows used count alone when total is unknown', () => {
        const wrapper = mountComponent({ ranges: [ipv6Range] });
        expect(wrapper.find('[data-testid="range-count-0"]').text()).toBe('3 used');
        expect(wrapper.find('[data-testid="range-usage-bar-0"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="range-percentage-0"]').text()).toBe('—');
    });

    it('shows em dash when usage is entirely unknown', () => {
        const unknownRange = { ...ipv6Range, used: null };
        const wrapper = mountComponent({ ranges: [unknownRange] });
        expect(wrapper.find('[data-testid="range-count-0"]').text()).toBe('—');
        expect(wrapper.find('[data-testid="range-usage-bar-0"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="range-percentage-0"]').text()).toBe('—');
    });

    it('shows em dash for used count when only used is unknown', () => {
        const partialRange = { ...ipv4Range, used: null };
        const wrapper = mountComponent({ ranges: [partialRange] });
        expect(wrapper.find('[data-testid="range-count-0"]').text()).toBe('— / 101');
        expect(wrapper.find('[data-testid="range-percentage-0"]').text()).toBe('41.6%');
    });

    it('renders used and total counts for IPv4 range', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        expect(wrapper.find('[data-testid="range-count-0"]').text()).toBe('42 / 101');
    });

    it('aggregates summary cards from known values only', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range, ipv6Range] });
        const items = wrapper.getComponent('[data-testid="metadata-strip"]').props('items');
        expect(items).toEqual([
            { label: 'Ranges', value: 2 },
            { label: 'Used', value: 45, mono: true },
            { label: 'Total', value: 101, mono: true },
            { label: 'Utilisation', value: '41.6%' },
        ]);
    });

    it('shows em dash utilisation when no totals are known', () => {
        const wrapper = mountComponent({ ranges: [ipv6Range] });
        const items = wrapper.getComponent('[data-testid="metadata-strip"]').props('items');
        expect(items[3]).toEqual({ label: 'Utilisation', value: '—' });
    });
});
