import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectionStripBlock from '../ConnectionStripBlock.vue';

describe('ConnectionStripBlock', () => {
    it('renders the connection strip block', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                blockContext: {
                    internetEnabled: true,
                    currentIpv4: '10.0.0.1',
                },
            },
        });

        expect(wrapper.find('[data-testid="block-connection-strip"]').exists()).toBe(true);
    });

    it('shows Online when internet is enabled', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                blockContext: { internetEnabled: true },
            },
        });

        expect(wrapper.text()).toContain('Online');
    });

    it('shows Offline when internet is disabled', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                blockContext: { internetEnabled: false },
            },
        });

        expect(wrapper.text()).toContain('Offline');
    });

    it('updates status reactively when blockContext changes', async () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                blockContext: { internetEnabled: true },
            },
        });

        expect(wrapper.text()).toContain('Online');

        await wrapper.setProps({
            blockContext: { internetEnabled: false },
        });

        expect(wrapper.text()).toContain('Offline');
    });

    it('exposes updateInternetStatus method', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                blockContext: { internetEnabled: true },
            },
        });

        expect(wrapper.vm.updateInternetStatus).toBeInstanceOf(Function);
    });

    it('updates status dot when updateInternetStatus is called', async () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                blockContext: { internetEnabled: true },
            },
        });

        wrapper.vm.updateInternetStatus(false);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Offline');
    });
});
