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
    formatSpeed: vi.fn((v) => v ?? '—'),
    formatVlan: vi.fn((vlan) => (vlan == null ? '—' : String(vlan))),
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
