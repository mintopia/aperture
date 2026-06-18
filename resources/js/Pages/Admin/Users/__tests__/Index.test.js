import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Index from '../Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { get: vi.fn() },
}));

const routeMock = vi.fn((name) => name);
vi.stubGlobal('route', routeMock);

const globalConfig = {
    stubs: ['AdminLayout', 'DataTable', 'FilterBar', 'Pagination'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

const mockUsers = {
    data: [
        {
            id: 1,
            nickname: 'alice',
            email: 'alice@example.test',
            ips: [],
            weekly_received: 0,
            weekly_sent: 0,
            internet_blocked: false,
        },
    ],
    total: 1,
};

describe('Users/Index', () => {
    it('renders the page title', () => {
        const wrapper = mount(Index, {
            props: { users: mockUsers, filters: {} },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Users');
    });

    it('renders summary counts from the summary prop, not the loaded page', () => {
        const wrapper = mount(Index, {
            props: {
                users: mockUsers, // only 1 row loaded on this page
                summary: { total: 10, active: 7, blocked: 3 },
                filters: {},
            },
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="summary-total-value"]').text()).toBe('10');
        expect(wrapper.find('[data-testid="summary-active-value"]').text()).toBe('7');
        expect(wrapper.find('[data-testid="summary-blocked-value"]').text()).toBe('3');
    });

    it('drives a server-side request when searching', () => {
        router.get.mockClear();
        const wrapper = mount(Index, {
            props: { users: mockUsers, filters: {} },
            global: globalConfig,
        });

        wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('update:search', 'alice');

        expect(router.get).toHaveBeenCalledWith(
            'admin.users.index',
            expect.objectContaining({ search: 'alice' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('drives a server-side request when the status filter changes', () => {
        router.get.mockClear();
        const wrapper = mount(Index, {
            props: { users: mockUsers, filters: {} },
            global: globalConfig,
        });

        wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('update:filter-values', { status: 'blocked' });

        expect(router.get).toHaveBeenCalledWith(
            'admin.users.index',
            expect.objectContaining({ status: 'blocked' }),
            expect.objectContaining({ preserveState: true }),
        );
    });
});
