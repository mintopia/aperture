import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Login from '@/Pages/Auth/Login.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn(() => ({
        email: '',
        password: '',
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
    })),
    Head: { template: '<div />' },
}));

describe('Login', () => {
    function mountLogin() {
        return mount(Login);
    }

    it('renders login title', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-title"]').text()).toBe('Sign In');
    });

    it('renders email input', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-email"]').exists()).toBe(true);
    });

    it('renders password input', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-password"]').exists()).toBe(true);
    });

    it('renders submit button', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-submit"]').text()).toBe('Sign In');
    });

    it('renders passkey button as enabled', () => {
        const wrapper = mountLogin();
        const btn = wrapper.find('[data-testid="login-passkey"]');
        expect(btn.exists()).toBe(true);
        expect(btn.attributes('disabled')).toBeUndefined();
    });

    it('renders captive portal link', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-captive-link"]').exists()).toBe(true);
    });

    it('has login form', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-form"]').exists()).toBe(true);
    });

    it('does not show passkey error by default', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="passkey-error"]').exists()).toBe(false);
    });

    it('renders passkey section', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="passkey-section"]').exists()).toBe(true);
    });
});
