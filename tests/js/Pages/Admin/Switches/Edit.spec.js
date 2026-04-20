import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Edit from '@/Pages/Admin/Switches/Edit.vue';

vi.mock('@inertiajs/vue3', () => ({
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

describe('Switches/Edit', () => {
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
});
