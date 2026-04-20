import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ConnectedDevicesSummary from '@/Components/UI/ConnectedDevicesSummary.vue';

describe('ConnectedDevicesSummary', () => {
    const sampleMacs = [
        {
            mac_address: 'AA:BB:CC:DD:EE:01',
            resolved_ips: [{ ip: '10.0.0.1', user: { nickname: 'gamer42' } }],
        },
        {
            mac_address: 'AA:BB:CC:DD:EE:02',
            resolved_ips: [{ ip: '10.0.0.2', user: null }],
        },
        {
            mac_address: 'AA:BB:CC:DD:EE:03',
            resolved_ips: [],
        },
    ];

    it('does not render when macs array is empty', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [] },
        });

        expect(wrapper.find('[data-testid="connected-devices-summary"]').exists()).toBe(false);
    });

    it('renders when macs are provided', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        expect(wrapper.find('[data-testid="connected-devices-summary"]').exists()).toBe(true);
    });

    it('has correct root element classes', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const root = wrapper.find('[data-testid="connected-devices-summary"]');
        expect(root.classes()).toContain('rounded-lg');
        expect(root.classes()).toContain('bg-[var(--color-bg)]');
        expect(root.classes()).toContain('p-3');
    });

    it('renders section title with correct styling', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const title = wrapper.find('p.mb-2');
        expect(title.exists()).toBe(true);
        expect(title.classes()).toContain('text-xs');
        expect(title.classes()).toContain('font-semibold');
        expect(title.classes()).toContain('tracking-wider');
        expect(title.classes()).toContain('uppercase');
        expect(title.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('shows correct device count in title', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const title = wrapper.find('p.mb-2');
        expect(title.text()).toBe('3 Connected Devices');
    });

    it('shows singular when one device', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const title = wrapper.find('p.mb-2');
        expect(title.text()).toBe('1 Connected Device');
    });

    it('renders one item per device in the list', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const items = wrapper.findAll('.space-y-1 > div');
        expect(items).toHaveLength(3);
    });

    it('shows user nickname when user exists', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const item = wrapper.find('.space-y-1 > div');
        expect(item.text()).toContain('gamer42');
    });

    it('shows MAC address when no user', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[1]] },
        });

        const items = wrapper.findAll('.space-y-1 > div');
        expect(items[0].text()).toContain('AA:BB:CC:DD:EE:02');
    });

    it('displays MAC address with mono font when no user', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[1]] },
        });

        const macSpan = wrapper.find('.space-y-1 > div span.font-mono');
        expect(macSpan.exists()).toBe(true);
        expect(macSpan.classes()).toContain('font-mono');
        expect(macSpan.classes()).toContain('text-[var(--color-text-muted)]');
        expect(macSpan.text()).toBe('AA:BB:CC:DD:EE:02');
    });

    it('displays IP address with mono font and muted color', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const item = wrapper.find('.space-y-1 > div');
        const spans = item.findAll('span');
        // The second span contains the IP
        const ipSpan = spans.find((s) => s.text() === '10.0.0.1');
        expect(ipSpan.exists()).toBe(true);
        expect(ipSpan.classes()).toContain('font-mono');
        expect(ipSpan.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('shows no IP span when no resolved IPs', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[2]] },
        });

        const item = wrapper.find('.space-y-1 > div');
        // Only the mac address span should exist (no IP span)
        const spans = item.findAll('span');
        const ipSpans = spans.filter((s) => s.classes().includes('font-mono') && s.text().includes('.'));
        expect(ipSpans).toHaveLength(0);
    });

    it('applies muted color to MAC address spans', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[1]] },
        });

        const macSpan = wrapper.find('.space-y-1 > div span.font-mono');
        expect(macSpan.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('uses text-xs on device items', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const item = wrapper.find('.space-y-1 > div');
        expect(item.classes()).toContain('text-xs');
    });
});
