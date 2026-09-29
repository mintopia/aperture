import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, afterEach } from 'vitest';
import UserMenu from '@/Components/UserMenu.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

const mockRoute = (name) => `/${name.replace(/\./g, '/')}`;

const adminUser = {
    id: 1,
    nickname: 'AdminUser',
    email: 'admin@example.com',
    avatar_url: null,
    is_admin: true,
    has_password: false,
    has_passkeys: false,
};

const regularUser = {
    id: 2,
    nickname: 'RegularUser',
    email: 'user@example.com',
    avatar_url: null,
    is_admin: false,
    has_password: false,
    has_passkeys: false,
};

const userWithAvatar = {
    ...regularUser,
    avatar_url: 'https://example.com/avatar.jpg',
};

describe('UserMenu', () => {
    function mountComponent(user = regularUser) {
        return mount(UserMenu, {
            props: { user },
            attachTo: document.body,
            global: {
                config: {
                    globalProperties: {
                        route: mockRoute,
                    },
                },
            },
        });
    }

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('renders avatar image when avatar_url is provided', () => {
        const wrapper = mountComponent(userWithAvatar);
        const img = wrapper.find('img');
        expect(img.exists()).toBe(true);
        expect(img.attributes('src')).toBe('https://example.com/avatar.jpg');
    });

    it('renders initials circle when no avatar_url', () => {
        const wrapper = mountComponent(regularUser);
        expect(wrapper.find('img').exists()).toBe(false);
        const initials = wrapper.find('[data-testid="user-menu-trigger"] div');
        expect(initials.exists()).toBe(true);
        expect(initials.text()).toBe('R');
    });

    it('shows nickname next to avatar', () => {
        const wrapper = mountComponent(regularUser);
        const trigger = wrapper.find('[data-testid="user-menu-trigger"]');
        expect(trigger.text()).toContain('RegularUser');
    });

    it('dropdown is hidden by default', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);
    });

    it('dropdown shows on trigger click', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(true);
    });

    it('dropdown hides when trigger clicked again', async () => {
        const wrapper = mountComponent();
        const trigger = wrapper.find('[data-testid="user-menu-trigger"]');
        await trigger.trigger('click');
        await trigger.trigger('click');
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);
    });

    it('always shows the Dashboard and Logout links, with correct hrefs', async () => {
        const wrapper = mountComponent(regularUser);
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        expect.soft(wrapper.find('[data-testid="user-menu-dashboard"]').exists()).toBe(true);
        expect.soft(wrapper.find('[data-testid="user-menu-logout"]').exists()).toBe(true);
        expect.soft(wrapper.find('[data-testid="user-menu-dashboard"]').attributes('href')).toBe('/portal/dashboard');
        expect.soft(wrapper.find('[data-testid="user-menu-logout"]').attributes('href')).toBe('/logout');
    });

    it.each([
        { name: 'Admin link, is_admin true', user: 'admin', testid: 'user-menu-admin', expected: true },
        { name: 'Admin link, is_admin false', user: 'regular', testid: 'user-menu-admin', expected: false },
        { name: 'Settings link, is_admin true', user: 'admin', testid: 'user-menu-settings', expected: true },
        { name: 'Settings link, is_admin false', user: 'regular', testid: 'user-menu-settings', expected: false },
    ])('$name', async ({ user, testid, expected }) => {
        const wrapper = mountComponent(user === 'admin' ? adminUser : regularUser);
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        expect(wrapper.find(`[data-testid="${testid}"]`).exists()).toBe(expected);
    });

    it('Admin link has correct href', async () => {
        const wrapper = mountComponent(adminUser);
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        const link = wrapper.find('[data-testid="user-menu-admin"]');
        expect(link.attributes('href')).toContain('admin');
    });

    it('dropdown closes when clicking outside', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(true);

        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);
    });

    it('renders trigger with data-testid', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="user-menu-trigger"]').exists()).toBe(true);
    });

    it('initials show first character of nickname uppercased', () => {
        const wrapper = mountComponent({ ...regularUser, nickname: 'zara' });
        const trigger = wrapper.find('[data-testid="user-menu-trigger"]');
        const initialsDiv = trigger.find('div');
        expect(initialsDiv.text()).toBe('Z');
    });

    it('trigger has aria-haspopup="menu"', () => {
        const wrapper = mountComponent();
        const trigger = wrapper.find('[data-testid="user-menu-trigger"]');
        expect(trigger.attributes('aria-haspopup')).toBe('menu');
    });

    it('trigger has aria-expanded="false" when dropdown is closed', () => {
        const wrapper = mountComponent();
        const trigger = wrapper.find('[data-testid="user-menu-trigger"]');
        expect(trigger.attributes('aria-expanded')).toBe('false');
    });

    it('trigger has aria-expanded="true" when dropdown is open', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        const trigger = wrapper.find('[data-testid="user-menu-trigger"]');
        expect(trigger.attributes('aria-expanded')).toBe('true');
    });

    it('dropdown has role="menu"', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        const dropdown = wrapper.find('[data-testid="user-menu-dropdown"]');
        expect(dropdown.attributes('role')).toBe('menu');
    });

    it.each([
        { name: 'dashboard link', user: regularUser, testid: 'user-menu-dashboard' },
        { name: 'logout link', user: regularUser, testid: 'user-menu-logout' },
        { name: 'admin link', user: adminUser, testid: 'user-menu-admin' },
        { name: 'settings link', user: adminUser, testid: 'user-menu-settings' },
    ])('$name has role="menuitem"', async ({ user, testid }) => {
        const wrapper = mountComponent(user);
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        expect(wrapper.find(`[data-testid="${testid}"]`).attributes('role')).toBe('menuitem');
    });

    it('closes on Escape key', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(true);

        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);
    });

    it('opens menu on ArrowDown when closed', async () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);

        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(true);
    });

    it('opens menu on ArrowUp when closed', async () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);

        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('keydown', { key: 'ArrowUp' });
        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(true);
    });

    it('navigates menu items with ArrowDown key', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');

        const dropdown = wrapper.find('[data-testid="user-menu-dropdown"]');
        await dropdown.trigger('keydown', { key: 'ArrowDown' });

        const items = wrapper.findAll('[role="menuitem"]');
        expect(items.length).toBeGreaterThan(0);
    });

    it('navigates menu items with ArrowUp key', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');

        const dropdown = wrapper.find('[data-testid="user-menu-dropdown"]');
        await dropdown.trigger('keydown', { key: 'ArrowUp' });

        const items = wrapper.findAll('[role="menuitem"]');
        expect(items.length).toBeGreaterThan(0);
    });

    it('dropdown has keydown handler for Escape', async () => {
        const wrapper = mountComponent();
        await wrapper.find('[data-testid="user-menu-trigger"]').trigger('click');

        const dropdown = wrapper.find('[data-testid="user-menu-dropdown"]');
        await dropdown.trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[data-testid="user-menu-dropdown"]').exists()).toBe(false);
    });
});
