import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Edit from '@/Pages/Admin/Content/Pages/Edit.vue';
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        delete: vi.fn(),
    },
    useForm: vi.fn((initial) => ({
        ...initial,
        errors: {},
        processing: false,
        put: vi.fn(),
    })),
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

const defaultPage = {
    id: 1,
    title: 'About Us',
    slug: 'about-us',
    content: '## About\n\nWe are a team.',
    updated_at: '2026-04-20T10:00:00Z',
};

const mockRoute = (name, params) => {
    if (name === 'admin.content.pages.update') return `/admin/content/pages/${params}`;
    if (name === 'admin.content.pages.destroy') return `/admin/content/pages/${params}`;
    if (name === 'admin.content.pages.index') return '/admin/content/pages';
    return `/mocked/${name}`;
};

globalThis.route = mockRoute;

describe('Pages/Edit', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    function mountEdit(page = defaultPage) {
        return mount(Edit, {
            props: { page },
            global: {
                mocks: {
                    route: mockRoute,
                },
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    MarkdownEditor: {
                        template:
                            '<div data-testid="markdown-editor"><textarea :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></div>',
                        props: ['modelValue'],
                        emits: ['update:modelValue'],
                    },
                    teleport: true,
                },
            },
        });
    }

    it('renders page-edit container', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="page-edit"]').exists()).toBe(true);
    });

    it('renders "Edit Page" heading', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('h1').text()).toBe('Edit Page');
    });

    it('renders page title input with pre-populated value', () => {
        const wrapper = mountEdit();
        const input = wrapper.find('[data-testid="input-title"]');
        expect(input.exists()).toBe(true);
        expect(input.element.value).toBe('About Us');
    });

    it('renders slug input with /content/ prefix', () => {
        const wrapper = mountEdit();
        const slugInput = wrapper.find('[data-testid="input-slug"]');
        expect(slugInput.exists()).toBe(true);
        expect(slugInput.element.value).toBe('about-us');
        // prefix addon should be present
        expect(wrapper.text()).toContain('/content/');
    });

    it('has save button with data-testid="action-save"', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="action-save"]').exists()).toBe(true);
    });

    it('has cancel button with data-testid="action-cancel"', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="action-cancel"]').exists()).toBe(true);
    });

    it('has delete button with data-testid="action-delete"', () => {
        const wrapper = mountEdit();
        expect(wrapper.find('[data-testid="action-delete"]').exists()).toBe(true);
    });

    it('opens confirm modal when delete is clicked', async () => {
        const wrapper = mountEdit();
        await wrapper.find('[data-testid="action-delete"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
    });

    it('calls router.delete when delete is confirmed', async () => {
        const wrapper = mountEdit();
        await wrapper.find('[data-testid="action-delete"]').trigger('click');
        await flushPromises();
        await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');
        await flushPromises();
        expect(router.delete).toHaveBeenCalledWith(
            `/admin/content/pages/${defaultPage.id}`,
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });

    it('cancel closes modal without deleting', async () => {
        const wrapper = mountEdit();
        await wrapper.find('[data-testid="action-delete"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
        expect(router.delete).not.toHaveBeenCalled();
    });
});
