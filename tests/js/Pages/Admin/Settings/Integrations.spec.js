import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Integrations from '@/Pages/Admin/Settings/Integrations.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: vi.fn(),
        processing: false,
        errors: {},
    })),
    usePage: vi.fn(() => ({
        props: { flash: {} },
    })),
}));

vi.stubGlobal('route', vi.fn(() => '/admin/settings/integrations'));

describe('Integrations.vue', () => {
    const defaultProps = {
        integrations: {
            opnsense: {},
            librenms: {},
            ntopng: {},
            pihole: {},
            dhcp: {},
            dns: {},
            auto_allow: {},
            ipv6: {},
        },
    };

    function mountPage(props = {}) {
        return mount(Integrations, {
            props: { ...defaultProps, ...props },
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    SettingsNav: { template: '<div><slot /></div>' },
                    FormField: {
                        template: '<div><slot /></div>',
                        props: ['label', 'name', 'error'],
                    },
                },
            },
        });
    }

    it('renders all 8 integration sections', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-opnsense"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-librenms"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-pihole"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dns"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-auto-allow"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ipv6"]').exists()).toBe(true);
    });

    it('renders OPNsense new fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-opnsense-verify-ssl"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-opnsense-zone-id"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-opnsense-ratelimit-up-uuid"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-opnsense-ratelimit-down-uuid"]').exists()).toBe(true);
    });

    it('renders ntopng with username/password instead of api_key', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-ntopng-username"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng-password"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng-interface"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng-enabled"]').exists()).toBe(true);
    });

    it('renders LibreNMS enabled toggle', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-librenms-enabled"]').exists()).toBe(true);
    });

    it('renders PiHole enabled and verify_ssl toggles', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-pihole-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-pihole-verify-ssl"]').exists()).toBe(true);
    });

    it('renders DHCP section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-dhcp-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-endpoint"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-key"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-secret"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-verify-ssl"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-pool-size"]').exists()).toBe(true);
    });

    it('renders DNS Probe section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-dns-expected-server"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dns-probe-domain"]').exists()).toBe(true);
    });

    it('renders Auto Allow section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-auto-allow-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-auto-allow-oui-prefixes"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-auto-allow-scan-interval"]').exists()).toBe(true);
    });

    it('renders IPv6 Detection section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-ipv6-detection-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ipv6-detection-endpoint"]').exists()).toBe(true);
    });
});
