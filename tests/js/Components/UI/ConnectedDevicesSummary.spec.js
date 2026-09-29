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

    it('has no card wrapper classes', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const root = wrapper.find('[data-testid="connected-devices-summary"]');
        expect(root.classes()).not.toContain('rounded-lg');
        expect(root.classes()).not.toContain('bg-[var(--color-bg)]');
        expect(root.classes()).not.toContain('p-3');
    });

    it('renders section title with Dispatch heading style', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const title = wrapper.find('[data-testid="connected-devices-title"]');
        expect(title.exists()).toBe(true);
        expect(title.classes()).toContain('font-heading');
        expect(title.classes()).toContain('text-[14px]');
        expect(title.classes()).toContain('font-bold');
        expect(title.classes()).toContain('tracking-[0.04em]');
        expect(title.classes()).toContain('uppercase');
        expect(title.classes()).toContain('text-[var(--color-text-secondary)]');
        expect(title.element.style.fontVariationSettings).toBe("'opsz' 16");
    });

    it('shows correct device count in title', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const title = wrapper.find('[data-testid="connected-devices-title"]');
        expect(title.text()).toBe('3 Connected Devices');
    });

    it('shows singular when one device', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const title = wrapper.find('[data-testid="connected-devices-title"]');
        expect(title.text()).toBe('1 Connected Device');
    });

    it('renders a data table with correct columns', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const table = wrapper.find('[data-testid="connected-devices-table"]');
        expect(table.exists()).toBe(true);

        const headers = table.findAll('th');
        expect(headers).toHaveLength(3);
        expect(headers[0].text()).toBe('Device');
        expect(headers[1].text()).toBe('MAC Address');
        expect(headers[2].text()).toBe('IP Address');
    });

    it('applies Dispatch table header styles', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const headers = wrapper.findAll('th');
        for (const header of headers) {
            expect(header.classes()).toContain('text-[11px]');
            expect(header.classes()).toContain('font-semibold');
            expect(header.classes()).toContain('tracking-[0.05em]');
            expect(header.classes()).toContain('uppercase');
            expect(header.classes()).toContain('text-[var(--color-text-muted)]');
            expect(header.classes()).toContain('border-b');
            expect(header.classes()).toContain('border-[var(--color-border-hover)]');
        }
    });

    it('applies pl-6 to non-first column headers', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const headers = wrapper.findAll('th');
        expect(headers[0].classes()).not.toContain('pl-6');
        expect(headers[1].classes()).toContain('pl-6');
        expect(headers[2].classes()).toContain('pl-6');
    });

    it('renders one row per device', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: sampleMacs },
        });

        const rows = wrapper.findAll('[data-testid="connected-devices-row"]');
        expect(rows).toHaveLength(3);
    });

    it('shows user nickname in device column when user exists', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const row = wrapper.find('[data-testid="connected-devices-row"]');
        const cells = row.findAll('td');
        expect(cells[0].text()).toBe('gamer42');
    });

    it('shows dash in device column when no user', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[1]] },
        });

        const row = wrapper.find('[data-testid="connected-devices-row"]');
        const cells = row.findAll('td');
        expect(cells[0].text()).toContain('\u2014');
    });

    it('displays MAC address with mono font', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const row = wrapper.find('[data-testid="connected-devices-row"]');
        const macCell = row.findAll('td')[1];
        const macSpan = macCell.find('span');
        expect(macSpan.classes()).toContain('font-mono');
        expect(macSpan.classes()).toContain('text-[13px]');
        expect(macSpan.text()).toBe('AA:BB:CC:DD:EE:01');
    });

    it('displays IP address with mono font and primary color', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const row = wrapper.find('[data-testid="connected-devices-row"]');
        const ipCell = row.findAll('td')[2];
        const ipSpan = ipCell.find('span');
        expect(ipSpan.classes()).toContain('font-mono');
        expect(ipSpan.classes()).toContain('text-[13px]');
        expect(ipSpan.classes()).toContain('text-[var(--color-primary)]');
        expect(ipSpan.text()).toBe('10.0.0.1');
    });

    it('shows dash for IP when no resolved IPs', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[2]] },
        });

        const row = wrapper.find('[data-testid="connected-devices-row"]');
        const ipCell = row.findAll('td')[2];
        expect(ipCell.text()).toContain('\u2014');
    });

    it('applies muted color to MAC addresses', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const row = wrapper.find('[data-testid="connected-devices-row"]');
        const macSpan = row.findAll('td')[1].find('span');
        expect(macSpan.classes()).toContain('text-[var(--color-text-muted)]');
    });

    it('applies border-b to table cells', () => {
        const wrapper = mount(ConnectedDevicesSummary, {
            props: { macs: [sampleMacs[0]] },
        });

        const cells = wrapper.findAll('[data-testid="connected-devices-row"] td');
        for (const cell of cells) {
            expect(cell.classes()).toContain('border-b');
            expect(cell.classes()).toContain('border-[var(--color-border)]');
        }
    });
});
