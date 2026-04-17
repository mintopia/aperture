import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DhcpPoolsCard from '@/Components/Admin/DhcpPoolsCard.vue';
import ProgressBar from '@/Components/UI/ProgressBar.vue';

describe('DhcpPoolsCard', () => {
    it('renders the title', () => {
        const wrapper = mount(DhcpPoolsCard);

        expect(wrapper.find('[data-testid="dhcp-pools-title"]').text()).toBe('DHCP Pools');
    });

    it('renders progress bars for each pool', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [
                    { name: 'Users', used: 89, total: 200, utilisation: 0.445 },
                    { name: 'Infrastructure', used: 12, total: 50, utilisation: 0.24 },
                    { name: 'Guest', used: 45, total: 50, utilisation: 0.9 },
                ],
            },
        });

        expect(wrapper.findAllComponents(ProgressBar)).toHaveLength(3);
        expect(wrapper.text()).toContain('Users');
        expect(wrapper.text()).toContain('89 / 200');
        expect(wrapper.text()).toContain('Guest');
    });

    it('shows an empty message when no pools are available', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [],
            },
        });

        expect(wrapper.find('[data-testid="dhcp-pools-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No DHCP pools available');
    });

    it('applies primary, warning, and danger colors based on utilisation', () => {
        const wrapper = mount(DhcpPoolsCard, {
            props: {
                pools: [
                    { name: 'Users', used: 20, total: 100, utilisation: 0.2 },
                    { name: 'Staff', used: 75, total: 100, utilisation: 0.75 },
                    { name: 'Guest', used: 95, total: 100, utilisation: 0.95 },
                ],
            },
        });

        const bars = wrapper.findAllComponents(ProgressBar);

        expect(bars[0].props('color')).toBe('primary');
        expect(bars[1].props('color')).toBe('warning');
        expect(bars[2].props('color')).toBe('danger');
    });
});
