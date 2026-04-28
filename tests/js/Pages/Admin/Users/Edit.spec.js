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
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
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

    const mockRoute = (name, params) => `/${name.replace(/\./g, '/')}/${params ?? ''}`;

    function mountEdit(user = defaultUser) {
        return mount(Edit, {
            props: { user },
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
                config: {
                    globalProperties: {
                        route: mockRoute,
                    },
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

    // ── Checkbox design-system styling ──────────────────────────────────────

    it('applies design-system classes to the clear-password checkbox', () => {
        const wrapper = mountEdit({ ...defaultUser, has_password: true });
        const checkbox = wrapper.find('[data-testid="edit-user-clear-password"]');
        expect(checkbox.exists()).toBe(true);
        expect(checkbox.classes()).toContain('h-4');
        expect(checkbox.classes()).toContain('w-4');
        expect(checkbox.classes()).toContain('bg-[var(--color-surface)]');
        expect(checkbox.classes()).toContain('accent-[var(--color-primary)]');
    });

    it('applies design-system classes to role checkboxes', () => {
        const wrapperWithRoles = mount(Edit, {
            props: {
                user: { ...defaultUser, has_password: true, roles: ['admin'] },
                availableRoles: [{ code: 'admin', name: 'Admin' }],
            },
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                },
                config: {
                    globalProperties: {
                        route: (name, params) => `/${name.replace(/\./g, '/')}/${params ?? ''}`,
                    },
                },
            },
        });
        const roleCheckbox = wrapperWithRoles.find('[data-testid="role-admin"]');
        expect(roleCheckbox.exists()).toBe(true);
        expect(roleCheckbox.classes()).toContain('h-4');
        expect(roleCheckbox.classes()).toContain('w-4');
        expect(roleCheckbox.classes()).toContain('bg-[var(--color-surface)]');
        expect(roleCheckbox.classes()).toContain('accent-[var(--color-primary)]');
    });
});
