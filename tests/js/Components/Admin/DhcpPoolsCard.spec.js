import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DhcpPoolsCard from '@/Components/Admin/DhcpPoolsCard.vue';

const samplePools = [
    { name: 'Users', network: '10.0.1.0/24', used: 89, total: 200, utilisation: 0.445 },
    { name: 'Infrastructure', network: '10.0.2.0/24', used: 12, total: 50, utilisation: 0.24 },
    { name: 'Guest', network: '10.0.3.0/24', used: 45, total: 50, utilisation: 0.9 },
];

describe('DhcpPoolsCard', () => {
    it('renders the title', () => {
        const wrapper = mount(DhcpPoolsCard);

        expect(wrapper.text()).toContain('DHCP Pools');
    });

    it('renders a table row for each pool', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: { pools: samplePools },
        });

        const rows = wrapper.findAll('[data-testid="dhcp-pool-row"]');

        expect(rows).toHaveLength(3);
        expect(wrapper.text()).toContain('10.0.1.0/24');
        expect(wrapper.text()).toContain('10.0.3.0/24');
    });

    it('renders table headers matching mockup 4-column layout', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: { pools: samplePools },
        });

        const headers = wrapper.findAll('th');

        expect(headers).toHaveLength(4);
        expect(headers[0].text()).toBe('Network');
        expect(headers[1].text()).toBe('Used');
        expect(headers[2].text()).toBe('Total');
        expect(headers[3].text()).toBe('Utilisation');
    });

    it('shows network/CIDR in first column with semibold weight', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Users', network: '192.168.1.0/24', used: 20, total: 100, utilisation: 0.2 }],
            },
        });

        const row = wrapper.find('[data-testid="dhcp-pool-row"]');
        const networkCell = row.findAll('td')[0];

        expect(networkCell.text()).toBe('192.168.1.0/24');
        expect(networkCell.classes()).toContain('font-semibold');
    });

    it('shows used and total in separate mono columns', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Users', network: '10.0.0.0/24', used: 89, total: 200, utilisation: 0.445 }],
            },
        });

        const usedCell = wrapper.find('[data-testid="dhcp-pool-used"]');
        const totalCell = wrapper.find('[data-testid="dhcp-pool-total"]');

        expect(usedCell.text()).toBe('89');
        expect(usedCell.classes()).toContain('font-mono');
        expect(totalCell.text()).toBe('200');
        expect(totalCell.classes()).toContain('font-mono');
    });

    it('shows em dash when network is null', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Unknown', network: null, used: 5, total: 10, utilisation: 0.5 }],
            },
        });

        const row = wrapper.find('[data-testid="dhcp-pool-row"]');
        const networkCell = row.findAll('td')[0];

        expect(networkCell.text()).toBe('—');
    });

    it('shows an empty message when no pools are available', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: { pools: [] },
        });

        expect(wrapper.find('[data-testid="dhcp-pools-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No DHCP pools available');
    });

    it('does not render table when pools are empty', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: { pools: [] },
        });

        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('renders progress bars with correct width', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Users', network: '10.0.0.0/24', used: 50, total: 100, utilisation: 0.5 }],
            },
        });

        const bar = wrapper.find('[data-testid="dhcp-pool-bar"]');

        expect(bar.attributes('style')).toContain('width: 50%');
    });

    it('caps progress bar width at 100%', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Over', network: '10.0.0.0/24', used: 120, total: 100, utilisation: 1.2 }],
            },
        });

        const bar = wrapper.find('[data-testid="dhcp-pool-bar"]');

        expect(bar.attributes('style')).toContain('width: 100%');
    });

    it('displays utilisation percentage', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Users', network: '10.0.0.0/24', used: 45, total: 100, utilisation: 0.445 }],
            },
        });

        const pct = wrapper.find('[data-testid="dhcp-pool-pct"]');

        expect(pct.text()).toBe('45%');
    });

    it('applies success bar color for utilisation below 70%', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Users', network: '10.0.0.0/24', used: 20, total: 100, utilisation: 0.2 }],
            },
        });

        const bar = wrapper.find('[data-testid="dhcp-pool-bar"]');

        expect(bar.attributes('style')).toContain('background-color: var(--color-success)');
    });

    it('applies warning bar color for utilisation between 70% and 90%', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Staff', network: '10.0.0.0/24', used: 75, total: 100, utilisation: 0.75 }],
            },
        });

        const bar = wrapper.find('[data-testid="dhcp-pool-bar"]');

        expect(bar.attributes('style')).toContain('background-color: var(--color-warning)');
    });

    it('applies danger bar color for utilisation at or above 90%', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Guest', network: '10.0.0.0/24', used: 95, total: 100, utilisation: 0.95 }],
            },
        });

        const bar = wrapper.find('[data-testid="dhcp-pool-bar"]');

        expect(bar.attributes('style')).toContain('background-color: var(--color-danger)');

        const pct = wrapper.find('[data-testid="dhcp-pool-pct"]');

        expect(pct.classes()).toContain('text-[var(--color-danger)]');
    });

    it('applies secondary text for percentage below 90%', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [{ name: 'Users', network: '10.0.0.0/24', used: 20, total: 100, utilisation: 0.2 }],
            },
        });

        const pct = wrapper.find('[data-testid="dhcp-pool-pct"]');

        expect(pct.classes()).toContain('text-[var(--color-text-secondary)]');
    });
});
