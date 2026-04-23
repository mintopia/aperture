import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Network from '@/Pages/Admin/Settings/Network.vue';

const mockPut = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: mockPut,
        processing: false,
        errors: {},
    })),
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ url: '/admin/settings/network' }),
}));

window.route = vi.fn((name) => `/mocked/${name}`);

const FormFieldStub = {
    template: '<div><slot /></div>',
    props: ['label', 'name', 'error', 'required'],
};

function mountPage(settings = {}) {
    return mount(Network, {
        props: {
            settings: {
                managed_ranges_v4: '',
                managed_ranges_v6: '',
                dns_filter_default: false,
                ...settings,
            },
        },
        global: {
            stubs: {
                FormField: FormFieldStub,
                AdminLayout: { template: '<div><slot /></div>' },
            },
        },
    });
}

describe('Network settings page', () => {
    beforeEach(() => {
        mockPut.mockReset();
    });

    it('renders page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Network Settings');
    });

    it('renders managed ranges section heading', () => {
        const wrapper = mountPage();
        const heading = wrapper.find('[data-testid="section-heading-ranges"]');
        expect(heading.exists()).toBe(true);
        expect(heading.text()).toBe('Managed Network Ranges');
    });

    it('renders IPv4 textarea with data-testid', () => {
        const wrapper = mountPage({ managed_ranges_v4: '10.0.0.0/8' });
        const textarea = wrapper.find('[data-testid="input-managed-ranges-v4"]');
        expect(textarea.exists()).toBe(true);
        expect(textarea.element.value).toBe('10.0.0.0/8');
    });

    it('renders IPv6 textarea with data-testid', () => {
        const wrapper = mountPage({ managed_ranges_v6: 'fc00::/7' });
        const textarea = wrapper.find('[data-testid="input-managed-ranges-v6"]');
        expect(textarea.exists()).toBe(true);
        expect(textarea.element.value).toBe('fc00::/7');
    });

    it('renders DNS filter default checkbox with data-testid', () => {
        const wrapper = mountPage();
        const checkbox = wrapper.find('[data-testid="toggle-dns-filter-default"]');
        expect(checkbox.exists()).toBe(true);
        expect(checkbox.element.type).toBe('checkbox');
    });

    it('renders Network Defaults section heading', () => {
        const wrapper = mountPage();
        const heading = wrapper.find('[data-testid="section-heading-defaults"]');
        expect(heading.exists()).toBe(true);
        expect(heading.text()).toBe('Network Defaults');
    });

    it('renders save button', () => {
        const wrapper = mountPage();
        const button = wrapper.find('[data-testid="action-save"]');
        expect(button.exists()).toBe(true);
        expect(button.text()).toBe('Save Settings');
    });

    it('submits form via PUT', async () => {
        const wrapper = mountPage();

        await wrapper.find('[data-testid="network-settings-form"]').trigger('submit');

        expect(mockPut).toHaveBeenCalledWith('/mocked/admin.settings.network.update');
    });
});
