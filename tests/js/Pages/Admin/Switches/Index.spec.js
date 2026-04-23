import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Index from '@/Pages/Admin/Switches/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
    },
    Link: {
        template: '<a :href="href"><slot /></a>',
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
    typeLabel: vi.fn((v) => `label:${v}`),
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
                route: (name, params) => {
                    if (name === 'admin.switches.show') return `/admin/switches/${params}`;
                    if (name === 'admin.switches.create') return '/admin/switches/create';
                    return `/mocked/${name}`;
                },
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

beforeEach(() => {
    vi.clearAllMocks();
});

describe('Index — Layout', () => {
    it('renders the page layout wrapper', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="switches-index-layout"]').exists()).toBe(true);
    });

    it('renders the page header', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="switches-index-header"]').exists()).toBe(true);
    });

    it('renders the page title', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Switches');
    });

    it('renders the add switch button', () => {
        const wrapper = mountIndex();
        const btn = wrapper.find('[data-testid="action-add-switch"]');
        expect(btn.exists()).toBe(true);
        expect(btn.text()).toBe('Add Switch');
    });

    it('renders summary strip when switches exist', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="switches-summary"]').exists()).toBe(true);
    });

    it('hides summary strip when no switches', () => {
        const wrapper = mountIndex([]);
        expect(wrapper.find('[data-testid="switches-summary"]').exists()).toBe(false);
    });

    it('renders table section when switches exist', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="switches-table-card"]').exists()).toBe(true);
    });

    it('hides table section when no switches', () => {
        const wrapper = mountIndex([]);
        expect(wrapper.find('[data-testid="switches-table-card"]').exists()).toBe(false);
    });

    it('renders empty state when no switches', () => {
        const wrapper = mountIndex([]);
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(true);
    });

    it('does not render empty state when switches exist', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="empty-state"]').exists()).toBe(false);
    });

    it('renders filter bar when switches exist', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="filter-bar-stub"]').exists()).toBe(true);
    });
});

describe('Index — DataTable', () => {
    it('renders DataTable component', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="data-table"]').exists()).toBe(true);
    });

    it('renders correct column headers', () => {
        const wrapper = mountIndex();
        const headers = wrapper.findAll('th');
        const headerTexts = headers.map((h) => h.text().trim());
        // Name is default sort column so its header includes the sort indicator
        expect(headerTexts.some((t) => t.includes('Name'))).toBe(true);
        expect(headerTexts.some((t) => t.includes('Hostname'))).toBe(true);
        expect(headerTexts.some((t) => t.includes('Type'))).toBe(true);
        expect(headerTexts.some((t) => t.includes('Status'))).toBe(true);
        expect(headerTexts.some((t) => t.includes('Ports'))).toBe(true);
        expect(headerTexts.some((t) => t.includes('Sync'))).toBe(true);
    });

    it('renders a row per switch', () => {
        const switches = [baseSwitchData, { ...baseSwitchData, id: 2, name: 'Edge' }];
        const wrapper = mountIndex(switches);
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(2);
    });

    it('rows are clickable with role=row', () => {
        const wrapper = mountIndex();
        const row = wrapper.find('[data-testid="data-table-row"]');
        expect(row.attributes('role')).toBe('row');
    });

    it('rows have correct aria-label', () => {
        const wrapper = mountIndex();
        const row = wrapper.find('[data-testid="data-table-row"]');
        expect(row.attributes('aria-label')).toBe('Open switch Core Switch');
    });
});

describe('Index — Summary Strip', () => {
    it('shows correct total count', () => {
        const switches = [baseSwitchData, { ...baseSwitchData, id: 2, name: 'Edge', enabled: false }];
        const wrapper = mountIndex(switches);
        const summary = wrapper.find('[data-testid="switches-summary"]');
        expect(summary.text()).toContain('2');
    });

    it('shows correct enabled count', () => {
        const switches = [
            { ...baseSwitchData, id: 1, enabled: true },
            { ...baseSwitchData, id: 2, enabled: false },
        ];
        const wrapper = mountIndex(switches);
        const summary = wrapper.find('[data-testid="switches-summary"]');
        expect(summary.text()).toContain('1');
    });
});

describe('Index — Name cell', () => {
    it('renders switch name', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="switch-name-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toBe('Core Switch');
    });
});

describe('Index — Hostname cell', () => {
    it('renders hostname with monospace font', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="switch-hostname-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toBe('10.0.0.1');
        expect(cell.classes()).toContain('font-mono');
    });
});

describe('Index — Type cell', () => {
    it('renders type label via typeLabel util', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="switch-type-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toBe('label:cisco');
    });
});

describe('Index — Status cell', () => {
    it('renders "Enabled" when switch is enabled', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="switch-status-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toContain('Enabled');
    });

    it('renders "Disabled" when switch is disabled', () => {
        const sw = { ...baseSwitchData, enabled: false };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="switch-status-1"]');
        expect(cell.text()).toContain('Disabled');
    });

    it('renders success dot for enabled switch', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="switch-status-1"]');
        const dot = cell.find('.rounded-full');
        expect(dot.classes().some((c) => c.includes('color-success'))).toBe(true);
    });

    it('renders muted dot for disabled switch', () => {
        const sw = { ...baseSwitchData, enabled: false };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="switch-status-1"]');
        const dot = cell.find('.rounded-full');
        expect(dot.classes().some((c) => c.includes('color-text-muted'))).toBe(true);
    });
});

