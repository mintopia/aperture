import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Settings from '@/Pages/Admin/Content/Settings.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: vi.fn(),
        processing: false,
        errors: {},
    })),
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ url: '/admin/content/settings' }),
}));

window.route = vi.fn((name) => `/mocked/${name}`);

const FormFieldStub = {
    template: '<div><slot /></div>',
    props: ['label', 'name', 'error', 'required'],
};

function mountPage(settings = {}, pages = []) {
    return mount(Settings, {
        props: {
            settings: {
                site_title: '',
                dns_filtering_default: false,
                terms_type: 'url',
                terms_value: '',
                privacy_type: 'url',
                privacy_value: '',
                ...settings,
            },
            pages,
        },
        global: {
            stubs: {
                FormField: FormFieldStub,
                AdminLayout: { template: '<div><slot /></div>' },
            },
        },
    });
}

describe('Admin Content Settings page', () => {
    it('renders the page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('General Settings');
    });

    it('renders site title input', () => {
        const wrapper = mountPage({ site_title: 'My Site' });
        const input = wrapper.find('[data-testid="input-site-title"]');
        expect(input.exists()).toBe(true);
        expect(input.element.value).toBe('My Site');
    });

    it('renders DNS filtering toggle', () => {
        const wrapper = mountPage({ dns_filtering_default: true });
        const toggle = wrapper.find('[data-testid="toggle-dns-filtering"]');
        expect(toggle.exists()).toBe(true);
    });

    it('reflects DNS filtering default state as checked', () => {
        const wrapper = mountPage({ dns_filtering_default: true });
        const toggle = wrapper.find('[data-testid="toggle-dns-filtering"]');
        expect(toggle.element.checked).toBe(true);
    });

    it('reflects DNS filtering default state as unchecked', () => {
        const wrapper = mountPage({ dns_filtering_default: false });
        const toggle = wrapper.find('[data-testid="toggle-dns-filtering"]');
        expect(toggle.element.checked).toBe(false);
    });

    it('renders terms type selector', () => {
        const wrapper = mountPage();
        const select = wrapper.find('[data-testid="select-terms-type"]');
        expect(select.exists()).toBe(true);
    });

    it('renders privacy type selector', () => {
        const wrapper = mountPage();
        const select = wrapper.find('[data-testid="select-privacy-type"]');
        expect(select.exists()).toBe(true);
    });

    it('renders Branding section heading', () => {
        const wrapper = mountPage();
        const headings = wrapper.findAll('[data-testid^="section-heading"]');
        const texts = headings.map((h) => h.text());
        expect(texts).toContain('Branding');
    });

    it('renders Network Defaults section heading', () => {
        const wrapper = mountPage();
        const headings = wrapper.findAll('[data-testid^="section-heading"]');
        const texts = headings.map((h) => h.text());
        expect(texts).toContain('Network Defaults');
    });

    it('renders Legal section heading', () => {
        const wrapper = mountPage();
        const headings = wrapper.findAll('[data-testid^="section-heading"]');
        const texts = headings.map((h) => h.text());
        expect(texts).toContain('Legal');
    });

    it('shows page selector when terms_type is "page"', () => {
        const pages = [
            { id: 1, title: 'Terms Page', slug: 'terms' },
            { id: 2, title: 'Privacy Page', slug: 'privacy' },
        ];
        const wrapper = mountPage({ terms_type: 'page' }, pages);
        expect(wrapper.find('[data-testid="select-terms-page"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="input-terms-url"]').exists()).toBe(false);
    });

    it('shows URL input when terms_type is "url"', () => {
        const wrapper = mountPage({ terms_type: 'url' });
        expect(wrapper.find('[data-testid="input-terms-url"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="select-terms-page"]').exists()).toBe(false);
    });

    it('shows page selector when privacy_type is "page"', () => {
        const pages = [{ id: 1, title: 'Privacy Page', slug: 'privacy' }];
        const wrapper = mountPage({ privacy_type: 'page' }, pages);
        expect(wrapper.find('[data-testid="select-privacy-page"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="input-privacy-url"]').exists()).toBe(false);
    });

    it('shows URL input when privacy_type is "url"', () => {
        const wrapper = mountPage({ privacy_type: 'url' });
        expect(wrapper.find('[data-testid="input-privacy-url"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="select-privacy-page"]').exists()).toBe(false);
    });

    it('populates terms page selector with page options', () => {
        const pages = [
            { id: 1, title: 'Terms Page', slug: 'terms' },
            { id: 2, title: 'About Us', slug: 'about' },
        ];
        const wrapper = mountPage({ terms_type: 'page' }, pages);
        const select = wrapper.find('[data-testid="select-terms-page"]');
        const options = select.findAll('option');
        expect(options.some((o) => o.text() === 'Terms Page')).toBe(true);
        expect(options.some((o) => o.text() === 'About Us')).toBe(true);
    });

    it('has a save button', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="action-save"]').exists()).toBe(true);
    });

    it('calls form.put on submit', async () => {
        const { useForm } = await import('@inertiajs/vue3');
        const mockPut = vi.fn();
        useForm.mockReturnValueOnce({
            site_title: '',
            dns_filtering_default: false,
            terms_type: 'url',
            terms_value: '',
            privacy_type: 'url',
            privacy_value: '',
            put: mockPut,
            processing: false,
            errors: {},
        });
        const wrapper = mountPage();
        await wrapper.find('form').trigger('submit');
        expect(mockPut).toHaveBeenCalledWith('/mocked/admin.content.settings.update');
    });
});
