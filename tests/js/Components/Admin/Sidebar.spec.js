import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi } from 'vitest';
import Sidebar from '@/Components/Admin/Sidebar.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/users' }),
}));

const navExpectations = [
    ['dashboard', '/admin'],
    ['users', '/admin/users'],
    ['ip-addresses', '/admin/ips'],
    ['switches', '/admin/switches'],
    ['dhcp', '/admin/dhcp'],
    ['stats', '/admin/stats'],
    ['content', '/admin/content'],
    ['settings', '/admin/settings/integrations'],
];

function setViewport(width) {
    window.innerWidth = width;
    window.dispatchEvent(new Event('resize'));
}

async function mountSidebar(width = 1280) {
    setViewport(width);
    const wrapper = mount(Sidebar);
    await nextTick();

    return wrapper;
}

describe('Sidebar.vue', () => {
    it('renders section headers in the correct desktop order', async () => {
        const wrapper = await mountSidebar();
        const headers = wrapper.findAll('aside nav section > p').map((header) => header.text());

        expect(wrapper.get('[data-testid="admin-sidebar"]').exists()).toBe(true);
        expect(headers).toEqual(['OVERVIEW', 'MANAGEMENT', 'TOOLS']);
    });

    it('renders Dashboard under OVERVIEW', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[0].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual(['Dashboard']);
    });

    it('renders Users, IP Addresses, Switches, and DHCP under MANAGEMENT', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[1].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual([
            'Users',
            'IP Addresses',
            'Switches',
            'DHCP',
        ]);
    });

    it('renders Stats, Content, and Settings under TOOLS', async () => {
        const wrapper = await mountSidebar();
        const groups = wrapper.findAll('aside nav section');

        expect(groups[2].findAll('[data-testid^="nav-"]').map((item) => item.text())).toEqual([
            'Stats',
            'Content',
            'Settings',
        ]);
    });

    it('renders all nav items with correct hrefs and preserved test ids', async () => {
        const wrapper = await mountSidebar();

        for (const [testIdSuffix, href] of navExpectations) {
            const item = wrapper.get(`[data-testid="nav-${testIdSuffix}"]`);
            expect(item.attributes('href')).toBe(href);
        }
    });

    it('applies active styling with accent background and primary text', async () => {
        const wrapper = await mountSidebar();
        const activeItem = wrapper.get('[data-testid="nav-users"]');

        expect(activeItem.classes()).toContain('bg-[var(--color-accent-dim)]');
        expect(activeItem.classes()).toContain('text-[var(--color-primary)]');
        expect(activeItem.classes()).toContain('font-semibold');
    });

    it('applies secondary text color to non-active items', async () => {
        const wrapper = await mountSidebar();
        const inactiveItem = wrapper.get('[data-testid="nav-dashboard"]');

        expect(inactiveItem.classes()).toContain('text-[var(--color-text-secondary)]');
    });

    it('renders the Aperture branded header on desktop', async () => {
        const wrapper = await mountSidebar();

        expect(wrapper.text()).toContain('Aperture');
    });

    it('renders grouped horizontal navigation on mobile and includes all items', async () => {
        const wrapper = await mountSidebar(768);
        const mobileNav = wrapper.get('[data-testid="admin-nav-horizontal"]');
        const items = mobileNav.findAll('[data-testid^="nav-"]').map((item) => item.text());

        expect(mobileNav.exists()).toBe(true);
        expect(items).toEqual([
            'Dashboard',
            'Users',
            'IP Addresses',
            'Switches',
            'DHCP',
            'Stats',
            'Content',
            'Settings',
        ]);
    });
});