describe('Index — Port Breakdown cell', () => {
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

    it('hides down count when ports_down is 0', () => {
        const sw = { ...baseSwitchData, ports_down: 0 };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="port-breakdown-1"]');
        expect(cell.text()).not.toContain('↓');
    });

    it('hides error count when ports_error is 0', () => {
        const sw = { ...baseSwitchData, ports_error: 0 };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="port-breakdown-1"]');
        expect(cell.text()).not.toContain('⚠');
    });

    it('shows dash when port_count is 0', () => {
        const sw = { ...baseSwitchData, port_count: 0, ports_up: 0, ports_down: 0, ports_error: 0 };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="port-breakdown-1"]');
        expect(cell.text()).toBe('—');
    });
});

describe('Index — Sync Status cell', () => {
    it('shows "Completed" for completed sync', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.exists()).toBe(true);
        expect(cell.text()).toContain('Completed');
    });

    it('shows "Failed" for failed sync', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'failed' };
        const wrapper = mountIndex([sw]);
        expect(wrapper.find('[data-testid="sync-status-1"]').text()).toContain('Failed');
    });

    it('shows "Running" for running sync', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'running', last_synced_at: null };
        const wrapper = mountIndex([sw]);
        expect(wrapper.find('[data-testid="sync-status-1"]').text()).toContain('Running');
    });

    it('shows "Pending" for pending sync', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'pending', last_synced_at: null };
        const wrapper = mountIndex([sw]);
        expect(wrapper.find('[data-testid="sync-status-1"]').text()).toContain('Pending');
    });

    it('shows "Never synced" when no sync data', () => {
        const sw = { ...baseSwitchData, latest_sync_status: null, last_synced_at: null };
        const wrapper = mountIndex([sw]);
        expect(wrapper.find('[data-testid="sync-status-1"]').text()).toContain('Never synced');
    });

    it('uses formatRelative for sync timestamp', () => {
        const wrapper = mountIndex();
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).toContain('relative:2024-06-15T10:30:00Z');
    });

    it('does not show timestamp when last_synced_at is null', () => {
        const sw = { ...baseSwitchData, latest_sync_status: 'running', last_synced_at: null };
        const wrapper = mountIndex([sw]);
        const cell = wrapper.find('[data-testid="sync-status-1"]');
        expect(cell.text()).not.toContain('relative:');
    });
});

describe('Index — Sorting', () => {
    it('renders sort button for port_count column', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="sort-port_count"]').exists()).toBe(true);
    });

    it('renders sort buttons for all sortable columns', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="sort-name"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sort-hostname"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sort-type"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sort-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sort-port_count"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="sort-latest_sync_status"]').exists()).toBe(true);
    });

    it('sorts by port_count ascending on sort button click', async () => {
        const switches = [
            { ...baseSwitchData, id: 1, name: 'A', port_count: 10, ports_up: 10, ports_down: 0, ports_error: 0 },
            { ...baseSwitchData, id: 2, name: 'B', port_count: 30, ports_up: 30, ports_down: 0, ports_error: 0 },
            { ...baseSwitchData, id: 3, name: 'C', port_count: 5, ports_up: 5, ports_down: 0, ports_error: 0 },
        ];
        const wrapper = mountIndex(switches);

        await wrapper.find('[data-testid="sort-port_count"]').trigger('click');
        await wrapper.vm.$nextTick();

        const portCells = wrapper.findAll('[data-testid^="port-breakdown-"]');
        expect(portCells[0].attributes('data-testid')).toBe('port-breakdown-3');
        expect(portCells[1].attributes('data-testid')).toBe('port-breakdown-1');
        expect(portCells[2].attributes('data-testid')).toBe('port-breakdown-2');
    });

    it('toggles sort direction on second click', async () => {
        const wrapper = mountIndex();
        const btn = wrapper.find('[data-testid="sort-name"]');
        // First click: already sorted by name asc, so direction flips to desc
        await btn.trigger('click');
        await wrapper.vm.$nextTick();
        // Second click: direction flips back to asc
        await btn.trigger('click');
        await wrapper.vm.$nextTick();

        const nameHeader = wrapper.findAll('th').find((th) => th.text().includes('Name'));
        expect(nameHeader.attributes('aria-sort')).toBe('ascending');
    });

    it('sets aria-sort="none" on non-active sortable columns', () => {
        const wrapper = mountIndex();
        const portHeader = wrapper.findAll('th').find((th) => th.text().includes('Ports'));
        expect(portHeader.attributes('aria-sort')).toBe('none');
    });

    it('sets aria-sort="ascending" after clicking a column', async () => {
        const wrapper = mountIndex();
        await wrapper.find('[data-testid="sort-port_count"]').trigger('click');
        await wrapper.vm.$nextTick();

        const portHeader = wrapper.findAll('th').find((th) => th.text().includes('Ports'));
        expect(portHeader.attributes('aria-sort')).toBe('ascending');
    });

    it('sets aria-sort="descending" after clicking active column twice', async () => {
        const wrapper = mountIndex();
        // name is the default sort column; click once to flip to desc
        await wrapper.find('[data-testid="sort-name"]').trigger('click');
        await wrapper.vm.$nextTick();

        const nameHeader = wrapper.findAll('th').find((th) => th.text().includes('Name'));
        expect(nameHeader.attributes('aria-sort')).toBe('descending');
    });
});
