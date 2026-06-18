import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import Show from '@/Pages/Admin/Switches/Ports/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        visit: vi.fn(),
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
    typeLabel: vi.fn((v) => `TypeLabel(${v})`),
    statusLabel: vi.fn((v) => v ?? '—'),
    formatPortStatus: vi.fn((admin, oper) => `${admin} / ${oper}`),
    formatSpeed: vi.fn((v) => `Speed(${v})`),
    formatDuplex: vi.fn((v) => `Duplex(${v})`),
    formatVlan: vi.fn((vlan, mode) => `Vlan(${vlan},${mode})`),
}));

vi.mock('@/helpers.js', () => ({
    formatBytes: vi.fn((v) => `${v} bytes`),
    formatBytesComponents: vi.fn((v) => ({ value: `${v}`, unit: 'bytes' })),
}));

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
        duplex: 'full',
        switchport_mode: 'access',
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
                MetadataStrip: {
                    template:
                        '<div data-testid="metadata-strip"><span v-for="item in items" :key="item.label" :data-testid="\'meta-\' + item.label">{{ item.label }}: {{ item.value }}</span></div>',
                    props: ['items'],
                },
                SectionHeader: {
                    template: '<div :data-title="title"><span>{{ title }}</span><slot /></div>',
                    props: ['title'],
                },
                StatusPill: {
                    template: '<span>{{ label }}</span>',
                    props: ['status', 'label'],
                },
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

describe('Show — Port Navigation Context', () => {
    it('renders metadata strip below the page title', () => {
        const wrapper = mountShow();
        const metadataStrip = wrapper.find('[data-testid="metadata-strip"]');

        expect(metadataStrip.exists()).toBe(true);
        expect(wrapper.html().indexOf('page-title')).toBeLessThan(wrapper.html().indexOf('metadata-strip'));
    });

    it('renders two-column layout with left and right columns', () => {
        const wrapper = mountShow();
        const layoutColumns = wrapper.find('[data-testid="layout-columns"]');

        expect(layoutColumns.exists()).toBe(true);
        expect(layoutColumns.classes()).toContain('xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]');
        expect(wrapper.find('[data-testid="layout-column-left"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="layout-column-right"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="connected-devices-section"]').exists()).toBe(true);
        expect(
            wrapper.find('[data-testid="errors-section"]').exists() ||
                wrapper.find('[data-testid="errors-fallback"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-testid="running-config-section"]').exists()).toBe(true);
    });

    it('renders interface output section', () => {
        const wrapper = mountShow();
        const interfaceOutputSection = wrapper.find('[data-testid="section-interface-output"]');

        expect(interfaceOutputSection.exists()).toBe(true);
        expect(interfaceOutputSection.text()).toContain('Interface Output');
    });

    it('does not render switch context strip in page header (context is in breadcrumbs)', () => {
        const wrapper = mountShow();

        expect(wrapper.find('[data-testid="port-context-strip"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="port-switch-link"]').exists()).toBe(false);
    });

    it('renders prev port link when prevPort is provided', () => {
        const wrapper = mountShow({ prevPort: 'Gi1/0/23' });
        const prev = wrapper.find('[data-testid="port-nav-prev"]');

        expect(prev.exists()).toBe(true);
        expect(prev.text()).toContain('Gi1/0/23');
        expect(prev.text()).toContain('←');
        expect(prev.attributes('href')).toContain('admin.switches.ports.show');
    });

    it('renders next port link when nextPort is provided', () => {
        const wrapper = mountShow({ nextPort: 'Gi1/0/25' });
        const next = wrapper.find('[data-testid="port-nav-next"]');

        expect(next.exists()).toBe(true);
        expect(next.text()).toContain('Gi1/0/25');
        expect(next.text()).toContain('→');
        expect(next.attributes('href')).toContain('admin.switches.ports.show');
    });

    it('hides prev link when no prevPort is provided', () => {
        const wrapper = mountShow({ prevPort: null });
        const prev = wrapper.find('[data-testid="port-nav-prev"]');

        expect(prev.exists()).toBe(false);
    });

    it('hides next link when no nextPort is provided', () => {
        const wrapper = mountShow({ nextPort: null });
        const next = wrapper.find('[data-testid="port-nav-next"]');

        expect(next.exists()).toBe(false);
    });

    it('renders both prev and next links when both are provided', () => {
        const wrapper = mountShow({ prevPort: 'Gi1/0/23', nextPort: 'Gi1/0/25' });

        expect(wrapper.find('[data-testid="port-nav-prev"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="port-nav-next"]').exists()).toBe(true);
    });

    it('shows formatted speed and duplex in metadata strip', () => {
        const wrapper = mountShow();
        const metaStrip = wrapper.find('[data-testid="metadata-strip"]');

        expect(metaStrip.text()).toContain('Speed');
        expect(metaStrip.text()).toContain('Speed(1Gbps) Duplex(full)');
    });

    it('shows formatted vlan in metadata strip', () => {
        const wrapper = mountShow();
        const metaStrip = wrapper.find('[data-testid="metadata-strip"]');

        expect(metaStrip.text()).toContain('VLAN');
        expect(metaStrip.text()).toContain('Vlan(100,access)');
    });

    it('still renders the page title with port interface name', () => {
        const wrapper = mountShow();
        const title = wrapper.find('[data-testid="page-title"]');

        expect(title.exists()).toBe(true);
        expect(title.text()).toBe('Gi1/0/24');
    });

    it('uses simplified header controls and removes header status duplication', () => {
        const wrapper = mountShow();
        const headerActions = wrapper.find('[data-testid="header-actions"]');

        expect(headerActions.exists()).toBe(true);
        expect(headerActions.find('[data-testid="action-refresh"]').exists()).toBe(true);
        expect(headerActions.find('[data-testid="action-toggle"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="port-status"]').exists()).toBe(false);
    });
});
