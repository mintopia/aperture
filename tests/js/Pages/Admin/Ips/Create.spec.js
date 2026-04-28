import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Create from '@/Pages/Admin/Ips/Create.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((initial) => ({
        ...initial,
        errors: {},
        processing: false,
        post: vi.fn(),
    })),
}));

window.route = vi.fn((name) => `/mocked/${name}`);

const FormFieldStub = {
    template: '<div><slot /></div>',
    props: ['label', 'name', 'error', 'required'],
};

function mountPage() {
    return mount(Create, {
        global: {
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                FormField: FormFieldStub,
            },
        },
    });
}

describe('Ips/Create', () => {
    it('renders the page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toContain('Add IP Address');
    });

    it('renders the form', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="ip-create-form"]').exists()).toBe(true);
    });

    it('renders address and comment inputs', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="input-address"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="input-comment"]').exists()).toBe(true);
    });

    it('renders allow and limit checkboxes', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="field-allow"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="field-limit"]').exists()).toBe(true);
    });

    // ── Checkbox design-system styling ──────────────────────────────────────

    it('applies design-system classes to the allow checkbox', () => {
        const wrapper = mountPage();
        const checkbox = wrapper.find('[data-testid="field-allow"]');
        expect(checkbox.exists()).toBe(true);
        expect(checkbox.classes()).toContain('h-4');
        expect(checkbox.classes()).toContain('w-4');
        expect(checkbox.classes()).toContain('bg-[var(--color-surface)]');
        expect(checkbox.classes()).toContain('accent-[var(--color-primary)]');
    });

    it('applies design-system classes to the limit checkbox', () => {
        const wrapper = mountPage();
        const checkbox = wrapper.find('[data-testid="field-limit"]');
        expect(checkbox.exists()).toBe(true);
        expect(checkbox.classes()).toContain('h-4');
        expect(checkbox.classes()).toContain('w-4');
        expect(checkbox.classes()).toContain('bg-[var(--color-surface)]');
        expect(checkbox.classes()).toContain('accent-[var(--color-primary)]');
    });

    it('renders submit button', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="action-submit"]').exists()).toBe(true);
    });
});
