import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Show from '@/Pages/Admin/Switches/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.mock('@/utils/dates', () => ({
    formatRelative: vi.fn((v) => v),
    formatDate: vi.fn((v) => v),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
}));

const defaultProps = {
    switchConfig: {
        id: 1,
        name: 'Core Switch',
        hostname: '10.0.0.1',
        port: 22,
        type: 'cisco_ios',
        enabled: true,
        timeout: 30,
        last_synced_at: '2024-01-01T00:00:00Z',
        created_at: '2024-01-01T00:00:00Z',
    },
    ports: [],
    canDownloadConfig: false,
    runningConfig: '',
    latestSync: null,
};

function mountShow(propsOverride = {}) {
    return mount(Show, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            mocks: {
                route: (...args) => `/mocked/${args[0]}`,
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div />', props: ['items'] },
                SectionHeader: { template: '<div><slot /></div>', props: ['title'] },
                StatusPill: {
                    template: '<span data-testid="status-pill">{{ label }}</span>',
                    props: ['status', 'label'],
                },
                ConfigBlock: { template: '<div />', props: ['code'] },
                teleport: true,
            },
        },
    });
}

function addCsrfMeta() {
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'test-csrf-token');
    document.head.appendChild(meta);
}

function removeCsrfMeta() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.remove();
}

describe('Show — Sync Status', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('does not render sync status when latestSync is null', () => {
        const wrapper = mountShow({ latestSync: null });
        expect(wrapper.find('[data-testid="sync-status"]').exists()).toBe(false);
    });

    it('displays sync status when latestSync is completed', () => {
        const wrapper = mountShow({
            latestSync: {
                status: 'completed',
                started_at: '2024-01-01T00:00:00Z',
                finished_at: '2024-01-01T00:05:00Z',
                error: null,
                ports_created: 24,
                ports_updated: 12,
                macs_created: 0,
                macs_updated: 0,
            },
        });

        const syncStatus = wrapper.find('[data-testid="sync-status"]');
        expect(syncStatus.exists()).toBe(true);
        expect(syncStatus.text()).toContain('Completed');
        expect(syncStatus.text()).toContain('24 created');
        expect(syncStatus.text()).toContain('12 updated');
        expect(wrapper.find('[data-testid="sync-error"]').exists()).toBe(false);
    });

    it('displays sync status when latestSync shows failed with error', () => {
        const wrapper = mountShow({
            latestSync: {
                status: 'failed',
                started_at: '2024-01-01T00:00:00Z',
                finished_at: '2024-01-01T00:01:00Z',
                error: 'Connection timed out',
                ports_created: 0,
                ports_updated: 0,
                macs_created: 0,
                macs_updated: 0,
            },
        });

        const syncStatus = wrapper.find('[data-testid="sync-status"]');
        expect(syncStatus.exists()).toBe(true);
        expect(syncStatus.text()).toContain('Failed');

        const syncError = wrapper.find('[data-testid="sync-error"]');
        expect(syncError.exists()).toBe(true);
        expect(syncError.text()).toBe('Connection timed out');
    });

    it('displays sync status when latestSync shows running', () => {
        const wrapper = mountShow({
            latestSync: {
                status: 'running',
                started_at: '2024-01-01T00:00:00Z',
                finished_at: null,
                error: null,
                ports_created: 0,
                ports_updated: 0,
                macs_created: 0,
                macs_updated: 0,
            },
        });

        const syncStatus = wrapper.find('[data-testid="sync-status"]');
        expect(syncStatus.exists()).toBe(true);
        expect(syncStatus.text()).toContain('Running');
        expect(syncStatus.text()).toContain('Started');
    });

    it('displays sync status when latestSync shows pending', () => {
        const wrapper = mountShow({
            latestSync: {
                status: 'pending',
                started_at: null,
                finished_at: null,
                error: null,
                ports_created: 0,
                ports_updated: 0,
                macs_created: 0,
                macs_updated: 0,
            },
        });

        const syncStatus = wrapper.find('[data-testid="sync-status"]');
        expect(syncStatus.exists()).toBe(true);
        expect(syncStatus.text()).toContain('Pending');
    });

    it('does not show port counts for non-completed sync', () => {
        const wrapper = mountShow({
            latestSync: {
                status: 'running',
                started_at: '2024-01-01T00:00:00Z',
                finished_at: null,
                error: null,
                ports_created: 0,
                ports_updated: 0,
                macs_created: 0,
                macs_updated: 0,
            },
        });

        const syncStatus = wrapper.find('[data-testid="sync-status"]');
        expect(syncStatus.text()).not.toContain('created');
        expect(syncStatus.text()).not.toContain('updated');
    });

    it('does not show error for failed sync without error message', () => {
        const wrapper = mountShow({
            latestSync: {
                status: 'failed',
                started_at: '2024-01-01T00:00:00Z',
                finished_at: '2024-01-01T00:01:00Z',
                error: null,
                ports_created: 0,
                ports_updated: 0,
                macs_created: 0,
                macs_updated: 0,
            },
        });

        expect(wrapper.find('[data-testid="sync-error"]').exists()).toBe(false);
    });
});

