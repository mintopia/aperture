import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import RecentActivity from '../RecentActivity.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

vi.mock('@/utils/dates', () => ({
    formatRelativeTime: vi.fn((v) => v ?? '—'),
}));

globalThis.route = (...args) => `/mocked/${args[0]}`;

const events = [
    {
        id: 1,
        action: 'switch.unreachable',
        description: 'Switch core-sw unreachable after 3 failures',
        severity: 'critical',
        created_at: '2026-06-12T10:00:00+00:00',
    },
    {
        id: 2,
        action: 'user.login',
        description: 'alice logged in',
        severity: 'info',
        created_at: '2026-06-12T09:59:00+00:00',
    },
];

function mountComponent(props = {}) {
    return mount(RecentActivity, {
        props: { events, ...props },
        global: {
            stubs: {
                SectionHeader: { template: '<div />' },
            },
            mocks: {
                route: globalThis.route,
            },
        },
    });
}

describe('RecentActivity', () => {
    it('renders one entry per event with its description', () => {
        const wrapper = mountComponent();
        const rows = wrapper.findAll('[data-testid="recent-activity-entry"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.text()).toContain('Switch core-sw unreachable after 3 failures');
    });

    it('marks the severity on each entry', () => {
        const wrapper = mountComponent();
        const dots = wrapper.findAll('[data-testid="recent-activity-severity"]');
        expect(dots[0].attributes('data-severity')).toBe('critical');
        expect(dots[1].attributes('data-severity')).toBe('info');
    });

    it('shows the empty state when there are no events', () => {
        const wrapper = mountComponent({ events: [] });
        expect(wrapper.find('[data-testid="recent-activity-empty"]').exists()).toBe(true);
    });

    it('links "View All" to the audit log', () => {
        const wrapper = mountComponent();
        const link = wrapper.find('[data-testid="recent-activity-view-all"]');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toBe('/mocked/admin.audit-log.index');
    });
});
