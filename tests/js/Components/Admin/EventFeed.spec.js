import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import EventFeed from '@/Components/Admin/EventFeed.vue';

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

const mockEvents = [
    { id: 1, type: 'UserConnected', message: 'alice connected from 10.0.0.42', created_at: '2026-04-27T12:00:00+00:00' },
    { id: 2, type: 'SwitchUnreachable', message: 'Switch down', created_at: '2026-04-27T11:59:00+00:00' },
];

function mountFeed(props = {}) {
    return mount(EventFeed, {
        props: { events: mockEvents, ...props },
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

describe('EventFeed', () => {
    it('renders event entries from props', () => {
        const wrapper = mountFeed();
        const entries = wrapper.findAll('[data-testid="event-feed-entry"]');
        expect(entries).toHaveLength(2);
    });

    it('displays event messages', () => {
        const wrapper = mountFeed();
        const entries = wrapper.findAll('[data-testid="event-feed-entry"]');
        expect(entries[0].text()).toContain('alice connected from 10.0.0.42');
    });

    it('shows empty state when no events', () => {
        const wrapper = mountFeed({ events: [] });
        expect(wrapper.find('[data-testid="event-feed-empty"]').exists()).toBe(true);
    });

    it('renders View All link', () => {
        const wrapper = mountFeed();
        const link = wrapper.find('[data-testid="event-feed-view-all"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('View All');
    });

    it('renders timestamps', () => {
        const wrapper = mountFeed();
        const timestamps = wrapper.findAll('[data-testid="event-feed-timestamp"]');
        expect(timestamps).toHaveLength(2);
    });
});
