import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import Edit from '@/Pages/Admin/Switches/Edit.vue';
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

globalThis.route = (...args) => `/mocked/${args[0]}`;

describe('Switches/Edit', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    function mountEdit() {
        return mount(Edit, {
            props: {
                switchConfig: {
                    id: 1,
                    name: 'Core',
                    hostname: '10.0.0.1',
                    type: 'cisco',
                    username: 'admin',
                    community: null,
                    port: 22,
                    timeout: 5,
                    enabled: true,
                },
                switchTypes: [
                    { value: 'cisco', label: 'Cisco IOS' },
                    { value: 'snmp', label: 'SNMP' },
                ],
            },
            global: {
                mocks: {
                    route: (name) => `/mocked/${name}`,
                },
                stubs: {
                    FormField: { template: '<div><slot /></div>' },
                    teleport: true,
                },
            },
        });
    }

    it('shows enable password field when switch type is cisco', () => {
        const wrapper = mountEdit();

        expect(wrapper.find('[data-testid="switch-enable-password"]').exists()).toBe(true);
    });

    it('clears hidden credential fields when switching to snmp', async () => {
        const wrapper = mountEdit();

        await wrapper.find('[data-testid="switch-password"]').setValue('new-password');
        await wrapper.find('[data-testid="switch-enable-password"]').setValue('enable-password');
        await wrapper.find('[data-testid="switch-type"]').setValue('snmp');
        await wrapper.find('[data-testid="switch-type"]').setValue('cisco');

        expect(wrapper.find('[data-testid="switch-username"]').element.value).toBe('');
        expect(wrapper.find('[data-testid="switch-password"]').element.value).toBe('');
        expect(wrapper.find('[data-testid="switch-enable-password"]').element.value).toBe('');
    });

    it('clears community when leaving snmp type', async () => {
        const wrapper = mountEdit();

        await wrapper.find('[data-testid="switch-type"]').setValue('snmp');
        await wrapper.find('[data-testid="switch-community"]').setValue('private');
        await wrapper.find('[data-testid="switch-type"]').setValue('cisco');
        await wrapper.find('[data-testid="switch-type"]').setValue('snmp');

        expect(wrapper.find('[data-testid="switch-community"]').element.value).toBe('');
    });

    describe('Delete functionality', () => {
        it('renders delete button in danger zone', () => {
            const wrapper = mountEdit();
            expect(wrapper.find('[data-testid="danger-zone-switch"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="action-delete"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="action-delete"]').attributes('title')).toBe(
                'Remove this switch and all its data',
            );
        });

        it('opens ConfirmModal when delete is clicked', async () => {
            const wrapper = mountEdit();

            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);
        });

        it('delete confirm triggers router.delete', async () => {
            const wrapper = mountEdit();

            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');
            await flushPromises();

            expect(router.delete).toHaveBeenCalledWith(
                '/mocked/admin.switches.destroy',
                expect.objectContaining({
                    onFinish: expect.any(Function),
                }),
            );
        });

        it('cancel closes the modal without deleting', async () => {
            const wrapper = mountEdit();

            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(true);

            await wrapper.find('[data-testid="confirm-modal-cancel"]').trigger('click');
            await flushPromises();

            expect(wrapper.find('[data-testid="confirm-modal"]').exists()).toBe(false);
            expect(router.delete).not.toHaveBeenCalled();
        });

        it('shows loading state during delete', async () => {
            vi.mocked(router.delete).mockImplementation(() => {
                // Don't call onFinish - simulating an in-progress request
            });

            const wrapper = mountEdit();

            await wrapper.find('[data-testid="action-delete"]').trigger('click');
            await flushPromises();

            await wrapper.find('[data-testid="confirm-modal-confirm"]').trigger('click');
            await flushPromises();
            await wrapper.vm.$nextTick();

            const confirmButton = wrapper.find('[data-testid="confirm-modal-confirm"]');
            expect(confirmButton.text()).toBe('Delete Switch…');
        });
    });
});
