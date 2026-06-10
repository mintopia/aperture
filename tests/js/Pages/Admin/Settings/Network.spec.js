import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Network from '@/Pages/Admin/Settings/Network.vue';

const mockPut = vi.fn();
const mockPost = vi.fn();
let forms = [];

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        useForm: vi.fn((data) => {
            const form = reactive({
                ...data,
                put: mockPut,
                post: mockPost,
                reset: vi.fn(),
                clearErrors: vi.fn(),
                processing: false,
                errors: {},
            });
            forms.push(form);
            return form;
        }),
        Link: { template: '<a><slot /></a>' },
        usePage: () => ({ url: '/admin/settings/network' }),
    };
});

window.route = vi.fn((name) => `/mocked/${name}`);

const FormFieldStub = {
    template: '<div><slot /></div>',
    props: {
        label: { type: String, default: '' },
        name: { type: String, default: '' },
        error: { type: String, default: '' },
        required: { type: Boolean, default: false },
    },
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
                teleport: true,
            },
        },
    });
}

// The second useForm() call in Network.vue creates the clear-mappings form.
const clearForm = () => forms[1];

describe('Network settings page', () => {
    beforeEach(() => {
        forms = [];
        mockPut.mockReset();
        mockPost.mockReset();
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

    it('DNS filter checkbox reflects dns_filter_default prop value', () => {
        const wrapper = mountPage({ dns_filter_default: true });
        const checkbox = wrapper.find('[data-testid="toggle-dns-filter-default"]');
        expect(checkbox.element.checked).toBe(true);
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

    describe('maintenance: clear stale IP to MAC mappings', () => {
        async function openModal(wrapper) {
            await wrapper.find('[data-testid="clear-ip-mac-button"]').trigger('click');
        }

        it('marks the days field as required', () => {
            const wrapper = mountPage();
            const daysField = wrapper.findAllComponents(FormFieldStub).find((field) => field.props('name') === 'days');

            expect(daysField).toBeDefined();
            expect(daysField.props('required')).toBe(true);
        });

        it('does not set aria-invalid or aria-describedby on the password input without an error', async () => {
            const wrapper = mountPage();
            await openModal(wrapper);

            const input = wrapper.find('[data-testid="clear-ip-mac-password-input"]');
            expect(input.attributes('aria-invalid')).toBeUndefined();
            expect(input.attributes('aria-describedby')).toBeUndefined();
        });

        it('announces the password error to screen readers', async () => {
            const wrapper = mountPage();
            await openModal(wrapper);

            clearForm().errors = { password: 'The password is incorrect.' };
            await nextTick();

            const input = wrapper.find('[data-testid="clear-ip-mac-password-input"]');
            expect(input.attributes('aria-invalid')).toBe('true');
            expect(input.attributes('aria-describedby')).toBe('clear-ip-mac-password-error');

            const error = wrapper.find('[data-testid="clear-ip-mac-password-error"]');
            expect(error.attributes('id')).toBe('clear-ip-mac-password-error');
            expect(error.attributes('role')).toBe('alert');
            expect(error.text()).toBe('The password is incorrect.');
        });

        it('submits on Enter in the password input when not processing', async () => {
            const wrapper = mountPage();
            await openModal(wrapper);

            await wrapper.find('[data-testid="clear-ip-mac-password-input"]').trigger('keydown.enter');

            expect(mockPost).toHaveBeenCalledTimes(1);
        });

        it('ignores Enter in the password input while the clear request is processing', async () => {
            const wrapper = mountPage();
            await openModal(wrapper);

            clearForm().processing = true;
            await nextTick();

            await wrapper.find('[data-testid="clear-ip-mac-password-input"]').trigger('keydown.enter');

            expect(mockPost).not.toHaveBeenCalled();
        });
    });
});
