import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import CaptivePortalApi from '@/Pages/Admin/Settings/CaptivePortalApi.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: vi.fn(),
        processing: false,
        errors: {},
    })),
    router: { on: vi.fn() },
    usePage: vi.fn(() => ({ props: { theme: {} } })),
    Link: { template: '<a><slot /></a>', props: ['href'] },
}));

window.route = vi.fn((name) => `/mocked/${name}`);

const FormFieldStub = {
    template: '<div><slot /></div>',
    props: ['label', 'name', 'error', 'required'],
};

function mountPage(settings = {}, apiUrl = 'https://example.com/api/captive-portal') {
    return mount(CaptivePortalApi, {
        props: {
            settings: {
                user_portal_url: '',
                venue_info_url: '',
                can_extend_session: false,
                ...settings,
            },
            apiUrl,
        },
        global: {
            stubs: {
                FormField: FormFieldStub,
                AdminLayout: { template: '<div><slot /></div>' },
            },
        },
    });
}

describe('CaptivePortalApi.vue', () => {
    beforeEach(() => {
        Object.assign(navigator, {
            clipboard: { writeText: vi.fn().mockResolvedValue(undefined) },
        });
    });

    it('renders the page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toContain('RFC 8908');
    });

    it('displays the API URL', () => {
        const wrapper = mountPage({}, 'https://test.example.com/api/captive-portal');
        const display = wrapper.find('[data-testid="api-url-display"]');
        expect(display.text()).toContain('https://test.example.com/api/captive-portal');
    });

    it('renders DHCP option sections', () => {
        const wrapper = mountPage();
        const options = wrapper.find('[data-testid="dhcp-options"]');
        expect(options.exists()).toBe(true);
        expect(options.text()).toContain('Option 114');
        expect(options.text()).toContain('Option 103');
        expect(options.text()).toContain('Type 37');
    });

    it('renders the settings form', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="captive-portal-api-form"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="input-user-portal-url"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="input-venue-info-url"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="toggle-can-extend-session"]').exists()).toBe(true);
    });

    it('renders the save button', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="action-save"]').text()).toBe('Save Settings');
    });

    it('populates form with saved settings', () => {
        const wrapper = mountPage({
            user_portal_url: 'https://portal.example.com',
            venue_info_url: 'https://venue.example.com',
            can_extend_session: true,
        });
        expect(wrapper.find('[data-testid="input-user-portal-url"]').element.value).toBe(
            'https://portal.example.com',
        );
        expect(wrapper.find('[data-testid="input-venue-info-url"]').element.value).toBe(
            'https://venue.example.com',
        );
        expect(wrapper.find('[data-testid="toggle-can-extend-session"]').element.checked).toBe(true);
    });

    it('renders the example response', () => {
        const wrapper = mountPage({ user_portal_url: 'https://portal.example.com' });
        const example = wrapper.find('[data-testid="example-response"]');
        expect(example.exists()).toBe(true);
        expect(example.text()).toContain('"captive": true');
        expect(example.text()).toContain('https://portal.example.com');
    });

    it('shows copy buttons for DHCP options', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="copy-api-url"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="copy-dhcpv4"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="copy-dhcpv6"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="copy-ra"]').exists()).toBe(true);
    });

    it('copies API URL to clipboard', async () => {
        const wrapper = mountPage({}, 'https://test.example.com/api/captive-portal');
        await wrapper.find('[data-testid="copy-api-url"]').trigger('click');
        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
            'https://test.example.com/api/captive-portal',
        );
    });

    it('renders section headings', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="section-heading-endpoint"]').text()).toContain('API Endpoint');
        expect(wrapper.find('[data-testid="section-heading-dhcp"]').text()).toContain('DHCP');
        expect(wrapper.find('[data-testid="section-heading-response"]').text()).toContain('Response Settings');
        expect(wrapper.find('[data-testid="section-heading-example"]').text()).toContain('Example Response');
    });

    it('renders ISC DHCP config snippets', () => {
        const wrapper = mountPage({}, 'https://example.com/api/captive-portal');
        expect(wrapper.text()).toContain('option captive-portal code 114 = text;');
        expect(wrapper.text()).toContain('option dhcp6.capport code 103 = text;');
        expect(wrapper.text()).toContain('AdvCaptivePortalAPI');
    });
});
