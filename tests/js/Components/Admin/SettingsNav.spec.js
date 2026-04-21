import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';

let mockUrl = '/admin/settings/integrations';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: mockUrl }),
}));

globalThis.route = (name, params) => {
    const base = 'https://aperture.local.js42.io';

    if (name === 'admin.settings.integrations.show') {
        return `${base}/admin/settings/integrations/${params}`;
    }

    if (name === 'admin.settings.integrations') {
        return `${base}/admin/settings/integrations`;
    }

    if (name === 'admin.switches.index') {
        return `${base}/admin/switches`;
    }

    return `${base}/admin/settings/${name.replace('admin.settings.', '')}`;
};

describe('SettingsNav.vue', () => {
    function mountComponent(url = '/admin/settings/integrations') {
        mockUrl = url;

        return mount(SettingsNav, {
            slots: {
                default: '<div>Settings content</div>',
            },
        });
    }

    it('renders the expected group headers', () => {
        const wrapper = mountComponent();
        const groupHeaders = wrapper.findAll('nav p').map((group) => group.text());

        expect(groupHeaders).toEqual(['SERVICES', 'APPEARANCE', 'GENERAL']);
        expect(wrapper.text()).not.toContain('FEATURES');
    });

    it('renders all nav items', () => {
        const wrapper = mountComponent();

        expect(wrapper.find('[data-testid="settings-nav-integrations"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-ipv6-detection"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-dns-detection"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-theme"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-event"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="settings-nav-portal"]').exists()).toBe(true);
    });

    it('renders three services items in the expected order', () => {
        const wrapper = mountComponent();
        const groups = wrapper.findAll('nav > div > div');
        const serviceItems = groups[0].findAll('[data-testid^="settings-nav-"]');

        expect(serviceItems).toHaveLength(3);
        expect(serviceItems.map((item) => item.text().replace(/\s+/g, ' ').trim())).toEqual([
            'Integrations',
            'IPv6 Detection',
            'DNS Detection Soon',
        ]);
        expect(wrapper.get('[data-testid="settings-nav-integrations"]').attributes('href')).toBe(
            'https://aperture.local.js42.io/admin/settings/integrations',
        );
        expect(wrapper.get('[data-testid="settings-nav-ipv6-detection"]').attributes('href')).toBe(
            'https://aperture.local.js42.io/admin/settings/ipv6-detection',
        );
    });

    it('renders disabled items as spans without href', () => {
        const wrapper = mountComponent();
        const dns = wrapper.get('[data-testid="settings-nav-dns-detection"]');

        expect(dns.element.tagName).toBe('SPAN');
        expect(dns.attributes('href')).toBeUndefined();
        expect(dns.classes()).toContain('cursor-not-allowed');
        expect(dns.classes()).toContain('opacity-40');
    });

    it('renders IPv6 Detection as a clickable link', () => {
        const wrapper = mountComponent();
        const ipv6 = wrapper.get('[data-testid="settings-nav-ipv6-detection"]');

        expect(ipv6.element.tagName).toBe('A');
        expect(ipv6.attributes('href')).toBe('https://aperture.local.js42.io/admin/settings/ipv6-detection');
        expect(ipv6.classes()).not.toContain('opacity-40');
        expect(ipv6.classes()).not.toContain('cursor-not-allowed');
    });

    it('renders enabled nav items as clickable links', () => {
        const wrapper = mountComponent();
        const enabledItems = wrapper
            .findAll('[data-testid^="settings-nav-"]')
            .filter((item) => item.element.tagName === 'A');

        for (const item of enabledItems) {
            expect(item.attributes('href')).toBeTruthy();
            expect(item.classes()).not.toContain('opacity-40');
            expect(item.classes()).not.toContain('cursor-not-allowed');
        }

        expect(enabledItems).toHaveLength(5);
    });

    it('highlights integrations link on the overview page', () => {
        const wrapper = mountComponent('/admin/settings/integrations');
        const activeItem = wrapper.get('[data-testid="settings-nav-integrations"]');

        expect(activeItem.classes()).toContain('bg-[var(--color-accent-dim)]');
        expect(activeItem.classes()).toContain('font-semibold');
        expect(activeItem.classes()).toContain('text-[var(--color-primary)]');
    });

    it('highlights integrations link on a service sub-page', () => {
        const wrapper = mountComponent('/admin/settings/integrations/opnsense');
        const activeItem = wrapper.get('[data-testid="settings-nav-integrations"]');

        expect(activeItem.classes()).toContain('bg-[var(--color-accent-dim)]');
        expect(activeItem.classes()).toContain('font-semibold');
        expect(activeItem.classes()).toContain('text-[var(--color-primary)]');
    });

    it('keeps appearance and general groups unchanged', () => {
        const wrapper = mountComponent();
        const groups = wrapper.findAll('nav > div > div');

        expect(groups[1].findAll('[data-testid^="settings-nav-"]').map((item) => item.text())).toEqual(['Theme']);
        expect(wrapper.get('[data-testid="settings-nav-theme"]').attributes('href')).toBe(
            'https://aperture.local.js42.io/admin/settings/theme',
        );
        expect(groups[2].findAll('[data-testid^="settings-nav-"]').map((item) => item.text())).toEqual([
            'Event',
            'Portal',
        ]);
        expect(wrapper.get('[data-testid="settings-nav-event"]').attributes('href')).toBe(
            'https://aperture.local.js42.io/admin/settings/event',
        );
        expect(wrapper.get('[data-testid="settings-nav-portal"]').attributes('href')).toBe(
            'https://aperture.local.js42.io/admin/settings/portal',
        );
    });

    it('renders slot content', () => {
        const wrapper = mountComponent();

        expect(wrapper.text()).toContain('Settings content');
    });
});
