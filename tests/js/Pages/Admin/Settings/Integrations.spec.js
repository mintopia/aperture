import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Integrations from '@/Pages/Admin/Settings/Integrations.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: { visit: vi.fn() },
    usePage: vi.fn(() => ({
        props: { flash: {} },
    })),
}));

describe('Integrations.vue', () => {
    const defaultServices = [
        {
            id: 'borealis',
            name: 'Borealis',
            enabled: true,
            health: null,
            readonly: true,
            capabilities: [],
        },
        {
            id: 'opnsense',
            name: 'OPNsense',
            enabled: true,
            health: true,
            capabilities: [
                { name: 'captive-portal', active: true },
                { name: 'rate-limiting', active: false },
            ],
        },
        {
            id: 'librenms',
            name: 'LibreNMS',
            enabled: false,
            health: false,
            capabilities: [{ name: 'ip-mac', active: false }],
        },
    ];

    function mountPage(props = {}) {
        return mount(Integrations, {
            props: { services: defaultServices, ...props },
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    SettingsNav: { template: '<div><slot /></div>' },
                },
            },
        });
    }

    it('renders the page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Integrations');
    });

    it('renders the integrations table', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="integrations-table"]').exists()).toBe(true);
    });

    it('renders a row for each service', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="integration-row-borealis"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-row-opnsense"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-row-librenms"]').exists()).toBe(true);
    });

    it('shows read-only badge for borealis', () => {
        const wrapper = mountPage();
        const row = wrapper.find('[data-testid="integration-row-borealis"]');
        expect(row.text()).toContain('Read only');
    });

    it('shows enabled/disabled status pills', () => {
        const wrapper = mountPage();
        const opnsenseRow = wrapper.find('[data-testid="integration-row-opnsense"]');
        expect(opnsenseRow.text()).toContain('Enabled');

        const librenmsRow = wrapper.find('[data-testid="integration-row-librenms"]');
        expect(librenmsRow.text()).toContain('Disabled');
    });

    it('shows health indicators', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="integration-health-opnsense"]').text()).toBe('Healthy');
        expect(wrapper.find('[data-testid="integration-health-librenms"]').text()).toBe('Unhealthy');
    });

    it('renders capability tags with active/inactive state', () => {
        const wrapper = mountPage();
        const activeTag = wrapper.find('[data-testid="integration-capability-opnsense-captive-portal"]');
        expect(activeTag.exists()).toBe(true);

        const inactiveTag = wrapper.find('[data-testid="integration-capability-opnsense-rate-limiting"]');
        expect(inactiveTag.exists()).toBe(true);
    });

    it('navigates to service config on row click for non-readonly', async () => {
        const { router } = await import('@inertiajs/vue3');
        const wrapper = mountPage();

        await wrapper.find('[data-testid="integration-row-opnsense"]').trigger('click');
        expect(router.visit).toHaveBeenCalledWith('/admin/settings/integrations/opnsense');
    });

    it('does not navigate on readonly row click', async () => {
        const { router } = await import('@inertiajs/vue3');
        router.visit.mockClear();
        const wrapper = mountPage();

        await wrapper.find('[data-testid="integration-row-borealis"]').trigger('click');
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('shows empty state when no services', () => {
        const wrapper = mountPage({ services: [] });
        expect(wrapper.find('[data-testid="integrations-empty"]').exists()).toBe(true);
    });
});
