import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import BandwidthBlock from '../BandwidthBlock.vue';

const routeMock = vi.fn(() => '/api/portal/stats/bandwidth');

const globalConfig = {
    stubs: ['TimeSeriesChart'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('BandwidthBlock', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        window.route = routeMock;
        global.route = routeMock;
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () =>
                Promise.resolve({
                    timestamps: [],
                    download: [],
                    upload: [],
                    totalReceived: 0,
                    totalSent: 0,
                }),
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('renders the bandwidth block', () => {
        const wrapper = mount(BandwidthBlock, {
            props: { blockContext: {} },
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="block-bandwidth"]').exists()).toBe(true);
    });

    it('fetches bandwidth data on mount', () => {
        mount(BandwidthBlock, {
            props: { blockContext: {} },
            global: globalConfig,
        });

        expect(global.fetch).toHaveBeenCalled();
    });

    it('polls for bandwidth data every 30 seconds', async () => {
        mount(BandwidthBlock, {
            props: { blockContext: {} },
            global: globalConfig,
        });

        // Initial fetch
        expect(global.fetch).toHaveBeenCalledTimes(1);

        // Advance 30 seconds
        vi.advanceTimersByTime(30000);
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('clears the polling interval on unmount', async () => {
        const wrapper = mount(BandwidthBlock, {
            props: { blockContext: {} },
            global: globalConfig,
        });

        wrapper.unmount();

        const callCount = global.fetch.mock.calls.length;
        vi.advanceTimersByTime(60000);

        expect(global.fetch).toHaveBeenCalledTimes(callCount);
    });

    it('provides a refreshBandwidth method for Echo-triggered refresh', () => {
        const wrapper = mount(BandwidthBlock, {
            props: { blockContext: {} },
            global: globalConfig,
        });

        // The component should expose refreshBandwidth
        expect(wrapper.vm.refreshBandwidth).toBeInstanceOf(Function);
    });

    it('refreshes bandwidth data when refreshBandwidth is called', async () => {
        const wrapper = mount(BandwidthBlock, {
            props: { blockContext: {} },
            global: globalConfig,
        });

        global.fetch.mockClear();
        wrapper.vm.refreshBandwidth();

        expect(global.fetch).toHaveBeenCalled();
    });
});
