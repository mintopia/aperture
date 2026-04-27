import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Index from '@/Pages/Admin/Events/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: vi.fn(),
        reload: vi.fn(),
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
    formatRelativeTime: vi.fn((v) => v ?? '—'),
}));

globalThis.route = (...args) => `/mocked/${args[0]}`;

const mockEvents = [
    {
        id: 1,
        type: 'UserConnected',
        level: 'info',
        message: 'alice connected from 10.0.0.42',
        created_at: '2026-04-27T12:00:00+00:00',
    },
    {
        id: 2,
        type: 'SwitchUnreachable',
        level: 'critical',
        message: 'Switch edge-sw-03 unreachable after 3 failures',
        created_at: '2026-04-27T11:59:00+00:00',
    },
    {
        id: 3,
        type: 'DhcpPoolThresholdReached',
        level: 'warning',
        message: 'DHCP pool LAN reached 92% utilization',
        created_at: '2026-04-27T11:58:00+00:00',
    },
];

const defaultProps = {
    events: {
        data: mockEvents,
        current_page: 1,
        last_page: 3,
        per_page: 50,
        total: 42,
        links: [],
    },
    totalCount: 1247,
    filters: { search: '', perPage: 50 },
};

function mountIndex(propsOverride = {}) {
    return mount(Index, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                Pagination: { template: '<div data-testid="pagination" />', props: ['paginator'] },
            },
        },
    });
}

describe('Events/Index', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders page title', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Event Feed');
    });

    it('renders DataTable with event rows', () => {
        const wrapper = mountIndex();
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(3);
    });

    it('displays event type with correct text', () => {
        const wrapper = mountIndex();
        const types = wrapper.findAll('[data-testid="event-type"]');
        expect(types[0].text()).toBe('UserConnected');
        expect(types[1].text()).toBe('SwitchUnreachable');
    });

    it('displays event message', () => {
        const wrapper = mountIndex();
        const messages = wrapper.findAll('[data-testid="event-message"]');
        expect(messages[0].text()).toBe('alice connected from 10.0.0.42');
    });

    it('renders search input', () => {
        const wrapper = mountIndex();
        const input = wrapper.find('[data-testid="event-search"]');
        expect(input.exists()).toBe(true);
    });

    it('displays filtered and total counts', () => {
        const wrapper = mountIndex();
        const count = wrapper.find('[data-testid="event-count"]');
        expect(count.exists()).toBe(true);
        expect(count.text()).toContain('42');
        expect(count.text()).toContain('1,247');
    });

    it('renders pagination', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(true);
    });

    it('renders empty state when no events', () => {
        const wrapper = mountIndex({
            events: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, links: [] },
            totalCount: 0,
        });
        expect(wrapper.find('[data-testid="event-empty"]').exists()).toBe(true);
    });

    it('applies danger color class for critical events', () => {
        const wrapper = mountIndex();
        const types = wrapper.findAll('[data-testid="event-type"]');
        expect(types[1].classes().join(' ')).toContain('danger');
    });

    it('applies warning color class for warning events', () => {
        const wrapper = mountIndex();
        const types = wrapper.findAll('[data-testid="event-type"]');
        expect(types[2].classes().join(' ')).toContain('warning');
    });

    it('shows new events banner when live events arrive', async () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="new-events-banner"]').exists()).toBe(false);

        wrapper.vm.addLiveEvent({ type: 'UserConnected', message: 'bob connected' });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="new-events-banner"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="new-events-banner"]').text()).toContain('1');
    });
});
