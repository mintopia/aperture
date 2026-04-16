import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Settings from '@/Pages/Account/Settings.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((initialData) => ({
        ...initialData,
        errors: {},
        processing: false,
        post: vi.fn(),
        put: vi.fn(),
        reset: vi.fn(),
    })),
    router: {
        delete: vi.fn(),
    },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

// Mock PortalLayout to a passthrough
vi.mock('@/Layouts/PortalLayout.vue', () => ({
    default: {
        name: 'PortalLayout',
        template: '<div><slot /></div>',
    },
}));

const mockRoute = (name) => `/${name.replace(/\./g, '/')}`;
globalThis.confirm = vi.fn(() => true);

const userWithPassword = {
    id: 1,
    nickname: 'TestUser',
    email: 'test@example.com',
    has_password: true,
    passkeys: [],
};

const userWithPasskeys = {
    id: 2,
    nickname: 'PasskeyUser',
    email: 'pk@example.com',
    has_password: false,
    passkeys: [
        { id: 'pk-1', name: 'My Passkey', created_at: '2024-01-01 00:00:00' },
    ],
};

const userWithNeither = {
    id: 3,
    nickname: 'FreshUser',
    email: 'fresh@example.com',
    has_password: false,
    passkeys: [],
};

describe('Account/Settings', () => {
    function mountComponent(user = userWithPassword, verified = false) {
        return mount(Settings, {
            props: { user, verified },
            global: {
                config: {
                    globalProperties: {
                        route: mockRoute,
                    },
                },
            },
        });
    }

    it('renders the settings page wrapper', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="settings-page"]').exists()).toBe(true);
    });

    it('shows verification form when user has password and is not verified', () => {
        const wrapper = mountComponent(userWithPassword, false);
        expect(wrapper.find('[data-testid="verify-form"]').exists()).toBe(true);
    });

    it('shows verification form when user has passkeys and is not verified', () => {
        const wrapper = mountComponent(userWithPasskeys, false);
        expect(wrapper.find('[data-testid="verify-form"]').exists()).toBe(true);
    });

    it('hides verification form when verified is true', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="verify-form"]').exists()).toBe(false);
    });

    it('shows settings directly when user has neither password nor passkeys', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="verify-form"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="password-section"]').exists()).toBe(true);
    });

    it('shows verify password input in verification form', () => {
        const wrapper = mountComponent(userWithPassword, false);
        expect(wrapper.find('[data-testid="verify-password"]').exists()).toBe(true);
    });

    it('shows verify submit button in verification form', () => {
        const wrapper = mountComponent(userWithPassword, false);
        expect(wrapper.find('[data-testid="verify-submit"]').exists()).toBe(true);
    });

    it('shows password section when verified', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="password-section"]').exists()).toBe(true);
    });

    it('shows passkey section when verified', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(true);
    });

    it('shows new password input in password section', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="password-new"]').exists()).toBe(true);
    });

    it('shows confirm password input in password section', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="password-confirm"]').exists()).toBe(true);
    });

    it('shows save password button', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="password-save"]').exists()).toBe(true);
    });

    it('shows clear password button when user has a password', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="password-clear"]').exists()).toBe(true);
    });

    it('hides clear password button when user has no password', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="password-clear"]').exists()).toBe(false);
    });

    it('shows passkeys in the passkey section', () => {
        const wrapper = mountComponent(userWithPasskeys, true);
        expect(wrapper.find('[data-testid="passkey-section"]').text()).toContain('My Passkey');
    });

    it('shows empty state when no passkeys registered', () => {
        const wrapper = mountComponent(userWithPassword, true);
        const section = wrapper.find('[data-testid="passkey-section"]');
        expect(section.text()).toContain('No passkeys registered');
    });

    it('shows both sections when user has neither password nor passkeys', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="password-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(true);
    });
});
