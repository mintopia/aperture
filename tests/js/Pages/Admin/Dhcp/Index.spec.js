import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Index from '@/Pages/Admin/Dhcp/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

describe('Dhcp/Index', () => {
    const defaultPool = {
        total: 254,
        used: 100,
        available: 154,
        utilisation: 0.3937,
    };

    const ipv4Range = {
        interface: 'lan',
        type: 'ipv4',
        subnet: '10.0.0.0/24',
        range_from: '10.0.0.100',
        range_to: '10.0.0.200',
        prefix: null,
        gateway: '10.0.0.1',
        description: 'LAN DHCP',
        total_addresses: 101,
        used_addresses: 42,
        utilisation: 0.4158,
    };

    const ipv6Range = {
        interface: 'lan',
        type: 'ipv6',
        subnet: null,
        range_from: null,
        range_to: null,
        prefix: 'fd00::/64',
        gateway: null,
        description: 'LAN IPv6',
        total_addresses: null,
        used_addresses: null,
        utilisation: null,
    };

    const mountComponent = (props = {}) => {
        return mount(Index, {
            props: {
                pool: defaultPool,
                ranges: [],
                ...props,
            },
            global: {
                mocks: {
                    route: (name) => `/mocked/${name}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    StatCard: {
                        template: '<div data-testid="stat-card"><slot /></div>',
                        props: ['label', 'value', 'hero', 'color', 'accentBorder'],
                    },
                    ProgressBar: {
                        template: '<div data-testid="progress-bar"></div>',
                        props: ['label', 'value', 'max', 'color', 'displayValue'],
                    },
                    SectionHeader: {
                        template: '<div data-testid="section-header"><slot /><slot name="actions" /></div>',
                        props: ['title', 'accentLine'],
                    },
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
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('DHCP');
    });

    it('renders dhcp-ranges section', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="dhcp-ranges"]').exists()).toBe(true);
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

        expect(wrapper.find('[data-testid="range-row-0-interface"]').text()).toBe('lan');
        expect(wrapper.find('[data-testid="range-row-0-subnet"]').text()).toBe('10.0.0.0/24');
        expect(wrapper.find('[data-testid="range-row-0-range"]').text()).toBe('10.0.0.100 – 10.0.0.200');
        expect(wrapper.find('[data-testid="range-row-0-gateway"]').text()).toBe('10.0.0.1');
        expect(wrapper.find('[data-testid="range-row-0-description"]').text()).toBe('LAN DHCP');
    });

    it('renders IPv4 type badge with correct text and styling', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range] });
        const badge = wrapper.find('[data-testid="range-type-badge-0"]');
        expect(badge.text()).toBe('IPv4');
        expect(badge.classes()).toContain('bg-blue-100');
        expect(badge.classes()).toContain('text-blue-800');
    });

    it('renders IPv6 range row with prefix', () => {
        const wrapper = mountComponent({ ranges: [ipv6Range] });
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows.length).toBe(1);

        expect(wrapper.find('[data-testid="range-row-0-interface"]').text()).toBe('lan');
        expect(wrapper.find('[data-testid="range-row-0-subnet"]').text()).toBe('fd00::/64');
        expect(wrapper.find('[data-testid="range-row-0-range"]').text()).toBe('—');
        expect(wrapper.find('[data-testid="range-row-0-gateway"]').text()).toBe('—');
        expect(wrapper.find('[data-testid="range-row-0-description"]').text()).toBe('LAN IPv6');
    });

    it('renders IPv6 type badge with correct text and styling', () => {
        const wrapper = mountComponent({ ranges: [ipv6Range] });
        const badge = wrapper.find('[data-testid="range-type-badge-0"]');
        expect(badge.text()).toBe('IPv6');
        expect(badge.classes()).toContain('bg-purple-100');
        expect(badge.classes()).toContain('text-purple-800');
    });

    it('renders multiple ranges', () => {
        const wrapper = mountComponent({ ranges: [ipv4Range, ipv6Range] });
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows.length).toBe(2);

        expect(wrapper.find('[data-testid="range-type-badge-0"]').text()).toBe('IPv4');
        expect(wrapper.find('[data-testid="range-type-badge-1"]').text()).toBe('IPv6');
    });

    it('shows em dash for missing subnet and prefix', () => {
        const rangeNoSubnet = {
            ...ipv4Range,
            subnet: null,
            prefix: null,
        };
        const wrapper = mountComponent({ ranges: [rangeNoSubnet] });
        expect(wrapper.find('[data-testid="range-row-0-subnet"]').text()).toBe('—');
    });

    it('shows em dash for missing interface', () => {
        const rangeNoInterface = {
            ...ipv4Range,
            interface: '',
        };
        const wrapper = mountComponent({ ranges: [rangeNoInterface] });
        expect(wrapper.find('[data-testid="range-row-0-interface"]').text()).toBe('—');
    });

    it('shows em dash for missing description', () => {
        const rangeNoDesc = {
            ...ipv4Range,
            description: null,
        };
        const wrapper = mountComponent({ ranges: [rangeNoDesc] });
        expect(wrapper.find('[data-testid="range-row-0-description"]').text()).toBe('—');
    });

    it('renders stat cards', () => {
        const wrapper = mountComponent();
        const cards = wrapper.findAll('[data-testid="stat-card"]');
        expect(cards.length).toBe(4);
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
        expect(bar.classes()).toContain('bg-emerald-500');
    });

    it('renders em dash for usage when no total_addresses', () => {
        const wrapper = mountComponent({ ranges: [ipv6Range] });
        const usage = wrapper.find('[data-testid="range-row-0-usage"]');
        expect(usage.text()).toBe('—');
    });

    it('renders danger color bar when utilisation above 90%', () => {
        const highRange = { ...ipv4Range, utilisation: 0.95 };
        const wrapper = mountComponent({ ranges: [highRange] });
        const bar = wrapper.find('[data-testid="range-usage-bar-0"]');
        expect(bar.classes()).toContain('bg-red-500');
    });

    it('renders warning color bar when utilisation above 70%', () => {
        const medRange = { ...ipv4Range, utilisation: 0.75 };
        const wrapper = mountComponent({ ranges: [medRange] });
        const bar = wrapper.find('[data-testid="range-usage-bar-0"]');
        expect(bar.classes()).toContain('bg-amber-500');
    });
});
