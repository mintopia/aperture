import { mount } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import UniqueIpsChart from '@/Components/Admin/UniqueIpsChart.vue';

const sampleData = [
    { date: '2026-04-14', count: 45 },
    { date: '2026-04-15', count: 52 },
    { date: '2026-04-16', count: 38 },
    { date: '2026-04-17', count: 61 },
    { date: '2026-04-18', count: 55 },
    { date: '2026-04-19', count: 48 },
    { date: '2026-04-20', count: 42 },
];

describe('UniqueIpsChart', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-20T12:00:00.000Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the section title', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: sampleData },
        });

        expect(wrapper.text()).toContain('Unique IPs');
        expect(wrapper.text()).toContain('Last 7 Days');
    });

    it('renders a bar for each day', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: sampleData },
        });

        const bars = wrapper.findAll('[data-testid="unique-ips-bar"]');

        expect(bars).toHaveLength(7);
    });

    it('shows peak count below the chart', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: sampleData },
        });

        expect(wrapper.find('[data-testid="unique-ips-peak"]').text()).toContain('Peak: 61 unique IPs');
    });

    it('renders day labels including Today for current day', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: sampleData },
        });

        expect(wrapper.text()).toContain('Today');
    });

    it('renders day labels including Yday for yesterday', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: sampleData },
        });

        expect(wrapper.text()).toContain('Yday');
    });

    it('tallest bar gets 100% height based on max count', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: sampleData },
        });

        const bars = wrapper.findAll('[data-testid="unique-ips-bar"]');
        // The bar with count 61 (index 3) should be 100%
        expect(bars[3].attributes('style')).toContain('height: 100%');
    });

    it('shorter bars get proportional heights', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: [{ date: '2026-04-20', count: 50 }, { date: '2026-04-19', count: 100 }] },
        });

        const bars = wrapper.findAll('[data-testid="unique-ips-bar"]');
        expect(bars[0].attributes('style')).toContain('height: 50%');
        expect(bars[1].attributes('style')).toContain('height: 100%');
    });

    it('shows empty state when no data', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: [] },
        });

        expect(wrapper.find('[data-testid="unique-ips-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No IP activity data available');
    });

    it('does not show peak count when no data', () => {
        const wrapper = mount(UniqueIpsChart, {
            props: { data: [] },
        });

        expect(wrapper.find('[data-testid="unique-ips-peak"]').exists()).toBe(false);
    });
});
