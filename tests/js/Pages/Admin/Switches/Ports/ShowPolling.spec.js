import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { router } from '@inertiajs/vue3';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
        reload: vi.fn(),
    },
    Link: {
        template: '<a :href="href" :data-testid="$attrs[\'data-testid\']"><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.mock('@/utils/dates', () => ({
    formatRelative: vi.fn((v) => `relative(${v})`),
    formatDate: vi.fn((v) => v),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
    formatPortStatus: vi.fn((admin, oper) => `${admin} / ${oper}`),
    formatSpeed: vi.fn((v) => v ?? '—'),
    formatDuplex: vi.fn((v) => v ?? '—'),
    formatVlan: vi.fn((vlan) => `${vlan}`),
}));

vi.mock('@/helpers.js', () => ({
    formatBytes: vi.fn((v) => `${v} bytes`),
}));

vi.stubGlobal('route', (name, params) => {
    if (typeof params === 'object' && params !== null) {
        return `/mocked/${name}/${JSON.stringify(params)}`;
    }

    return `/mocked/${name}/${params}`;
});

const defaultProps = {
    switchConfig: {
        id: 42,
        name: 'sw-lab',
        hostname: 'sw-lab.local',
        type: 'cisco_ios',
        enabled: true,
        port: 22,
        timeout: 30,
    },
    port: {
        id: 1,
        interface: 'Gi1/0/24',
        description: 'Test Port',
        status: 'connected',
        admin_status: 'up',
        speed: '1Gbps',
        vlan: 100,
        poe: 'on',
        config_text: null,
        last_synced_at: '2024-06-15T10:30:00Z',
    },
    macs: [],
    bandwidth: { in: [], out: [], in_bytes: 0, out_bytes: 0 },
    errors: { input: 0, output: 0, crc: 0, collisions: 0, in_series: [], out_series: [] },
    metricsAvailable: false,
    prevPort: null,
    nextPort: null,
};

function mountShow(propsOverride = {}) {
    return mount(Show, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            mocks: {
                route: (name, params) => {
                    if (typeof params === 'object' && params !== null) {
                        return `/mocked/${name}/${JSON.stringify(params)}`;
                    }
                    return `/mocked/${name}/${params}`;
                },
            },
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                MetadataStrip: { template: '<div />', props: ['items'] },
                SectionHeader: { template: '<div><slot /></div>', props: ['title', 'accentLine'] },
                StatusPill: { template: '<span>{{ label }}</span>', props: ['status', 'label'] },
                StatCard: { template: '<div />', props: ['label', 'value', 'color'] },
                ConfigBlock: { template: '<div />', props: ['code'] },
                TimeSeriesChart: {
                    template: '<div />',
                    props: ['series', 'yAxisLabel', 'height', 'emptyMessage'],
                },
                teleport: true,
            },
        },
    });
}

describe('Show — Polling & Last Updated', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('initializes lastUpdated on mount', () => {
        const now = new Date('2024-06-15T12:00:00Z');
        vi.setSystemTime(now);
        const wrapper = mountShow();
        const el = wrapper.find('[data-testid="last-updated"]');

        expect(el.exists()).toBe(true);
        expect(el.text()).toContain('just now');
    });

    it('sets up polling interval on mount', () => {
        router.reload.mockImplementation(() => {});
        mountShow();

        expect(router.reload).not.toHaveBeenCalled();
        vi.advanceTimersByTime(30000);
        expect(router.reload).toHaveBeenCalledTimes(1);
    });

    it('clears polling interval on unmount', () => {
        router.reload.mockImplementation(() => {});
        const wrapper = mountShow();
        wrapper.unmount();

        vi.advanceTimersByTime(30000);
        expect(router.reload).not.toHaveBeenCalled();
    });

    it('displays "just now" initially for last-updated text', () => {
        const wrapper = mountShow();
        const el = wrapper.find('[data-testid="last-updated"]');

        expect(el.text()).toContain('just now');
    });

    it('updates display time after seconds pass', async () => {
        const wrapper = mountShow();

        vi.advanceTimersByTime(11000);
        await flushPromises();
        await wrapper.vm.$nextTick();

        const el = wrapper.find('[data-testid="last-updated"]');
        expect(el.text()).not.toContain('just now');
    });

    it('shows "Xs ago" format after 15 seconds', async () => {
        const wrapper = mountShow();

        vi.advanceTimersByTime(15000);
        await flushPromises();
        await wrapper.vm.$nextTick();

        const el = wrapper.find('[data-testid="last-updated"]');
        expect(el.text()).toContain('15s ago');
    });

    it('shows "Xm ago" format after 60+ seconds', async () => {
        const wrapper = mountShow();

        vi.advanceTimersByTime(65000);
        await flushPromises();
        await wrapper.vm.$nextTick();

        const el = wrapper.find('[data-testid="last-updated"]');
        expect(el.text()).toContain('1m ago');
    });

    it('header refresh button triggers router.visit', async () => {
        router.visit.mockImplementation((url, { onSuccess, onFinish }) => {
            if (onSuccess) onSuccess();
            if (onFinish) onFinish();
        });
        const wrapper = mountShow();

        await wrapper.find('[data-testid="action-refresh"]').trigger('click');
        await flushPromises();

        expect(router.visit).toHaveBeenCalled();
    });

    it('header refresh updates lastUpdated timestamp', async () => {
        router.visit.mockImplementation((url, { onSuccess, onFinish }) => {
            if (onSuccess) onSuccess();
            if (onFinish) onFinish();
        });
        const wrapper = mountShow();

        // Advance time so display shows something other than "just now"
        vi.advanceTimersByTime(20000);
        await flushPromises();
        await wrapper.vm.$nextTick();
        expect(wrapper.find('[data-testid="last-updated"]').text()).toContain('20s ago');

        // Trigger refresh — resets lastUpdated to current time
        await wrapper.find('[data-testid="action-refresh"]').trigger('click');
        await flushPromises();

        // Advance 5s for the display timer to recalculate
        vi.advanceTimersByTime(5000);
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="last-updated"]').text()).toContain('just now');
    });

    it('polling calls router.reload with correct options', () => {
        router.reload.mockImplementation(() => {});
        mountShow();

        vi.advanceTimersByTime(30000);

        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['port', 'macs', 'bandwidth', 'errors'],
                preserveScroll: true,
            }),
        );
    });
});
