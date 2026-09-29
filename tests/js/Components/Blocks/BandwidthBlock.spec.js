import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import BandwidthBlock from '@/Components/Blocks/BandwidthBlock.vue';

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

        expect(global.fetch).toHaveBeenCalledTimes(1);

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

    describe('error state', () => {
        const mountBlock = () => mount(BandwidthBlock, { props: { blockContext: {} }, global: globalConfig });
        const okResponse = {
            ok: true,
            json: () => Promise.resolve({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 }),
        };

        it('shows an error when the request rejects', async () => {
            global.fetch = vi.fn().mockRejectedValue(new Error('network'));
            const wrapper = mountBlock();
            await flushPromises();
            expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(true);
        });

        it('shows an error when the response is not ok', async () => {
            global.fetch = vi.fn().mockResolvedValue({ ok: false, status: 500 });
            const wrapper = mountBlock();
            await flushPromises();
            expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(true);
        });

        it('clears the error after a successful poll', async () => {
            global.fetch = vi.fn().mockRejectedValueOnce(new Error('network')).mockResolvedValue(okResponse);
            const wrapper = mountBlock();
            await flushPromises();
            expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(true);

            vi.advanceTimersByTime(30000);
            await flushPromises();
            expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(false);
        });
    });
});