describe('Show — Test Connection Feedback', () => {
    let fetchMock;
    const routeMock = (...args) => `/mocked/${args[0]}`;

    beforeEach(() => {
        vi.clearAllMocks();
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('route', routeMock);
        addCsrfMeta();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
        removeCsrfMeta();
    });

    it('shows success result after test connection succeeds', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({ success: true, message: 'Connection successful.' }),
        });

        const wrapper = mountShow();

        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(false);

        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();

        const result = wrapper.find('[data-testid="test-result"]');
        expect(result.exists()).toBe(true);
        expect(result.text()).toContain('Connection successful.');
        expect(result.text()).toContain('✓');
    });

    it('shows failure result after test connection fails', async () => {
        fetchMock.mockResolvedValue({
            json: () =>
                Promise.resolve({
                    success: false,
                    message: 'Connection test failed. Check the switch configuration and try again.',
                }),
        });

        const wrapper = mountShow();

        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();

        const result = wrapper.find('[data-testid="test-result"]');
        expect(result.exists()).toBe(true);
        expect(result.text()).toContain('Connection test failed.');
        expect(result.text()).toContain('✕');
    });

    it('shows error result when fetch throws', async () => {
        fetchMock.mockRejectedValue(new Error('Network error'));

        const wrapper = mountShow();

        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();

        const result = wrapper.find('[data-testid="test-result"]');
        expect(result.exists()).toBe(true);
        expect(result.text()).toContain('Request failed. Please try again.');
    });

    it('auto-dismisses test result after 10 seconds', async () => {
        vi.useFakeTimers();

        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({ success: true, message: 'Connection successful.' }),
        });

        const wrapper = mountShow();

        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(true);

        vi.advanceTimersByTime(9999);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(true);

        vi.advanceTimersByTime(1);
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(false);
    });

    it('sends CSRF token and correct headers in fetch request', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({ success: true, message: 'Connection successful.' }),
        });

        const wrapper = mountShow();

        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/mocked/admin.switches.test-connection');
        expect(options.method).toBe('POST');
        expect(options.headers['X-CSRF-TOKEN']).toBe('test-csrf-token');
        expect(options.headers['Accept']).toBe('application/json');
    });

    it('disables test button while testing', async () => {
        let resolvePromise;
        fetchMock.mockReturnValue(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mountShow();
        const button = wrapper.find('[data-testid="action-test"]');

        await button.trigger('click');
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(button.attributes('disabled')).toBeDefined();
        expect(button.text()).toBe('Testing…');

        resolvePromise({
            json: () => Promise.resolve({ success: true, message: 'OK' }),
        });
        await flushPromises();

        expect(wrapper.find('[data-testid="action-test"]').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('[data-testid="action-test"]').text()).toBe('Test Connection');
    });

    it('clears previous result when retesting', async () => {
        fetchMock.mockResolvedValue({
            json: () => Promise.resolve({ success: true, message: 'Connection successful.' }),
        });

        const wrapper = mountShow();

        // First test
        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();
        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(true);

        // Second test — set up a pending promise
        let resolveSecond;
        fetchMock.mockReturnValueOnce(
            new Promise((resolve) => {
                resolveSecond = resolve;
            }),
        );

        await wrapper.find('[data-testid="action-test"]').trigger('click');
        await flushPromises();
        await wrapper.vm.$nextTick();
        // During loading, previous result should be cleared
        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(false);

        resolveSecond({
            json: () => Promise.resolve({ success: false, message: 'Failed this time.' }),
        });
        await flushPromises();

        const result = wrapper.find('[data-testid="test-result"]');
        expect(result.exists()).toBe(true);
        expect(result.text()).toContain('Failed this time.');
    });
});
