import { describe, it, expect, vi } from 'vitest';
import { reactive } from 'vue';
import { mount } from '@vue/test-utils';
import Create from '@/Pages/Admin/Switches/Create.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((initial) => reactive({
        ...initial,
        errors: {},
        processing: false,
        post: vi.fn(),
    })),
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

describe('Switches/Create', () => {
    function mountCreate() {
        return mount(Create, {
            props: {
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
                },
            },
        });
    }

    it('shows enable password field when type is cisco', async () => {
        const wrapper = mountCreate();

        expect(wrapper.find('[data-testid="switch-enable-password"]').exists()).toBe(false);

        await wrapper.find('[data-testid="switch-type"]').setValue('cisco');

        expect(wrapper.find('[data-testid="switch-enable-password"]').exists()).toBe(true);
    });

    it('clears credential fields when switching to snmp', async () => {
        const wrapper = mountCreate();

        await wrapper.find('[data-testid="switch-type"]').setValue('cisco');
        await wrapper.find('[data-testid="switch-username"]').setValue('admin');
        await wrapper.find('[data-testid="switch-password"]').setValue('secret');
        await wrapper.find('[data-testid="switch-enable-password"]').setValue('enable-secret');

        await wrapper.find('[data-testid="switch-type"]').setValue('snmp');

        expect(wrapper.find('[data-testid="switch-username"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="switch-password"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="switch-enable-password"]').exists()).toBe(false);
    });

    it('clears snmp community when switching away from snmp', async () => {
        const wrapper = mountCreate();

        await wrapper.find('[data-testid="switch-type"]').setValue('snmp');
        await wrapper.find('[data-testid="switch-community"]').setValue('public');
        await wrapper.find('[data-testid="switch-type"]').setValue('cisco');
        await wrapper.find('[data-testid="switch-type"]').setValue('snmp');

        expect(wrapper.find('[data-testid="switch-community"]').element.value).toBe('');
    });
});
