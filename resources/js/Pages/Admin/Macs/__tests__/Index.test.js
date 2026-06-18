import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Index from '../Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    router: { get: vi.fn() },
}));

const routeMock = vi.fn((name) => name);
vi.stubGlobal('route', routeMock);

const globalConfig = {
    stubs: ['AdminLayout', 'DataTable', 'FilterBar', 'Pagination', 'SectionHeader', 'Link'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

const mockMacs = {
    data: [
        {
            id: 1,
            mac_address: 'AA:BB:CC:DD:EE:01',
            hostname: 'test-host',
            current_ips: [{ id: 1, address: '10.0.0.1' }],
            user: { id: 1, nickname: 'testuser' },
            source: 'dhcp',
            created_at: '2026-04-23T00:00:00+00:00',
        },
    ],
    links: {},
    meta: {},
    total: 1,
};

describe('Macs/Index', () => {
    it('renders the page title', () => {
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('MAC Addresses');
    });

    it('renders macs index layout', () => {
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="macs-index-layout"]').exists()).toBe(true);
    });

    it('renders page header section', () => {
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="macs-index-header"]').exists()).toBe(true);
    });

    it('renders with empty data', () => {
        const wrapper = mount(Index, {
            props: { macs: { data: [], total: 0 }, filters: {} },
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('MAC Addresses');
    });

    it('drives a server-side request with the unified search term', () => {
        router.get.mockClear();
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: globalConfig,
        });

        wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('update:search', 'needle');

        expect(router.get).toHaveBeenCalledWith(
            'admin.macs.index',
            expect.objectContaining({ search: 'needle' }),
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('drives a server-side request when the source filter changes', () => {
        router.get.mockClear();
        const wrapper = mount(Index, {
            props: { macs: mockMacs, filters: {} },
            global: globalConfig,
        });

        wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('update:filter-values', { source: 'static' });

        expect(router.get).toHaveBeenCalledWith(
            'admin.macs.index',
            expect.objectContaining({ source: 'static' }),
            expect.objectContaining({ preserveState: true }),
        );
    });
});
