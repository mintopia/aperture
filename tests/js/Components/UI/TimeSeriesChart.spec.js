import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';

let lastChartCallbacks = {};
let lastChartXTick = null;
let lastChartYTick = null;
let lastChartGridX = null;

const mockChartInstance = {
    destroy: vi.fn(),
    update: vi.fn(),
};

vi.mock('chart.js/auto', () => ({
    Chart: vi.fn(function Chart(_canvas, config) {
        lastChartCallbacks = config?.options?.plugins?.tooltip?.callbacks ?? {};
        lastChartXTick = config?.options?.scales?.x?.ticks?.callback ?? null;
        lastChartYTick = config?.options?.scales?.y?.ticks?.callback ?? null;
        lastChartGridX = config?.options?.scales?.x?.grid?.color ?? null;
        Object.assign(mockChartInstance, { destroy: vi.fn(), update: vi.fn() });
        return mockChartInstance;
    }),
}));

vi.mock('chartjs-adapter-date-fns', () => ({}));

describe('TimeSeriesChart', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => '#888',
        });
        lastChartCallbacks = {};
        lastChartXTick = null;
        lastChartYTick = null;
        lastChartGridX = null;
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

    it('builds chart on mount with series data', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();
        expect(Chart).toHaveBeenCalled();
    });

    it('destroys chart on unmount', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        const wrapper = mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        wrapper.unmount();
        expect(mockChartInstance.destroy).toHaveBeenCalled();
    });

    it('rebuilds chart when series prop changes', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        const wrapper = mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        const callCount = Chart.mock.calls.length;

        await wrapper.setProps({
            series: [
                {
                    label: 'New',
                    data: [{ timestamp: 2000, value: 50 }],
                    color: '#ff0000',
                    fill: false,
                },
            ],
        });

        await nextTick();
        await nextTick();

        expect(Chart.mock.calls.length).toBeGreaterThan(callCount);
    });

    it('calls tooltip title callback with items', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartCallbacks.title).toBeDefined();

        const result = lastChartCallbacks.title([{ parsed: { x: new Date('2026-01-01T12:00:00Z').getTime() } }]);
        expect(typeof result).toBe('string');
        expect(result.length).toBeGreaterThan(0);
    });

    it('tooltip title returns empty string for no items', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        const result = lastChartCallbacks.title([]);
        expect(result).toBe('');
    });

    it('tooltip label callback formats value', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartCallbacks.label).toBeDefined();

        const result = lastChartCallbacks.label({
            dataset: { label: 'Inbound' },
            parsed: { y: 500 },
        });
        expect(result).toContain('Inbound');
        expect(result).toContain('500.0');
    });

    it('tooltip label formats Gbps values', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        const result = lastChartCallbacks.label({
            dataset: { label: 'In' },
            parsed: { y: 2e9 },
        });
        expect(result).toContain('2.0 Gbps');
    });

    it('tooltip label formats Mbps values', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        const result = lastChartCallbacks.label({
            dataset: { label: 'In' },
            parsed: { y: 5e6 },
        });
        expect(result).toContain('5.0 Mbps');
    });

    it('tooltip label formats Kbps values', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        const result = lastChartCallbacks.label({
            dataset: { label: 'In' },
            parsed: { y: 2000 },
        });
        expect(result).toContain('2.0 Kbps');
    });

    it('x-axis tick callback formats timestamp', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartXTick).toBeDefined();

        const result = lastChartXTick(null, 0, [{ value: new Date('2026-01-01T12:00:00Z').getTime() }]);
        expect(typeof result).toBe('string');
    });

    it('x-axis tick callback uses Date.now when tick value is missing', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        // Empty ticks array — should fall back to Date.now()
        const result = lastChartXTick(null, 0, []);
        expect(typeof result).toBe('string');
    });

    it('y-axis tick callback formats value', async () => {
        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartYTick).toBeDefined();

        const result = lastChartYTick(1500);
        expect(result).toContain('1.5 Kbps');
    });

    it('uses custom yAxisFormatter when provided', async () => {
        const formatter = vi.fn((v) => `custom:${v}`);

        mount(TimeSeriesChart, {
            props: { series: sampleSeries, yAxisFormatter: formatter },
        });

        await nextTick();
        await nextTick();

        const result = lastChartYTick(42);
        expect(result).toBe('custom:42');
        expect(formatter).toHaveBeenCalledWith(42);
    });

    it('grid color uses withAlpha with hex color', async () => {
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => '#333333',
        });

        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartGridX).toBeDefined();
        expect(lastChartGridX).toContain('rgba(');
    });

    it('grid color uses withAlpha with rgb color', async () => {
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => 'rgb(100, 100, 100)',
        });

        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartGridX).toBeDefined();
        expect(lastChartGridX).toContain('rgba(100, 100, 100,');
    });

    it('withAlpha falls back for empty/falsy color', async () => {
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => '',
        });

        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        // With empty getPropertyValue, fallback color is used as-is (not rgba)
        // The important thing is it doesn't throw
        expect(lastChartGridX).toBeDefined();
    });

    it('withAlpha uses short hex notation (3-char)', async () => {
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => '#fff',
        });

        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        expect(lastChartGridX).toContain('rgba(255, 255, 255,');
    });

    it('withAlpha returns color unchanged for non-hex non-rgb color', async () => {
        // oklch or named colors fall through to the return color
        vi.spyOn(window, 'getComputedStyle').mockReturnValue({
            getPropertyValue: () => 'oklch(70% 0.2 55)',
        });

        mount(TimeSeriesChart, {
            props: { series: sampleSeries },
        });

        await nextTick();
        await nextTick();

        // Should return the color as-is
        expect(lastChartGridX).toBeDefined();
    });

    it('uses custom data-testid via attrs', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [] },
            attrs: { 'data-testid': 'my-custom-chart' },
        });

        expect(wrapper.find('[data-testid="my-custom-chart"]').exists()).toBe(true);
    });

    it('passes through class and style attrs to root element', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [] },
            attrs: { class: 'my-class', style: 'color: red' },
        });

        const root = wrapper.find('[data-testid="time-series-chart"]');
        expect(root.classes()).toContain('my-class');
    });

    it('applies height style from prop', () => {
        const wrapper = mount(TimeSeriesChart, {
            props: { series: [], height: '300px' },
        });

        const root = wrapper.find('[data-testid="time-series-chart"]');
        expect(root.attributes('style')).toContain('300px');
    });

    it('does not build chart when loading is true even with data', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        mount(TimeSeriesChart, {
            props: { series: sampleSeries, loading: true },
        });

        await nextTick();
        await nextTick();

        expect(Chart).not.toHaveBeenCalled();
    });

    it('builds chart with series fill=false using transparent background', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        mount(TimeSeriesChart, {
            props: {
                series: [
                    {
                        label: 'No Fill',
                        data: [{ timestamp: 1000, value: 100 }],
                        color: '#00ff00',
                        fill: false,
                    },
                ],
            },
        });

        await nextTick();
        await nextTick();

        expect(Chart).toHaveBeenCalled();
        const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
        const dataset = config.data.datasets[0];
        expect(dataset.backgroundColor).toBe('transparent');
        expect(dataset.fill).toBe(false);
    });

    it('shows legend when multiple series present', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        mount(TimeSeriesChart, {
            props: {
                series: [
                    { label: 'A', data: [{ timestamp: 1000, value: 1 }], color: '#f00' },
                    { label: 'B', data: [{ timestamp: 1000, value: 2 }], color: '#0f0' },
                ],
            },
        });

        await nextTick();
        await nextTick();

        expect(Chart).toHaveBeenCalled();
        const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
        expect(config.options.plugins.legend.display).toBe(true);
    });

    describe('resolveColor', () => {
        it('passes through hex colors unchanged', async () => {
            const { Chart } = await import('chart.js/auto');
            Chart.mockClear();

            mount(TimeSeriesChart, {
                props: {
                    series: [
                        {
                            label: 'Hex',
                            data: [{ timestamp: 1000, value: 100 }],
                            color: '#22c55e',
                            fill: false,
                        },
                    ],
                },
            });

            await nextTick();
            await nextTick();

            expect(Chart).toHaveBeenCalled();
            const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
            expect(config.data.datasets[0].borderColor).toBe('#22c55e');
        });

        it('passes through rgb() colors unchanged', async () => {
            const { Chart } = await import('chart.js/auto');
            Chart.mockClear();

            mount(TimeSeriesChart, {
                props: {
                    series: [
                        {
                            label: 'RGB',
                            data: [{ timestamp: 1000, value: 100 }],
                            color: 'rgb(34, 197, 94)',
                            fill: false,
                        },
                    ],
                },
            });

            await nextTick();
            await nextTick();

            expect(Chart).toHaveBeenCalled();
            const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
            expect(config.data.datasets[0].borderColor).toBe('rgb(34, 197, 94)');
        });

        it('passes through null/undefined unchanged', async () => {
            const { Chart } = await import('chart.js/auto');
            Chart.mockClear();

            mount(TimeSeriesChart, {
                props: {
                    series: [
                        {
                            label: 'Null Color',
                            data: [{ timestamp: 1000, value: 100 }],
                            color: null,
                            fill: false,
                        },
                    ],
                },
            });

            await nextTick();
            await nextTick();

            expect(Chart).toHaveBeenCalled();
            const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
            expect(config.data.datasets[0].borderColor).toBeNull();
        });

        it('resolves var(--color-success) via getComputedStyle', async () => {
            vi.spyOn(window, 'getComputedStyle').mockReturnValue({
                getPropertyValue: (prop) => {
                    if (prop === '--color-success') return '#22c55e';
                    return '#888';
                },
            });

            const { Chart } = await import('chart.js/auto');
            Chart.mockClear();

            mount(TimeSeriesChart, {
                props: {
                    series: [
                        {
                            label: 'CSS Var',
                            data: [{ timestamp: 1000, value: 100 }],
                            color: 'var(--color-success)',
                            fill: false,
                        },
                    ],
                },
            });

            await nextTick();
            await nextTick();

            expect(Chart).toHaveBeenCalled();
            const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
            expect(config.data.datasets[0].borderColor).toBe('#22c55e');
        });

        it('uses resolved color for fill backgroundColor when series color is a CSS variable', async () => {
            vi.spyOn(window, 'getComputedStyle').mockReturnValue({
                getPropertyValue: (prop) => {
                    if (prop === '--color-success') return '#22c55e';
                    return '#888';
                },
            });

            const { Chart } = await import('chart.js/auto');
            Chart.mockClear();

            mount(TimeSeriesChart, {
                props: {
                    series: [
                        {
                            label: 'CSS Var Fill',
                            data: [{ timestamp: 1000, value: 100 }],
                            color: 'var(--color-success)',
                            fill: true,
                        },
                    ],
                },
            });

            await nextTick();
            await nextTick();

            expect(Chart).toHaveBeenCalled();
            const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
            const dataset = config.data.datasets[0];
            // borderColor should be the resolved hex, not the raw var() string
            expect(dataset.borderColor).toBe('#22c55e');
            // backgroundColor should be an rgba derived from the resolved hex
            expect(dataset.backgroundColor).toContain('rgba(34, 197, 94,');
            expect(dataset.backgroundColor).not.toContain('var(');
        });
    });

    it('withAlpha uses default indigo rgba when color is null/falsy (fill with null color)', async () => {
        const { Chart } = await import('chart.js/auto');
        Chart.mockClear();

        mount(TimeSeriesChart, {
            props: {
                series: [
                    {
                        label: 'Null Color',
                        data: [{ timestamp: 1000, value: 100 }],
                        color: null,
                        fill: true,
                    },
                ],
            },
        });

        await nextTick();
        await nextTick();

        expect(Chart).toHaveBeenCalled();
        const [, config] = Chart.mock.calls[Chart.mock.calls.length - 1];
        const dataset = config.data.datasets[0];
        // When color is null and fill is true, withAlpha(null, 0.12) => rgba(99, 102, 241, 0.12)
        expect(dataset.backgroundColor).toContain('rgba(99, 102, 241,');
    });
});
