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
    formatVlan: vi.fn((vlan) => `${vlan ?? '—'}`),
}));

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
    canDownloadConfig: false,
    runningConfig: '',
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
                SectionHeader: { template: '<div><slot /></div>', props: ['title'] },
                StatusPill: {
                    template: '<span>{{ label }}</span>',
                    props: ['status', 'label'],
                },
                ConfigBlock: { template: '<div />', props: ['code'] },
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

    it('renders all filter chips with counts', () => {
        const wrapper = mountShow();
        expect(wrapper.find('[data-testid="port-filter-all"]').text()).toContain('All (4)');
        expect(wrapper.find('[data-testid="port-filter-up"]').text()).toContain('Up (2)');
        expect(wrapper.find('[data-testid="port-filter-down"]').text()).toContain('Down (1)');
        expect(wrapper.find('[data-testid="port-filter-errors"]').text()).toContain('Errors (1)');
    });

    it('displays correct count "Showing X of Y ports"', () => {
        const wrapper = mountShow();
        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 4 of 4 ports');
    });

    it('search filters ports by interface name', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('Gi1/0/1');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
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

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
    });

    it('search filters by VLAN', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('200');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
    });

    it('search is case-insensitive', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('server room');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
    });

    it('search is debounced at 300ms', async () => {
        const wrapper = mountShow();
        const input = wrapper.find('[data-testid="port-search"]');

        await input.setValue('Server Room');
        await input.trigger('input');

        // Before debounce fires, all ports should still be visible
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 4 of 4 ports');

        // After debounce fires
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
    });

    it('filter chip "Up" shows only connected ports', async () => {
        const wrapper = mountShow();
        await wrapper.find('[data-testid="port-filter-up"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 2 of 4 ports');
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(2);
    });

    it('filter chip "Down" shows only down/notconnect ports', async () => {
        const wrapper = mountShow();
        await wrapper.find('[data-testid="port-filter-down"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('filter chip "Errors" shows only err-disabled ports', async () => {
        const wrapper = mountShow();
        await wrapper.find('[data-testid="port-filter-errors"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(1);
    });

    it('"All" chip resets filter', async () => {
        const wrapper = mountShow();

        // First apply a filter
        await wrapper.find('[data-testid="port-filter-up"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 2 of 4 ports');

        // Reset with "All"
        await wrapper.find('[data-testid="port-filter-all"]').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 4 of 4 ports');
    });

    it('combined search + filter works', async () => {
        const wrapper = mountShow();

        // Apply "Up" filter first (2 connected ports: Gi1/0/1 and Gi1/0/2)
        await wrapper.find('[data-testid="port-filter-up"]').trigger('click');
        await wrapper.vm.$nextTick();

        // Then search for "AP-Lobby" (only Gi1/0/2)
        const input = wrapper.find('[data-testid="port-search"]');
        await input.setValue('AP-Lobby');
        await input.trigger('input');
        vi.advanceTimersByTime(300);
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="port-filter-count"]').text()).toBe('Showing 1 of 4 ports');
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

    it('active filter chip has primary styling', async () => {
        const wrapper = mountShow();

        // "All" is active by default
        const allChip = wrapper.find('[data-testid="port-filter-all"]');
        expect(allChip.classes()).toContain('text-[var(--color-primary)]');
        expect(allChip.attributes('aria-pressed')).toBe('true');

        // "Up" should be inactive
        const upChip = wrapper.find('[data-testid="port-filter-up"]');
        expect(upChip.classes()).toContain('text-[var(--color-text-secondary)]');
        expect(upChip.attributes('aria-pressed')).toBe('false');

        // Click "Up" chip
        await upChip.trigger('click');
        await wrapper.vm.$nextTick();

        const updatedUpChip = wrapper.find('[data-testid="port-filter-up"]');
        expect(updatedUpChip.classes()).toContain('text-[var(--color-primary)]');
        expect(updatedUpChip.attributes('aria-pressed')).toBe('true');

        const updatedAllChip = wrapper.find('[data-testid="port-filter-all"]');
        expect(updatedAllChip.classes()).toContain('text-[var(--color-text-secondary)]');
        expect(updatedAllChip.attributes('aria-pressed')).toBe('false');
    });

    it('shows DataTable empty message when switch has no ports at all', () => {
        const wrapper = mountShow({ ports: [] });
        expect(wrapper.find('[data-testid="data-table-empty"]').exists()).toBe(true);
    });
});
