import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/settings/integrations' }),
}));

globalThis.route = (name) => `/admin/settings/${name.replace('admin.settings.', '')}`;

describe('SettingsNav.vue', () => {
    function mountComponent() {
        return mount(SettingsNav, {
            slots: {
                default: '<div>Settings content</div>',
            },
        });
    }

    it('renders all group headers', () => {
        const wrapper = mountComponent();

        expect(wrapper.text()).toContain('INTEGRATIONS');
        expect(wrapper.text()).toContain('FEATURES');
        expect(wrapper.text()).toContain('APPEARANCE');
        expect(wrapper.text()).toContain('GENERAL');
    });

    it('renders all nav items including disabled ones', () => {
        const wrapper = mountComponent();

        expect(wrapper.find('[data-testid="settings-nav-services"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-switches"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-auto-allow"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-ipv6-detection"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-dns-warning"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-theme"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-event"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-portal"]').exists()).toBe(true);
    });

    it('disabled items have opacity-40 class and no href', () => {
        const wrapper = mountComponent();
        const disabledItems = [
            wrapper.get('[data-testid="settings-nav-auto-allow"]'),
            wrapper.get('[data-testid="settings-nav-ipv6-detection"]'),
            wrapper.get('[data-testid="settings-nav-dns-warning"]'),
        ];

        for (const item of disabledItems) {
            expect(item.classes()).toContain('opacity-40');
            expect(item.classes()).toContain('cursor-not-allowed');
            expect(item.attributes('href')).toBeUndefined();
        }
    });

    it('active item gets highlighted class', () => {
        const wrapper = mountComponent();
        const activeItem = wrapper.get('[data-testid="settings-nav-services"]');

        expect(activeItem.classes()).toContain('bg-[var(--color-primary)]/10');
        expect(activeItem.classes()).toContain('font-semibold');
        expect(activeItem.classes()).toContain('text-[var(--color-primary)]');
    });

    it('clicking a disabled item does not navigate', async () => {
        const wrapper = mountComponent();
        const disabledItem = wrapper.get('[data-testid="settings-nav-auto-allow"]');
        const initialUrl = window.location.href;

        await disabledItem.trigger('click');

        expect(disabledItem.attributes('href')).toBeUndefined();
        expect(window.location.href).toBe(initialUrl);
    });
});
