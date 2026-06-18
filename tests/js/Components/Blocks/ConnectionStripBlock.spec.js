import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectionStripBlock from '@/Components/Blocks/ConnectionStripBlock.vue';

describe('ConnectionStripBlock', () => {
    const defaultContext = {
        currentIpv4: '10.0.0.1',
        currentIpv6: 'fe80::1',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        internetEnabled: true,
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
        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Online');
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

    it('renders status as Online when internetEnabled is true', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {},
                blockContext: { ...defaultContext, internetEnabled: true },
            },
        });
        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Online');
    });

    it('renders status as Offline when internetEnabled is false', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {},
                blockContext: { ...defaultContext, internetEnabled: false },
            },
        });
        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Offline');
    });

    it('renders status as Offline when internetEnabled is undefined', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {},
                blockContext: { currentIpv4: '10.0.0.1' },
            },
        });
        expect(wrapper.text()).toContain('Status');
        expect(wrapper.text()).toContain('Offline');
    });

    it('renders default fields when settings is null', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: { settings: null, blockContext: defaultContext },
        });
        expect(wrapper.text()).toContain('IPv4');
    });

    it('shows a green status dot when online', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {},
                blockContext: { ...defaultContext, internetEnabled: true },
            },
        });
        const dot = wrapper.find('[data-testid="status-dot"]');
        expect(dot.exists()).toBe(true);
        expect(dot.classes()).toContain('bg-[var(--color-success)]');
    });

    it('shows a red status dot when offline', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: {},
                blockContext: { ...defaultContext, internetEnabled: false },
            },
        });
        const dot = wrapper.find('[data-testid="status-dot"]');
        expect(dot.exists()).toBe(true);
        expect(dot.classes()).toContain('bg-[var(--color-danger)]');
    });

    it('does not show status dot on non-status fields', () => {
        const wrapper = mount(ConnectionStripBlock, {
            props: {
                settings: { fields: [{ label: 'IP', value: '{ipv4}' }] },
                blockContext: defaultContext,
            },
        });
        expect(wrapper.find('[data-testid="status-dot"]').exists()).toBe(false);
    });
});
