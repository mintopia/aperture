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

const mountWithStubs = (props) =>
    mount(UniqueIpsChart, {
        props,
        global: {
            stubs: { TimeSeriesChart: true },
        },
    });

describe('UniqueIpsChart', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-20T12:00:00.000Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the section title', () => {
        const wrapper = mountWithStubs({ data: sampleData });

        expect(wrapper.text()).toContain('Unique IPs');
        expect(wrapper.text()).toContain('Last 7 Days');
    });

    it('renders the line chart when data is present', () => {
        const wrapper = mountWithStubs({ data: sampleData });

        expect(wrapper.find('[data-testid="unique-ips-line-chart"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="unique-ips-empty"]').exists()).toBe(false);
    });

    it('passes correct chartSeries to TimeSeriesChart with one series per dataset', () => {
        const wrapper = mountWithStubs({ data: sampleData });

        const chart = wrapper.findComponent({ name: 'TimeSeriesChart' });
        const series = chart.props('series');

        expect(series).toHaveLength(1);
        expect(series[0].label).toBe('Unique IPs');
        expect(series[0].data).toHaveLength(7);
        expect(series[0].fill).toBe(true);
    });

    it('maps data entries to correct timestamp and value in chartSeries', () => {
        const wrapper = mountWithStubs({ data: sampleData });

        const chart = wrapper.findComponent({ name: 'TimeSeriesChart' });
        const series = chart.props('series');
        const points = series[0].data;

        // 2026-04-14T12:00:00 UTC → Unix timestamp
        const expectedTimestamp = new Date('2026-04-14T12:00:00').getTime() / 1000;
        expect(points[0].timestamp).toBe(expectedTimestamp);
        expect(points[0].value).toBe(45);

        expect(points[3].value).toBe(61);
    });

    it('shows peak count below the chart', () => {
        const wrapper = mountWithStubs({ data: sampleData });

        expect(wrapper.find('[data-testid="unique-ips-peak"]').text()).toContain('Peak: 61 unique IPs');
    });

    it('computes max count correctly as the peak value', () => {
        const wrapper = mountWithStubs({
            data: [
                { date: '2026-04-20', count: 50 },
                { date: '2026-04-19', count: 100 },
            ],
        });

        expect(wrapper.find('[data-testid="unique-ips-peak"]').text()).toContain('Peak: 100 unique IPs');
    });

    it('shows empty state when no data', () => {
        const wrapper = mountWithStubs({ data: [] });

        expect(wrapper.find('[data-testid="unique-ips-empty"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No IP activity data available');
    });

    it('does not show line chart when no data', () => {
        const wrapper = mountWithStubs({ data: [] });

        expect(wrapper.find('[data-testid="unique-ips-line-chart"]').exists()).toBe(false);
    });

    it('does not show peak count when no data', () => {
        const wrapper = mountWithStubs({ data: [] });

        expect(wrapper.find('[data-testid="unique-ips-peak"]').exists()).toBe(false);
    });

    it('returns empty chartSeries when data is empty', () => {
        const wrapper = mountWithStubs({ data: [] });

        const chart = wrapper.findComponent({ name: 'TimeSeriesChart' });
        expect(chart.exists()).toBe(false);
    });
});
