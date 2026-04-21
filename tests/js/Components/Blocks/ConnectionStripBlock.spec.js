import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectionStripBlock from '@/Components/Blocks/ConnectionStripBlock.vue';

describe('ConnectionStripBlock', () => {
    const defaultContext = {
        currentIpv4: '10.0.0.1',
        currentIpv6: 'fe80::1',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        ipAllowed: true,
        user: { name: 'Player', params: { seat: 'A42' } },
    };

    it('renders default fields when settings.fields is empty', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { settings: {}, blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('IPv4');
        expect(wrapper.text()).toContain('10.0.0.1');
        expect(wrapper.text()).toContain('IPv6');
        expect(wrapper.text()).toContain('MAC');
    });

    it('renders custom fields from settings.fields', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {
                    fields: [
                        { label: 'Seat', value: '{user.params.seat}' },
                        { label: 'IP', value: '{ipv4}' },
                    ],
                },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.text()).toContain('Seat');
        expect(wrapper.text()).toContain('A42');
        expect(wrapper.text()).toContain('IP');
        expect(wrapper.text()).toContain('10.0.0.1');
    });

    it('renders template variables in field values', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {
                    fields: [{ label: 'MAC', value: '{mac}' }],
                },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.text()).toContain('AA:BB:CC:DD:EE:FF');
    });

    it('renders em dash for empty template result', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {
                    fields: [{ label: 'Missing', value: '{user.params.unknown}' }],
                },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.text()).toContain('\u2014');
    });

    it('renders default fields when settings is null', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { settings: null, blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('IPv4');
    });
});
