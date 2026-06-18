import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import DnsDetection from '@/Pages/Admin/Settings/DnsDetection.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: vi.fn(),
        processing: false,
        errors: {},
    })),
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ url: '/admin/settings/dns-detection' }),
}));

window.route = vi.fn((name) => `/mocked/${name}`);

const SettingsNavStub = { template: '<div><slot /></div>' };
const FormFieldStub = {
    template: '<div><slot /></div>',
    props: ['label', 'name', 'error', 'required'],
};

function mountPage(settings = {}) {
    return mount(DnsDetection, {
        props: {
            settings: {
                dns_check_url: '',
                dns_warning_message: '',
                ...settings,
            },
        },
        global: {
            stubs: {
                SettingsNav: SettingsNavStub,
                FormField: FormFieldStub,
                AdminLayout: { template: '<div><slot /></div>' },
            },
        },
    });
}

describe('DnsDetection settings page', () => {
    it('renders page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('DNS Detection');
    });

    it('renders check URL input with existing value', () => {
        const wrapper = mountPage({ dns_check_url: 'https://{uuid}.example.com' });
        const input = wrapper.find('[data-testid="input-dns-check-url"]');
        expect(input.exists()).toBe(true);
        expect(input.element.value).toBe('https://{uuid}.example.com');
    });

    it('renders warning message textarea with existing value', () => {
        const wrapper = mountPage({ dns_warning_message: 'Fix your DNS!' });
        const textarea = wrapper.find('[data-testid="input-dns-warning-message"]');
        expect(textarea.exists()).toBe(true);
        expect(textarea.element.value).toBe('Fix your DNS!');
    });

    it('renders save button', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="action-save"]').exists()).toBe(true);
    });
});
