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
        reload: vi.fn(),
    },
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({
        props: {
            auth: {
                user: {
                    is_admin: true,
                },
            },
        },
    }),
}));

// Mock layouts to passthroughs we can assert against
vi.mock('@/Layouts/PortalLayout.vue', () => ({
    default: {
        name: 'PortalLayout',
        template: '<div data-testid="portal-layout-mock"><slot /></div>',
    },
}));

vi.mock('@/Layouts/AdminLayout.vue', () => ({
    default: {
        name: 'AdminLayout',
        template: '<div data-testid="admin-layout-mock"><slot /></div>',
    },
}));

const mockRoute = (name) => `/${name.replace(/\./g, '/')}`;
globalThis.confirm = vi.fn(() => true);

// Mock fetch globally — passkey operations use fetch directly
globalThis.fetch = vi.fn(() =>
    Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ success: true }),
    }),
);

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
        { id: 'pk-2', name: 'Backup Key', created_at: '2024-02-01 00:00:00' },
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

    // ── Page wrapper ────────────────────────────────────────────────────────

    it('renders the settings page wrapper', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="settings-page"]').exists()).toBe(true);
    });

    // ── Verification gate ───────────────────────────────────────────────────

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

    // ── Password section ────────────────────────────────────────────────────

    it('shows password section when verified', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="password-section"]').exists()).toBe(true);
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

    // ── Layout selection ────────────────────────────────────────────────────

    it('renders with admin layout when user is admin', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="admin-layout-mock"]').exists()).toBe(true);
    });

    // ── Passkey section ─────────────────────────────────────────────────────

    it('hides passkey section when verification gate is active', () => {
        const wrapper = mountComponent(userWithPassword, false);
        expect(wrapper.find('[data-testid="verify-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(false);
    });

    it('shows passkey section when verified', () => {
        const wrapper = mountComponent(userWithPassword, true);
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(true);
    });

    it('shows passkey section for fresh user with no credentials', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(true);
    });

    // ── Passkey register button ─────────────────────────────────────────────

    it('renders passkey register button', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="passkey-register"]').exists()).toBe(true);
    });

    it('passkey register button is enabled by default', () => {
        const wrapper = mountComponent(userWithNeither, false);
        const btn = wrapper.find('[data-testid="passkey-register"]');
        expect(btn.attributes('disabled')).toBeUndefined();
    });

    // ── Passkey list ────────────────────────────────────────────────────────

    it('shows passkey-list when user has passkeys', () => {
        const wrapper = mountComponent(userWithPasskeys, true);
        expect(wrapper.find('[data-testid="passkey-list"]').exists()).toBe(true);
    });

    it('does not show passkey-list when user has no passkeys', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="passkey-list"]').exists()).toBe(false);
    });

    it('renders passkey-item-{id} for each passkey', () => {
        const wrapper = mountComponent(userWithPasskeys, true);
        expect(wrapper.find('[data-testid="passkey-item-pk-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="passkey-item-pk-2"]').exists()).toBe(true);
    });

    it('shows passkey names in the list', () => {
        const wrapper = mountComponent(userWithPasskeys, true);
        expect(wrapper.find('[data-testid="passkey-list"]').text()).toContain('My Passkey');
        expect(wrapper.find('[data-testid="passkey-list"]').text()).toContain('Backup Key');
    });

    // ── Passkey delete buttons ──────────────────────────────────────────────

    it('renders passkey-delete-{id} button for each passkey', () => {
        const wrapper = mountComponent(userWithPasskeys, true);
        expect(wrapper.find('[data-testid="passkey-delete-pk-1"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="passkey-delete-pk-2"]').exists()).toBe(true);
    });

    // ── Error state ─────────────────────────────────────────────────────────

    it('does not show passkey-error by default', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="passkey-error"]').exists()).toBe(false);
    });

    it('shows passkey-error when passkeyError is set', async () => {
        const wrapper = mountComponent(userWithNeither, false);

        // Simulate error by triggering register with a failing fetch
        globalThis.fetch = vi.fn(() => Promise.reject(new Error('Network error')));
        await wrapper.find('[data-testid="passkey-register"]').trigger('click');
        await wrapper.vm.$nextTick();
        await new Promise((r) => setTimeout(r, 10));

        expect(wrapper.find('[data-testid="passkey-error"]').exists()).toBe(true);
    });

    it('shows friendly error when passkey register response is not valid JSON', async () => {
        const wrapper = mountComponent(userWithNeither, false);

        globalThis.fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: () =>
                    Promise.resolve({
                        challenge: 'Y2hhbGxlbmdl',
                        user: { id: 'dXNlci1pZA' },
                        excludeCredentials: [],
                    }),
            })
            .mockResolvedValueOnce({
                ok: true,
                json: () => Promise.reject(new SyntaxError('Unexpected token < in JSON at position 0')),
            });

        globalThis.navigator.credentials = {
            create: vi.fn(() =>
                Promise.resolve({
                    id: 'cred-id',
                    rawId: new Uint8Array([1, 2, 3]).buffer,
                    type: 'public-key',
                    response: {
                        attestationObject: new Uint8Array([4, 5, 6]).buffer,
                        clientDataJSON: new Uint8Array([7, 8, 9]).buffer,
                    },
                }),
            ),
        };

        await wrapper.find('[data-testid="passkey-register"]').trigger('click');
        await wrapper.vm.$nextTick();
        await new Promise((r) => setTimeout(r, 10));

        const error = wrapper.find('[data-testid="passkey-error"]');
        expect(error.exists()).toBe(true);
        expect(error.text()).not.toContain('Unexpected token');
    });

    // ── Empty state ─────────────────────────────────────────────────────────

    it('shows empty state text when no passkeys registered', () => {
        const wrapper = mountComponent(userWithPassword, true);
        const section = wrapper.find('[data-testid="passkey-section"]');
        expect(section.text()).toContain('No passkeys registered yet');
    });

    // ── Both sections present for fresh user ───────────────────────────────

    it('shows both sections when user has neither password nor passkeys', () => {
        const wrapper = mountComponent(userWithNeither, false);
        expect(wrapper.find('[data-testid="password-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(true);
    });
});
