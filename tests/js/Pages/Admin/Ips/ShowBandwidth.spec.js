import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Show from '@/Pages/Admin/Ips/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name, param) => `/mocked/${name}/${param ?? ''}`);

const defaultBandwidthResponse = {
    timestamps: [1700000000, 1700003600],
    download: [1000, 2000],
    upload: [500, 1000],
    totalReceived: 3000,
    totalSent: 1500,
};

const defaultProps = {
    ip: { id: 1, address: '10.0.0.1', internet_enabled: true, comment: 'Test' },
    port: null,
    switchInfo: null,
    users: [],
};

// TimeSeriesChart stub component — defined once so findComponent(TimeSeriesChartStub) works
const TimeSeriesChartStub = {
    name: 'TimeSeriesChart',
    template: '<div data-testid="admin-bandwidth-chart" />',
    props: ['series', 'loading', 'yAxisLabel', 'height', 'emptyMessage'],
};

function mockFetchSuccess(response = defaultBandwidthResponse) {
    window.axios = { get: vi.fn().mockResolvedValue({ data: response }) };
}

function mockFetchError() {
    window.axios = { get: vi.fn().mockRejectedValue(new Error('Network error')) };
}

function mockFetchNonOk() {
    window.axios = { get: vi.fn().mockRejectedValue(new Error('Request failed with status 400')) };
}

function mountPage(overrides = {}) {
    return mount(Show, {
        props: { ...defaultProps, ...overrides },
        global: {
            mocks: {
                route: (name, param) => `/mocked/${name}/${param ?? ''}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div data-testid="metadata-strip" />' },
                SectionHeader: { template: '<div><slot /></div>' },
                DataTable: { template: '<div data-testid="data-table" />' },
                TimeSeriesChart: TimeSeriesChartStub,
                teleport: true,
            },
        },
    });
}

describe('Admin IP Show bandwidth chart', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockFetchSuccess();
    });

    it('fetches bandwidth with default range 24h on mount', async () => {
        mountPage();
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledWith(expect.stringContaining('?range=24h'));
    });

    it('shows loading state during fetch', async () => {
        let resolveGet;
        const getPromise = new Promise((resolve) => {
            resolveGet = resolve;
        });
        window.axios = { get: vi.fn().mockReturnValue(getPromise) };

        const wrapper = mountPage();

        // Before fetch resolves, loading should be true
        const chart = wrapper.findComponent(TimeSeriesChartStub);
        expect(chart.props('loading')).toBe(true);

        resolveGet({ data: defaultBandwidthResponse });
        await flushPromises();

        expect(chart.props('loading')).toBe(false);
    });

    it('re-fetches bandwidth when range selector changes', async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(1);

        await wrapper.find('[data-testid="range-1h"]').trigger('click');
        await flushPromises();

        expect(window.axios.get).toHaveBeenCalledTimes(2);
        expect(window.axios.get).toHaveBeenLastCalledWith(expect.stringContaining('?range=1h'));
    });

    it('re-fetches bandwidth when 4d range is selected', async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.find('[data-testid="range-4d"]').trigger('click');
        await flushPromises();

        expect(window.axios.get).toHaveBeenLastCalledWith(expect.stringContaining('?range=4d'));
    });

    it('renders range buttons for 1h, 24h, 4d and 7d', async () => {
        const wrapper = mountPage();
        await flushPromises();

        const selector = wrapper.find('[data-testid="bandwidth-range-selector"]');
        const buttons = selector.findAll('button');

        expect(buttons.map((b) => b.text())).toEqual(['1h', '24h', '4d', '7d']);
        expect(selector.find('[data-testid="range-7d"]').exists()).toBe(true);
    });

    it('re-fetches bandwidth when 7d range is selected', async () => {
        const wrapper = mountPage();
        await flushPromises();

        await wrapper.find('[data-testid="range-7d"]').trigger('click');
        await flushPromises();

        expect(window.axios.get).toHaveBeenLastCalledWith(expect.stringContaining('?range=7d'));
    });

    it('chartSeries computed maps timestamps and data correctly', async () => {
        const wrapper = mountPage();
        await flushPromises();

        const chart = wrapper.findComponent(TimeSeriesChartStub);
        const series = chart.props('series');

        expect(series).toHaveLength(2);

        const download = series.find((s) => s.label === 'Download');
        expect(download).toBeDefined();
        expect(download.data).toEqual([
            { timestamp: 1700000000, value: 1000 },
            { timestamp: 1700003600, value: 2000 },
        ]);

        const upload = series.find((s) => s.label === 'Upload');
        expect(upload).toBeDefined();
        expect(upload.data).toEqual([
            { timestamp: 1700000000, value: 500 },
            { timestamp: 1700003600, value: 1000 },
        ]);
    });

    it('chartSeries returns empty array when timestamps are empty', async () => {
        mockFetchSuccess({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 });

        const wrapper = mountPage();
        await flushPromises();

        const chart = wrapper.findComponent(TimeSeriesChartStub);
        expect(chart.props('series')).toEqual([]);
    });

    it('shows error message when fetch returns a non-ok response', async () => {
        mockFetchNonOk();

        const wrapper = mountPage();
        await flushPromises();

        const error = wrapper.find('[data-testid="bandwidth-error"]');
        expect(error.exists()).toBe(true);
        expect(error.text()).toContain('Failed to load bandwidth data');
    });

    it('shows error message when fetch throws a network error', async () => {
        mockFetchError();

        const wrapper = mountPage();
        await flushPromises();

        const error = wrapper.find('[data-testid="bandwidth-error"]');
        expect(error.exists()).toBe(true);
        expect(error.text()).toContain('Failed to load bandwidth data');
    });

    it('does not show error message on successful fetch', async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(false);
    });

    it('clears error state on successful retry after error', async () => {
        mockFetchError();

        const wrapper = mountPage();
        await flushPromises();

        expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(true);

        // Restore fetch to succeed and trigger a new range selection
        mockFetchSuccess();

        await wrapper.find('[data-testid="range-1h"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="bandwidth-error"]').exists()).toBe(false);
    });
});
