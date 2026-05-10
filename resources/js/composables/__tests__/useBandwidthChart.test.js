import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { useBandwidthChart } from '../useBandwidthChart.js';

const mockData = {
    timestamps: ['1000', '2000'],
    download: [100, 200],
    upload: [50, 75],
    totalReceived: 1024,
    totalSent: 512,
};

function createWrapper(endpoint = '/api/bandwidth', defaultRange = '24h', pollInterval = 30000, enabled = true) {
    const TestComponent = defineComponent({
        setup() {
            return useBandwidthChart(endpoint, defaultRange, pollInterval, enabled);
        },
        template: '<div />',
    });
    return mount(TestComponent);
}

describe('useBandwidthChart', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(mockData),
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('starts with default range and loading state', async () => {
        const wrapper = createWrapper('/api/bandwidth', '24h');
        const { selectedRange, bandwidthLoading } = wrapper.vm;
        expect(selectedRange).toBe('24h');
        expect(bandwidthLoading).toBe(true);
    });

    it('fetches bandwidth data on mount', async () => {
        createWrapper('/api/bandwidth');
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledWith('/api/bandwidth?range=24h');
    });

    it('updates bandwidthData on successful fetch', async () => {
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        expect(wrapper.vm.bandwidthData.totalReceived).toBe(1024);
        expect(wrapper.vm.bandwidthLoading).toBe(false);
    });

    it('sets bandwidthError on fetch failure', async () => {
        global.fetch = vi.fn().mockRejectedValue(new Error('Network error'));
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        expect(wrapper.vm.bandwidthError).toBe(true);
        expect(wrapper.vm.bandwidthLoading).toBe(false);
    });

    it('polls at specified interval', async () => {
        createWrapper('/api/bandwidth', '24h', 5000);
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(5000);
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('does not poll when pollInterval is 0', async () => {
        createWrapper('/api/bandwidth', '24h', 0);
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(1);
        vi.advanceTimersByTime(60000);
        await flushPromises();
        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('returns chart series in correct format', async () => {
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        const series = wrapper.vm.chartSeries;
        expect(series).toHaveLength(2);
        expect(series[0].label).toBe('Download');
        expect(series[1].label).toBe('Upload');
        expect(series[0].data[0]).toMatchObject({ timestamp: 1000, value: 100 });
    });

    it('returns empty chartSeries when timestamps are empty', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 }),
        });
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        expect(wrapper.vm.chartSeries).toEqual([]);
    });

    it('selectRange updates range and re-fetches', async () => {
        const wrapper = createWrapper('/api/bandwidth');
        await flushPromises();
        wrapper.vm.selectRange('1h');
        expect(wrapper.vm.selectedRange).toBe('1h');
        await flushPromises();
        expect(global.fetch).toHaveBeenLastCalledWith('/api/bandwidth?range=1h');
    });

    it('clears poll interval on unmount', async () => {
        const clearIntervalSpy = vi.spyOn(globalThis, 'clearInterval');
        const wrapper = createWrapper('/api/bandwidth', '24h', 5000);
        await flushPromises();
        wrapper.unmount();
        expect(clearIntervalSpy).toHaveBeenCalled();
    });

    it('does not fetch when enabled is false', async () => {
        const wrapper = createWrapper('/api/bandwidth', '24h', 30000, false);
        await flushPromises();
        expect(global.fetch).not.toHaveBeenCalled();
        expect(wrapper.vm.bandwidthLoading).toBe(false);
    });

    it('does not poll when enabled is false', async () => {
        createWrapper('/api/bandwidth', '24h', 30000, false);
        await flushPromises();
        vi.advanceTimersByTime(60000);
        await flushPromises();
        expect(global.fetch).not.toHaveBeenCalled();
    });
});
