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
    formatRelative: vi.fn((v) => v),
    formatDate: vi.fn((v) => v),
}));

vi.mock('@/utils/switches', () => ({
    typeLabel: vi.fn((v) => v),
    statusLabel: vi.fn((v) => v ?? '—'),
    formatSpeed: vi.fn((v) => v ?? '—'),
    formatVlan: vi.fn((vlan) => (vlan == null ? '—' : String(vlan))),
}));

// Set up global route function for script setup
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

describe('Show.vue - Polish', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('Layout structure', () => {
        it('renders updated layout wrappers for wireframe parity', () => {
            const wrapper = mountShow();
            expect(wrapper.find('[data-testid="switch-show-layout"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="switch-show-header-card"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="switch-show-actions"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="switch-details-card"]').exists()).toBe(true);
            expect(wrapper.find('[data-testid="switch-ports-card"]').exists()).toBe(true);
        });

        it('does not render delete button on Show page', () => {
            const wrapper = mountShow();
            expect(wrapper.find('[data-testid="action-delete"]').exists()).toBe(false);
        });
    });

    describe('Action button tooltips', () => {
        it('action buttons have title attributes', () => {
            const wrapper = mountShow();

            // Check sync button tooltip
            const syncButton = wrapper.find('[data-testid="action-sync"]');
            expect(syncButton.exists()).toBe(true);
            expect(syncButton.attributes('title')).toBe('Trigger a port sync from the switch');

            // Check test button tooltip
            const testButton = wrapper.find('[data-testid="action-test"]');
            expect(testButton.exists()).toBe(true);
            expect(testButton.attributes('title')).toBe('Test SSH connectivity to this switch');
        });
    });
});
