import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';

vi.mock('chart.js/auto', () => ({
    Chart: vi.fn(function Chart() {
        return {
            destroy: vi.fn(),
            update: vi.fn(),
        };
    }),
}));

vi.mock('chartjs-adapter-date-fns', () => ({}));

describe('TimeSeriesChart', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => '#888',
        });
    });

    const sampleSeries = [
        {
            label: 'Inbound',
            data: [
                { timestamp: 1000, value: 100 },
                { timestamp: 1060, value: 200 },
            ],
            color: '#00ff00',
            fill: true,
        },
    ];

    it('shows empty state when no data', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [] },
        });

        expect(wrapper.find('[data-testid="chart-empty"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(false);
    });

    it('shows custom empty message', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [], emptyMessage: 'No metrics yet' },
        });

        expect(wrapper.find('[data-testid="chart-empty"]').text()).toBe('No metrics yet');
    });

    it('shows loading state', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: sampleSeries, loading: true },
        });

        expect(wrapper.find('[data-testid="chart-loading"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(false);
    });

    it('renders canvas when data provided', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        expect(wrapper.find('[data-testid="chart-canvas"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="chart-empty"]').exists()).toBe(false);
    });

    it('shows empty when series has empty data arrays', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [{ label: 'Test', data: [], color: '#fff' }] },
        });

        expect(wrapper.find('[data-testid="chart-empty"]').exists()).toBe(true);
    });

    it('has root testid', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [] },
        });

        expect(wrapper.find('[data-testid="time-series-chart"]').exists()).toBe(true);
    });

    it('applies Dispatch chart-area styling to canvas container', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        const chartArea = wrapper.find('[data-testid="chart-canvas"]').element.parentElement;
        expect(chartArea.classList.contains('rounded')).toBe(true);
        expect(chartArea.classList.contains('border')).toBe(true);
        expect(chartArea.classList.contains('border-[var(--color-border)]')).toBe(true);
        expect(chartArea.classList.contains('bg-[var(--color-surface)]')).toBe(true);
    });

    it('applies surface bg to loading state', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: sampleSeries, loading: true },
        });

        const loadingEl = wrapper.find('[data-testid="chart-loading"]');
        expect(loadingEl.classes()).toContain('bg-[var(--color-surface)]');
        expect(loadingEl.classes()).toContain('rounded');
    });

    it('applies surface bg to empty state', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [] },
        });

        const emptyEl = wrapper.find('[data-testid="chart-empty"]');
        expect(emptyEl.classes()).toContain('bg-[var(--color-surface)]');
        expect(emptyEl.classes()).toContain('rounded');
    });
});
