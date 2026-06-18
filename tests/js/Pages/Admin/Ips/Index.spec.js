import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Index from '@/Pages/Admin/Ips/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn(), visit: vi.fn() },
    Link: {
        template: '<a :href="href" @click="$emit(\'click\', $event)"><slot /></a>',
        props: ['href'],
        emits: ['click'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

const defaultIps = {
    data: [
        {
            id: 1,
            address: '10.0.0.1',
            mac: 'AA:BB:CC:DD:EE:FF',
            internet_enabled: true,
            users: [{ user: { id: 42, nickname: 'testuser' } }],
        },
        {
            id: 2,
            address: '10.0.0.2',
            mac: null,
            internet_enabled: false,
            users: [],
        },
        {
            id: 3,
            address: '10.0.0.3',
            mac: null,
            internet_enabled: null,
            users: [],
        },
    ],
    total: 3,
};

const defaultFilters = {
    address: '',
    status: '',
};

describe('Ips/Index', () => {
    const mountComponent = (props = {}) => {
        return mount(Index, {
            props: {
                ips: defaultIps,
                filters: defaultFilters,
                ...props,
            },
            global: {
                mocks: {
                    route: (name, params) => {
                        if (name === 'admin.ips.show') return `/admin/ips/${params}`;
                        if (name === 'admin.users.show') return `/admin/users/${params}`;
                        if (name === 'admin.ips.index') return '/admin/ips';
                        return `/mocked/${name}`;
                    },
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    Pagination: { template: '<div data-testid="pagination"></div>', props: ['paginator'] },
                    teleport: true,
                },
            },
        });
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders page title "IP Addresses"', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('IP Addresses');
    });

    it('renders section header "Address List"', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('h2').text()).toBe('Address List');
    });

    it('renders FilterBar component', () => {
        const wrapper = mountComponent();
        // Index.vue passes data-testid="ip-filter-bar" which overrides FilterBar's internal root testid
        expect(wrapper.find('[data-testid="ip-filter-bar"]').exists()).toBe(true);
    });

    it('renders FilterBar search input with correct placeholder', () => {
        const wrapper = mountComponent();
        const input = wrapper.find('[data-testid="filter-search-input"]');
        expect(input.exists()).toBe(true);
        expect(input.attributes('placeholder')).toBe('Filter by address…');
    });

    it('renders DataTable component', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="data-table"]').exists()).toBe(true);
    });

    it('renders correct column headers', () => {
        const wrapper = mountComponent();
        const headers = wrapper.findAll('th');
        const headerTexts = headers.map((h) => h.text().trim());
        expect(headerTexts).toEqual(['Address', 'MAC', 'User', 'Status']);
    });

    it('renders correct number of rows', () => {
        const wrapper = mountComponent();
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(3);
    });

    it('renders address cell with mono font and primary color class', () => {
        const wrapper = mountComponent();
        const addressCell = wrapper.find('[data-testid="ip-address"]');
        expect(addressCell.exists()).toBe(true);
        expect(addressCell.text()).toBe('10.0.0.1');
        expect(addressCell.classes()).toContain('font-mono');
        expect(addressCell.classes().some((c) => c.includes('color-primary'))).toBe(true);
    });

    it('renders MAC address when present', () => {
        const wrapper = mountComponent();
        const macCell = wrapper.find('[data-testid="ip-mac"]');
        expect(macCell.text()).toBe('AA:BB:CC:DD:EE:FF');
    });

    it('renders em dash when MAC address is null', () => {
        const wrapper = mountComponent();
        const macCells = wrapper.findAll('[data-testid="ip-mac"]');
        expect(macCells[1].text()).toBe('—');
    });

    it('renders user as a Link when user exists', () => {
        const wrapper = mountComponent();
        const userCell = wrapper.find('[data-testid="ip-user"]');
        const link = userCell.find('a');
        expect(link.exists()).toBe(true);
        expect(link.text()).toBe('testuser');
        expect(link.attributes('href')).toBe('/admin/users/42');
    });

    it('renders em dash for user when no users', () => {
        const wrapper = mountComponent();
        const userCells = wrapper.findAll('[data-testid="ip-user"]');
        expect(userCells[1].find('a').exists()).toBe(false);
        expect(userCells[1].text()).toBe('—');
    });

    it('renders "Allowed" status text for allowed IP', () => {
        const wrapper = mountComponent();
        const statusCells = wrapper.findAll('[data-testid="ip-status"]');
        expect(statusCells[0].text()).toContain('Allowed');
    });

    it('renders "Blocked" status text for blocked IP', () => {
        const wrapper = mountComponent();
        const statusCells = wrapper.findAll('[data-testid="ip-status"]');
        expect(statusCells[1].text()).toContain('Blocked');
    });

    it('renders an em dash status for an IP with no explicit decision', () => {
        const wrapper = mountComponent();
        const statusCells = wrapper.findAll('[data-testid="ip-status"]');
        expect(statusCells[2].text()).toContain('—');
    });

    it('renders status dot with success classes for allowed IP', () => {
        const wrapper = mountComponent();
        const statusCells = wrapper.findAll('[data-testid="ip-status"]');
        const dot = statusCells[0].find('span.rounded-full');
        expect(dot.exists()).toBe(true);
        expect(dot.classes().some((c) => c.includes('color-success'))).toBe(true);
    });

    it('renders status dot with danger classes for denied IP', () => {
        const wrapper = mountComponent();
        const statusCells = wrapper.findAll('[data-testid="ip-status"]');
        const dot = statusCells[1].find('span.rounded-full');
        expect(dot.exists()).toBe(true);
        expect(dot.classes().some((c) => c.includes('color-danger'))).toBe(true);
    });

    it('renders Pagination component', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(true);
    });

    it('shows DataTable empty state when ips.data is empty', () => {
        const wrapper = mountComponent({ ips: { data: [], total: 0 } });
        expect(wrapper.find('[data-testid="data-table-empty"]').exists()).toBe(true);
    });

    it('does not render StatusPill component', () => {
        const wrapper = mountComponent();
        expect(wrapper.findComponent({ name: 'StatusPill' }).exists()).toBe(false);
    });

    it('user link uses route admin.users.show with user id', () => {
        const wrapper = mountComponent();
        const userCell = wrapper.find('[data-testid="ip-user"]');
        const link = userCell.find('a');
        expect(link.attributes('href')).toBe('/admin/users/42');
    });

    it('initialises search from filters.address prop', () => {
        const wrapper = mountComponent({ filters: { address: '10.0.0', status: '' } });
        const input = wrapper.find('[data-testid="filter-search-input"]');
        expect(input.element.value).toBe('10.0.0');
    });

    it('renders status filter dropdown', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="filter-select-status"]').exists()).toBe(true);
    });

    it('status filter dropdown has Allowed, Blocked and Unassigned options', () => {
        const wrapper = mountComponent();
        const select = wrapper.find('[data-testid="filter-select-status"]');
        const options = select.findAll('option');
        const optionValues = options.map((o) => o.element.value);
        expect(optionValues).toContain('allowed');
        expect(optionValues).toContain('blocked');
        expect(optionValues).toContain('unassigned');
    });
});
