import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Index from '@/Pages/Admin/Switches/Index.vue';

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
    formatRelative: vi.fn((v) => `relative:${v}`),
    formatDate: vi.fn((v) => v),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
}));

const baseSwitchData = {
    id: 1,
    name: 'Core Switch',
    hostname: '10.0.0.1',
    type: 'cisco',
    enabled: true,
    port: 22,
    timeout: 5,
    created_at: '2024-01-01T00:00:00Z',
    updated_at: '2024-01-01T00:00:00Z',
    port_count: 24,
    ports_up: 20,
    ports_down: 3,
    ports_error: 1,
    last_synced_at: '2024-06-15T10:30:00Z',
    latest_sync_status: 'completed',
};

function mountIndex(switches = [baseSwitchData]) {
    return mount(Index, {
        props: { switches },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
                $inertia: { visit: vi.fn() },
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                FilterBar: {
                    template: '<div data-testid="filter-bar-stub" />',
                    props: ['search', 'searchPlaceholder', 'filters', 'filterValues', 'totalCount', 'filteredCount'],
                },
                EmptyState: {
                    template: '<div data-testid="empty-state"><slot /></div>',
                    props: ['title', 'description'],
                },
            },
        },
    });
}

describe('Index — Port Breakdown', () => {
    it('renders port breakdown with up/down/error counts', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="port-breakdown-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toContain('20');
        expect(cell.text()).toContain('↑');
        expect(cell.text()).toContain('3');
        expect(cell.text()).toContain('↓');
        expect(cell.text()).toContain('1');
        expect(cell.text()).toContain('⚠');
    });

    it('renders port breakdown showing only non-zero counts', () => {
        const sw = { ...baseSwitchData, ports_down: 0, ports_error: 0 };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="port-breakdown-1"]');
        expect(cell.text()).toContain('20');
        expect(cell.text()).toContain('↑');
        expect(cell.text()).not.toContain('↓');
        expect(cell.text()).not.toContain('⚠');
    });

    it('shows dash when port_count is 0', () => {
        const sw = { ...baseSwitchData, port_count: 0, ports_up: 0, ports_down: 0, ports_error: 0 };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="port-breakdown-1"]');
        expect(cell.text()).toBe('—');
    });
});

describe('Index — Layout parity', () => {
    it('renders header and table grouping wrappers', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="switches-index-layout"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="switches-index-header"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="switches-summary"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="switches-table-card"]').exists()).toBe(true);
    });

    it('hides summary and table card when no switches are configured', () => {
        const wrapper = mountIndex([]);
        expect(wrapper.find('[data-testid="switches-summary"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="switches-table-card"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
    });

    it('renders filter bar when switches exist', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="filter-bar-stub"]').exists()).toBe(true);
    });
});

describe('Index — Sync Status (inline dot)', () => {
    it('shows inline sync status for completed sync', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toContain('Completed');
    });

    it('shows inline sync status for failed sync', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'failed' };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).toContain('Failed');
    });

    it('shows inline sync status for running sync', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'running', last_synced_at: null };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).toContain('Running');
    });

    it('shows inline sync status for pending sync', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'pending', last_synced_at: null };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).toContain('Pending');
    });

    it('shows "Never synced" when no sync data', () => {
        const sw = { ...baseSwitchData, latest_sync_status: null, last_synced_at: null };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).toContain('Never synced');
    });

    it('uses formatRelative for sync time display', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).toContain('relative:2024-06-15T10:30:00Z');
    });
});

describe('Index — Sorting', () => {
    it('sorts by port_count column', async () => {
        const switches = [
            { ...baseSwitchData, id: 1, name: 'A', port_count: 10, ports_up: 10, ports_down: 0, ports_error: 0 },
            { ...baseSwitchData, id: 2, name: 'B', port_count: 30, ports_up: 30, ports_down: 0, ports_error: 0 },
            { ...baseSwitchData, id: 3, name: 'C', port_count: 5, ports_up: 5, ports_down: 0, ports_error: 0 },
        ];
        const wrapper = mountIndex(switches);

        const portsSortButton = wrapper.find('[data-testid="sort-port_count"]');
        expect(portsSortButton.exists()).toBe(true);

        await portsSortButton.trigger('click');
        await wrapper.vm.$nextTick();

        const rows = wrapper.findAll('[data-testid^="switch-row-"]');
        expect(rows[0].attributes('data-testid')).toBe('switch-row-3');
        expect(rows[1].attributes('data-testid')).toBe('switch-row-1');
        expect(rows[2].attributes('data-testid')).toBe('switch-row-2');
    });

    it('adds aria-sort for sortable header state', async () => {
        const wrapper = mountIndex();
        const portHeader = wrapper.findAll('th').find((th) => th.text().includes('Ports'));

        expect(portHeader.attributes('aria-sort')).toBe('none');

        await wrapper.find('[data-testid="sort-port_count"]').trigger('click');
        await wrapper.vm.$nextTick();

        const updatedPortHeader = wrapper.findAll('th').find((th) => th.text().includes('Ports'));
        expect(updatedPortHeader.attributes('aria-sort')).toBe('ascending');
    });

    it('adds accessible labels to interactive switch rows', () => {
        const wrapper = mountIndex();
        const row = wrapper.find('[data-testid="switch-row-1"]');
        expect(row.attributes('role')).toBe('link');
        expect(row.attributes('aria-label')).toBe('Open switch Core Switch');
    });
});
