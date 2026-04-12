import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Dashboard from '@/Pages/Admin/Dashboard.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name) => `/mocked/${name}`);

describe('Dashboard', () => {
    const defaultProps = {
        totalUsers: 10,
        onlineUsers: 5,
        totalIps: 20,
        allowedIps: 15,
    };

    const mountOptions = {
        props: defaultProps,
        global: {
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                StatCard: {
                    template: '<div data-testid="stat-card"><slot /></div>',
                    props: ['label', 'value', 'hero', 'color', 'accentBorder'],
                },
                teleport: true,
            },
        },
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders page title', () => {
        const wrapper = mount(Dashboard, mountOptions);
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Admin Dashboard');
    });

    it('renders stat cards', () => {
        const wrapper = mount(Dashboard, mountOptions);
        const cards = wrapper.findAll('[data-testid="stat-card"]');
        expect(cards.length).toBe(4);
    });

    it('renders reset button', () => {
        const wrapper = mount(Dashboard, mountOptions);
        expect(wrapper.find('[data-testid="reset-button"]').exists()).toBe(true);
    });

    it('shows confirmation modal when reset is clicked', async () => {
        const wrapper = mount(Dashboard, mountOptions);
        await wrapper.find('[data-testid="reset-button"]').trigger('click');
        expect(wrapper.find('[data-testid="reset-confirm-modal"]').exists()).toBe(true);
    });

    it('dispatches reset when confirmed', async () => {
        const { router } = await import('@inertiajs/vue3');

        router.post.mockImplementation((_url, _data, options) => {
            if (options && options.onFinish) {
                options.onFinish();
            }
        });

        const wrapper = mount(Dashboard, mountOptions);
        await wrapper.find('[data-testid="reset-button"]').trigger('click');
        await wrapper.find('[data-testid="reset-confirm-button"]').trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            expect.stringContaining('reset'),
            {},
            expect.any(Object),
        );

        // After onFinish, modal should be closed
        expect(wrapper.find('[data-testid="reset-confirm-modal"]').exists()).toBe(false);
    });

    it('closes modal on cancel', async () => {
        const wrapper = mount(Dashboard, mountOptions);
        await wrapper.find('[data-testid="reset-button"]').trigger('click');
        expect(wrapper.find('[data-testid="reset-confirm-modal"]').exists()).toBe(true);

        await wrapper.find('[data-testid="reset-cancel-button"]').trigger('click');
        expect(wrapper.find('[data-testid="reset-confirm-modal"]').exists()).toBe(false);
    });

    it('does not show modal initially', () => {
        const wrapper = mount(Dashboard, mountOptions);
        expect(wrapper.find('[data-testid="reset-confirm-modal"]').exists()).toBe(false);
    });
});
