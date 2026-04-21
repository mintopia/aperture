import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectionStripBlock from '@/Components/Blocks/ConnectionStripBlock.vue';

describe('ConnectionStripBlock', () => {
    const defaultContext = {
        currentIp: '192.168.1.42',
        ipAllowed: true,
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: {},
    };

    it('renders IPv4 address from block context', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('192.168.1.42');
    });

    it('renders MAC address from block context', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('AA:BB:CC:DD:EE:FF');
    });

    it('shows Online status when ipAllowed is true', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('Online');
    });

    it('shows Offline status when ipAllowed is false', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: { ...defaultContext, ipAllowed: false } },
        });
        expect(wrapper.text()).toContain('Offline');
    });

    it('shows dash when MAC address is null', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: { ...defaultContext, macAddress: null } },
        });
        const macSection = wrapper.find('[data-testid="connection-strip-mac"]');
        expect(macSection.text()).toContain('—');
    });

    it('shows dash when IP is empty', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { blockContext: { ...defaultContext, currentIp: '' } },
        });
        expect(wrapper.text()).toContain('—');
    });
});
