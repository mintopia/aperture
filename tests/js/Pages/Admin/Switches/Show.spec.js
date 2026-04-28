import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
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
    formatRelative: vi.fn((v) => `relative:${v}`),
    formatDate: vi.fn((v) => `date:${v}`),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
    formatSpeed: vi.fn((v) => v ?? '—'),
    formatVlan: vi.fn((vlan) => (vlan == null ? '—' : String(vlan))),
}));

globalThis.route = (...args) => `/mocked/${args[0]}`;

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
                MetadataStrip: { name: 'MetadataStrip', template: '<div />', props: ['items'] },
                DataTable: {
                    template: '<div />',
                    props: ['columns', 'rows', 'rowClass', 'clickable', 'rowHref', 'rowAriaLabel', 'emptyMessage'],
                },
                ConfigBlock: { template: '<div data-testid="config-block" />', props: ['code'] },
                teleport: true,
            },
        },
    });
}

describe('Show.vue - broken template references', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('props', () => {
        it('accepts canDownloadConfig prop', () => {
            const wrapper = mountShow({ canDownloadConfig: true });
            expect(wrapper.exists()).toBe(true);
        });

        it('accepts latestSync prop as null', () => {
            const wrapper = mountShow({ latestSync: null });
            expect(wrapper.exists()).toBe(true);
        });

        it('accepts latestSync prop as object', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'completed',
                    finished_at: '2024-01-01T01:00:00Z',
                    started_at: '2024-01-01T00:55:00Z',
                    ports_created: 5,
                    ports_updated: 10,
                    error: null,
                },
            });
            expect(wrapper.exists()).toBe(true);
        });
    });

    describe('latestSync section', () => {
        it('does not render sync status section when latestSync is null', () => {
            const wrapper = mountShow({ latestSync: null });
            expect(wrapper.find('[data-testid="sync-status"]').exists()).toBe(false);
        });

        it('renders sync status section when latestSync is provided', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'completed',
                    finished_at: '2024-01-01T01:00:00Z',
                    started_at: null,
                    ports_created: 3,
                    ports_updated: 7,
                    error: null,
                },
            });
            expect(wrapper.find('[data-testid="sync-status"]').exists()).toBe(true);
        });

        it('shows error message when sync status is failed and error is present', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'failed',
                    finished_at: null,
                    started_at: '2024-01-01T00:55:00Z',
                    ports_created: 0,
                    ports_updated: 0,
                    error: 'Connection timed out',
                },
            });
            const errorEl = wrapper.find('[data-testid="sync-error"]');
            expect(errorEl.exists()).toBe(true);
            expect(errorEl.text()).toContain('Connection timed out');
        });

        it('does not show error element when sync status is completed', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'completed',
                    finished_at: '2024-01-01T01:00:00Z',
                    started_at: null,
                    ports_created: 1,
                    ports_updated: 2,
                    error: null,
                },
            });
            expect(wrapper.find('[data-testid="sync-error"]').exists()).toBe(false);
        });

        it('shows started_at when finished_at is absent', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'running',
                    finished_at: null,
                    started_at: '2024-01-01T00:55:00Z',
                    ports_created: 0,
                    ports_updated: 0,
                    error: null,
                },
            });
            const syncSection = wrapper.find('[data-testid="sync-status"]');
            expect(syncSection.text()).toContain('Started');
        });
    });

    describe('canDownloadConfig / running config section', () => {
        it('does not render running-config-card when canDownloadConfig is false', () => {
            const wrapper = mountShow({ canDownloadConfig: false });
            expect(wrapper.find('[data-testid="running-config-card"]').exists()).toBe(false);
        });

        it('renders running-config-card when canDownloadConfig is true', () => {
            const wrapper = mountShow({ canDownloadConfig: true });
            expect(wrapper.find('[data-testid="running-config-card"]').exists()).toBe(true);
        });

        it('renders toggle and download buttons inside running-config-card', () => {
            const wrapper = mountShow({ canDownloadConfig: true });
            expect(wrapper.find('[data-testid="action-toggle-config"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="action-download-config"]').exists()).toBe(true);
        });

        it('shows "View Running Config" text when config is hidden', () => {
            const wrapper = mountShow({ canDownloadConfig: true });
            expect(wrapper.find('[data-testid="action-toggle-config"]').text()).toBe('View Running Config');
        });

        it('shows "Hide Config" text when config is toggled on', async () => {
            const wrapper = mountShow({ canDownloadConfig: true });
            await wrapper.find('[data-testid="action-toggle-config"]').trigger('click');
            expect(wrapper.find('[data-testid="action-toggle-config"]').text()).toBe('Hide Config');
        });

        it('shows no-config message when toggled on but no runningConfig prop', async () => {
            const wrapper = mountShow({ canDownloadConfig: true, runningConfig: null });
            await wrapper.find('[data-testid="action-toggle-config"]').trigger('click');
            expect(wrapper.text()).toContain('No running config available');
        });

        it('renders ConfigBlock when showConfig is true and runningConfig is provided', async () => {
            const wrapper = mountShow({
                canDownloadConfig: true,
                runningConfig: 'interface GigabitEthernet0/1\n no shutdown',
            });
            await wrapper.find('[data-testid="action-toggle-config"]').trigger('click');
            expect(wrapper.find('[data-testid="config-block"]').exists()).toBe(true);
        });
    });

    describe('metadata strip last synced', () => {
        it('passes last_synced_at through formatRelative to the metadata strip', () => {
            const wrapper = mountShow({
                switchConfig: {
                    ...defaultProps.switchConfig,
                    last_synced_at: '2024-06-15T14:30:00Z',
                },
            });
            const strip = wrapper.findComponent({ name: 'MetadataStrip' });
            expect(strip.exists()).toBe(true);
            const lastSyncedItem = strip.props('items').find((i) => i.label === 'Last Synced');
            expect(lastSyncedItem).toBeDefined();
            expect(lastSyncedItem.value).toBe('relative:2024-06-15T14:30:00Z');
        });

        it('shows Never when last_synced_at is null', () => {
            const wrapper = mountShow({
                switchConfig: {
                    ...defaultProps.switchConfig,
                    last_synced_at: null,
                },
            });
            const strip = wrapper.findComponent({ name: 'MetadataStrip' });
            const lastSyncedItem = strip.props('items').find((i) => i.label === 'Last Synced');
            expect(lastSyncedItem).toBeDefined();
            expect(lastSyncedItem.value).toBe('Never');
        });
    });

    describe('syncStatusType and syncStatusLabel helper functions', () => {
        it('renders correct status display for completed sync', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'completed',
                    finished_at: '2024-01-01T01:00:00Z',
                    started_at: null,
                    ports_created: 0,
                    ports_updated: 0,
                    error: null,
                },
            });
            const syncSection = wrapper.find('[data-testid="sync-status"]');
            expect(syncSection.exists()).toBe(true);
        });

        it('renders sync status for failed state', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'failed',
                    finished_at: null,
                    started_at: '2024-01-01T00:55:00Z',
                    ports_created: 0,
                    ports_updated: 0,
                    error: 'Auth failed',
                },
            });
            const syncSection = wrapper.find('[data-testid="sync-status"]');
            expect(syncSection.exists()).toBe(true);
        });

        it('renders sync status for running state', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'running',
                    finished_at: null,
                    started_at: '2024-01-01T00:55:00Z',
                    ports_created: 0,
                    ports_updated: 0,
                    error: null,
                },
            });
            expect(wrapper.find('[data-testid="sync-status"]').exists()).toBe(true);
        });

        it('renders sync status for pending state', () => {
            const wrapper = mountShow({
                latestSync: {
                    status: 'pending',
                    finished_at: null,
                    started_at: null,
                    ports_created: 0,
                    ports_updated: 0,
                    error: null,
                },
            });
            expect(wrapper.find('[data-testid="sync-status"]').exists()).toBe(true);
        });
    });
});
