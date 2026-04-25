import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { router } from '@inertiajs/vue3';
import Show from '@/Pages/Admin/Users/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name) => `/mocked/${name}`);

describe('Admin User Show block confirmation modal integration', () => {
    const defaultProps = {
        user: { id: 1, nickname: 'TestUser', email: 'test@example.com', internet_blocked: false },
        roles: [{ name: 'user' }],
        networkDevices: [
            {
                mac_address: 'aa:bb:cc:dd:ee:01',
                ip_address: '192.168.1.10',
                hostname: null,
                internet_enabled: true,
                rate_limit_enabled: false,
            },
            {
                mac_address: 'aa:bb:cc:dd:ee:02',
                ip_address: '192.168.1.11',
                hostname: null,
                internet_enabled: true,
                rate_limit_enabled: false,
            },
        ],
        allInternetEnabled: true,
        allRateLimited: false,
        ipCount: 2,
        auditLogs: [],
        downloaded: 1024,
        uploaded: 512,
    };

    const mountPage = (overrides = {}) =>
        mount(Show, {
            props: {
                ...defaultProps,
                ...overrides,
                user: {
                    ...defaultProps.user,
                    ...(overrides.user ?? {}),
                },
            },
            global: {
                mocks: {
                    route: (...args) => `/mocked/${args[0]}`,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MetadataStrip: { template: '<div data-testid="metadata-strip" />' },
                    SectionHeader: { template: '<div><slot /></div>' },
                    DataTable: { template: '<div data-testid="data-table" />' },
                    teleport: true,
                },
            },
        });

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('shows block confirmation modal when clicking block on an unblocked user', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-block"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Block User');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('shows unblock confirmation modal when clicking unblock on a blocked user', async () => {
        const wrapper = mountPage({
            user: { internet_blocked: true },
        });

        await wrapper.find('[data-testid="action-unblock"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Unblock User');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('canceling block confirmation closes modal without posting', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-block"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('canceling unblock confirmation closes modal without posting', async () => {
        const wrapper = mountPage({
            user: { internet_blocked: true },
        });

        await wrapper.find('[data-testid="action-unblock"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('confirming block action triggers router.post with correct arguments', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-block"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.users.block'),
            { block: 1 },
            expect.any(Object),
        );
    });

    it('confirming unblock action triggers router.post with correct arguments', async () => {
        const wrapper = mountPage({
            user: { internet_blocked: true },
        });

        await wrapper.find('[data-testid="action-unblock"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.users.block'),
            { block: 0 },
            expect.any(Object),
        );
    });

    it('modal shows IP count for the user', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-block"]').trigger('click');

        const modal = wrapper.find('[data-testid="confirm-modal"]');
        expect(modal.exists()).toBe(true);
        expect(modal.text()).toContain('2');
    });

    it('block modal message describes consequences', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-block"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal-message"]').text()).toMatch(/block|internet|denied/i);
    });

    it('unblock modal message describes consequences', async () => {
        const wrapper = mountPage({
            user: { internet_blocked: true },
        });

        await wrapper.find('[data-testid="action-unblock"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal-message"]').text()).toMatch(/unblock|restore|access/i);
    });
});
