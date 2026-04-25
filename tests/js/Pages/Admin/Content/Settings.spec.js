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

vi.mock('@/composables/useTheme.js', () => ({
    useTheme: () => ({
        previewMode: vi.fn(),
        cancelPreview: vi.fn(),
    }),
}));

vi.mock('@/composables/useAccentColor.js', () => ({
    ACCENT_PRESETS: [
        { name: 'Gold', hue: 55, c: 0.19, l: 72 },
        { name: 'Blue', hue: 230, c: 0.19, l: 72 },
    ],
    applyAccentColor: vi.fn(),
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
                terms_type: 'url',
                terms_value: '',
                privacy_type: 'url',
                privacy_value: '',
                theme_mode: 'dark',
                accent_hue: 55,
                accent_chroma: 0.19,
                accent_lightness: 72,
                custom_css: '',
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
            terms_type: 'url',
            terms_value: '',
            privacy_type: 'url',
            privacy_value: '',
            theme_mode: 'dark',
            accent_hue: 55,
            accent_chroma: 0.19,
            accent_lightness: 72,
            custom_css: '',
            put: mockPut,
            processing: false,
            errors: {},
        });
        const wrapper = mountPage();
        await wrapper.find('form').trigger('submit');
        expect(mockPut).toHaveBeenCalledWith('/mocked/admin.content.settings.update');
    });

    it('renders accent color presets', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="accent-preset-55"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="accent-preset-230"]').exists()).toBe(true);
    });

    it('renders hue slider', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="accent-hue-slider"]').exists()).toBe(true);
    });

    it('renders chroma slider', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="accent-chroma-slider"]').exists()).toBe(true);
    });

    it('renders lightness slider', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="accent-lightness-slider"]').exists()).toBe(true);
    });

    it('renders accent preview swatch', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="accent-preview-swatch"]').exists()).toBe(true);
    });

    it('renders mode buttons', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="mode-option-light"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="mode-option-dark"]').exists()).toBe(true);
    });

    it('renders custom CSS textarea', () => {
        const wrapper = mountPage({ custom_css: 'body { color: red; }' });
        const textarea = wrapper.find('[data-testid="input-custom_css"]');
        expect(textarea.exists()).toBe(true);
        expect(textarea.element.value).toBe('body { color: red; }');
    });

    it('constrains sliders to max-w-sm width', () => {
        const wrapper = mountPage();
        const sliderContainer = wrapper.find('[data-testid="accent-hue-slider"]').element.closest('.max-w-sm');
        expect(sliderContainer).not.toBeNull();
    });
});
