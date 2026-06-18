import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Show from '@/Pages/Admin/Switches/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.mock('@/utils/dates', () => ({
    formatRelative: vi.fn((v) => v),
    formatDate: vi.fn((v) => v),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
    formatSpeed: vi.fn((v) => v ?? '—'),
    formatVlan: vi.fn((vlan) => (vlan == null ? '—' : String(vlan))),
}));

globalThis.route = (...args) => `/mocked/${args[0]}`;

const mockPorts = [
    {
        id: 1,
        interface: 'Gi1/0/1',
        description: 'Server Room',
        status: 'connected',
        admin_status: 'up',
        speed: '1Gbps',
        vlan: 100,
        poe: 'on',
    },
    {
        id: 2,
        interface: 'Gi1/0/2',
        description: 'AP-Lobby',
        status: 'connected',
        admin_status: 'up',
        speed: '1Gbps',
        vlan: 200,
        poe: 'on',
    },
    {
        id: 3,
        interface: 'Gi1/0/3',
        description: '',
        status: 'notconnect',
        admin_status: 'up',
        speed: null,
        vlan: 1,
        poe: 'off',
    },
    {
        id: 4,
        interface: 'Gi1/0/4',
        description: 'Broken Link',
        status: 'err-disabled',
        admin_status: 'up',
        speed: null,
        vlan: 100,
        poe: 'off',
    },
];

const defaultProps = {
    switchConfig: {
        id: 1,
        name: 'Core Switch',
        hostname: '10.0.0.1',
        port: 22,
        type: 'cisco_ios',
        enabled: true,
        timeout: 30,
        last_synced_at: '2024-01-01T00:00:00Z',
        created_at: '2024-01-01T00:00:00Z',
    },
    ports: mockPorts,
};

function mountShow(propsOverride = {}) {
    return mount(Show, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div />', props: ['items'] },
                SwitchPortGrid: { template: '<div />', props: ['ports', 'switchId'] },
                teleport: true,
            },
        },
    });
}

describe('Show — Port Search & Filter', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the search input', () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');
        expect(input.exists()).toBe(true);
        expect(input.attributes('id')).toBe('port-search');
        expect(input.attributes('placeholder')).toBe('Search ports…');
        expect(wrapper.find('label[for="port-search"]').exists()).toBe(true);
    });

    it('renders the status filter dropdown with options', () => {
        const wrapper = mountShow();
        const select = wrapper.find('[data-testid="port-filter-status"]');
        expect(select.exists()).toBe(true);

        const options = select.findAll('option');
        expect(options).toHaveLength(4);
        expect(options[0].text()).toBe('All Status');
        expect(options[1].text()).toContain('Up');
        expect(options[1].text()).toContain('2');
        expect(options[2].text()).toContain('Down');
        expect(options[2].text()).toContain('1');
        expect(options[3].text()).toContain('Errors');
        expect(options[3].text()).toContain('1');
    });

    it('search filters ports by interface name', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('Gi1/0/1');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('search filters by description', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('Server Room');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('search filters by VLAN', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('200');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('search is case-insensitive', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('server room');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('search is debounced at 300ms', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('Server Room');
        await input.trigger('input');

        // Before debounce fires, all ports should still be visible
        await wrapper.vm.$nextTick();
        expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(4);

        // After debounce fires
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();
        expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(1);
    });

    it('status filter "up" shows only connected ports', async () => {
        const wrapper = mountShow();
        const select = wrapper.find('[data-testid="port-filter-status"]');

        await select.setValue('up');
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(2);
    });

    it('status filter "down" shows only down/notconnect ports', async () => {
        const wrapper = mountShow();
        const select = wrapper.find('[data-testid="port-filter-status"]');

        await select.setValue('down');
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('status filter "errors" shows only err-disabled ports', async () => {
        const wrapper = mountShow();
        const select = wrapper.find('[data-testid="port-filter-status"]');

        await select.setValue('errors');
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('"all" resets filter', async () => {
        const wrapper = mountShow();
        const select = wrapper.find('[data-testid="port-filter-status"]');

        // First apply a filter
        await select.setValue('up');
        await wrapper.vm.$nextTick();
        expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(2);

        // Reset with "all"
        await select.setValue('all');
        await wrapper.vm.$nextTick();
        expect(wrapper.findAll('[data-testid="data-table-row"]')).toHaveLength(4);
    });

    it('combined search + filter works', async () => {
        const wrapper = mountShow();

        // Apply "up" filter first (2 connected ports: Gi1/0/1 and Gi1/0/2)
        await wrapper.find('[data-testid="port-filter-status"]').setValue('up');
        await wrapper.vm.$nextTick();

        // Then search for "AP-Lobby" (only Gi1/0/2)
        const input = wrapper.find('[data-testid="port-search"]');
        await input.setValue('AP-Lobby');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('shows "No ports match your search" when no results', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('nonexistent-port-xyz');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-no-results"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="port-no-results"]').text()).toBe('No ports match your search');
    });

    it('hides no-results message when ports exist in results', () => {
        const wrapper = mountShow();
        expect(wrapper.find('[data-testid="port-no-results"]').exists()).toBe(false);
    });

    it('defaults to "all" status filter', () => {
        const wrapper = mountShow();
        const select = wrapper.find('[data-testid="port-filter-status"]');
        expect(select.element.value).toBe('all');
    });

    it('shows filtered count', () => {
        const wrapper = mountShow();
        const count = wrapper.find('[data-testid="port-filter-count"]');
        expect(count.exists()).toBe(true);
        expect(count.text()).toBe('4 of 4');
    });

    it('updates filtered count when filter is applied', async () => {
        const wrapper = mountShow();
        await wrapper.find('[data-testid="port-filter-status"]').setValue('up');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('2 of 4');
    });

    it('hides count when no ports exist', () => {
        const wrapper = mountShow({ ports: [] });
        expect(wrapper.find('[data-testid="port-filter-count"]').exists()).toBe(false);
    });

    it('shows DataTable empty message when switch has no ports at all', () => {
        const wrapper = mountShow({ ports: [] });
        expect(wrapper.find('[data-testid="data-table-empty"]').exists()).toBe(true);
    });
});
