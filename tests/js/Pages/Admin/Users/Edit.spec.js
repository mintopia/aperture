import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Edit from '@/Pages/Admin/Users/Edit.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((initial) => ({
        ...initial,
        errors: {},
        processing: false,
        put: vi.fn(),
        reset: vi.fn(),
    })),
    Head: { template: '<div />' },
}));

describe('Edit User', () => {
    const defaultUser = {
        id: 1,
        nickname: 'testuser',
        email: 'test@example.com',
        blocked: 0,
        has_password: false,
        roles: ['admin'],
        avatar_url: null,
    };

    function mountEdit(user = defaultUser) {
        return mount(Edit, {
            props: { user },
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
            },
        });
    }

    it('renders edit page title', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="edit-user-title"]').text()).toContain('Edit User');
    });

    it('renders nickname and email fields', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="edit-user-nickname"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-user-email"]').exists()).toBe(true);
    });

    it('renders password fields', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="edit-user-password"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-user-password-confirm"]').exists()).toBe(true);
    });

    it('shows no-password indicator when user has no password', () => {
        const wrapper = mountEdit({ ...defaultUser, has_password: false });
        expect(wrapper.find('[data-testid="no-password-indicator"]').exists()).toBe(true);
    });

    it('shows has-password indicator when user has password', () => {
        const wrapper = mountEdit({ ...defaultUser, has_password: true });
        expect(wrapper.find('[data-testid="has-password-indicator"]').exists()).toBe(true);
    });

    it('shows clear password checkbox when user has password', () => {
        const wrapper = mountEdit({ ...defaultUser, has_password: true });
        expect(wrapper.find('[data-testid="edit-user-clear-password"]').exists()).toBe(true);
    });

    it('hides clear password checkbox when user has no password', () => {
        const wrapper = mountEdit({ ...defaultUser, has_password: false });
        expect(wrapper.find('[data-testid="edit-user-clear-password"]').exists()).toBe(false);
    });

    it('renders submit and cancel buttons', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="edit-user-submit"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="edit-user-cancel"]').exists()).toBe(true);
    });
});
