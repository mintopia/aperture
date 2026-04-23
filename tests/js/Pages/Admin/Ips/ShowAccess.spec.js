import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { router } from '@inertiajs/vue3';
import Show from '@/Pages/Admin/Ips/Show.vue';

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

describe('Admin IP Show access confirmation modal integration', () => {
    const defaultProps = {
        ip: { id: 1, address: '192.168.1.10', internet_enabled: true, comment: 'Test IP' },
        port: { interface: 'Gi1/0/1' },
        switchInfo: null,
        users: [
            { user: { id: 1, nickname: 'Alice' }, last_seen_at: '2024-01-01T00:00:00Z' },
            { user: { id: 2, nickname: 'Bob' }, last_seen_at: '2024-01-01T00:00:00Z' },
        ],
    };

    const mountPage = (overrides = {}) =>
        mount(Show, {
            props: {
                ...defaultProps,
                ...overrides,
                ip: {
                    ...defaultProps.ip,
                    ...(overrides.ip ?? {}),
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

    it('shows revoke confirmation modal when clicking revoke on an allowed IP', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-revoke"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Revoke Access');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('shows grant confirmation modal when clicking grant on a denied IP', async () => {
        const wrapper = mountPage({
            ip: { internet_enabled: false },
        });

        await wrapper.find('[data-testid="action-grant"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="confirm-modal-title"]').text()).toContain('Grant Access');
        expect(router.post).not.toHaveBeenCalled();
    });

    it('canceling revoke confirmation closes modal without posting', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-revoke"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('canceling grant confirmation closes modal without posting', async () => {
        const wrapper = mountPage({
            ip: { internet_enabled: false },
        });

        await wrapper.find('[data-testid="action-grant"]').trigger('click');
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('confirming revoke action triggers router.post with correct arguments', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-revoke"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.ips.internet'),
            { allow: 0 },
            expect.any(Object),
        );
    });

    it('confirming grant action triggers router.post with correct arguments', async () => {
        const wrapper = mountPage({
            ip: { internet_enabled: false },
        });

        await wrapper.find('[data-testid="action-grant"]').trigger('click');
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.ips.internet'),
            { allow: 1 },
            expect.any(Object),
        );
    });

    it('modal shows user count when users exist', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-revoke"]').trigger('click');

        const modal = wrapper.find('[data-testid="confirm-modal"]');
        expect(modal.exists()).toBe(true);
        expect(modal.text()).toContain('2');
    });

    it('revoke modal message describes consequences', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="action-revoke"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal-message"]').text()).toMatch(/revoke|deny|internet|block/i);
    });

    it('grant modal message describes consequences', async () => {
        const wrapper = mountPage({
            ip: { internet_enabled: false },
        });

        await wrapper.find('[data-testid="action-grant"]').trigger('click');

        expect(wrapper.find('[data-testid="confirm-modal-message"]').text()).toMatch(/grant|restore|allow|access/i);
    });

    it('enable rate limit button calls router.post with limit: 1 when rate limiting is disabled', async () => {
        const wrapper = mountPage({
            ip: { rate_limit_enabled: false },
        });

        await wrapper.find('[data-testid="action-enable-rate-limit"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.ips.limit'),
            { limit: 1 },
            expect.any(Object),
        );
    });

    it('disable rate limit button calls router.post with limit: 0 when rate limiting is enabled', async () => {
        const wrapper = mountPage({
            ip: { rate_limit_enabled: true },
        });

        await wrapper.find('[data-testid="action-disable-rate-limit"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.ips.limit'),
            { limit: 0 },
            expect.any(Object),
        );
    });

    it('enable dns filter button calls router.post with filter: 1 when dns filtering is disabled', async () => {
        const wrapper = mountPage({
            ip: { dns_filtering_enabled: false },
        });

        await wrapper.find('[data-testid="action-enable-dns-filter"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.ips.dns-filter'),
            { filter: 1 },
            expect.any(Object),
        );
    });

    it('disable dns filter button calls router.post with filter: 0 when dns filtering is enabled', async () => {
        const wrapper = mountPage({
            ip: { dns_filtering_enabled: true },
        });

        await wrapper.find('[data-testid="action-disable-dns-filter"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('admin.ips.dns-filter'),
            { filter: 0 },
            expect.any(Object),
        );
    });
});
