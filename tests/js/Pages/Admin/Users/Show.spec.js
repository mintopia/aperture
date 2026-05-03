import { mount } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import Show from '@/Pages/Admin/Users/Show.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        visit: vi.fn(),
    },
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
    useForm: vi.fn((initial) => ({
        ...initial,
        processing: false,
        errors: {},
        clearErrors: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    })),
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.stubGlobal('route', (name, param) => (param ? `/mocked/${name}/${param}` : `/mocked/${name}`));

global.fetch = vi.fn(() =>
    Promise.resolve({
        ok: true,
        json: () =>
            Promise.resolve({
                timestamps: [],
                download: [],
                upload: [],
                totalReceived: 0,
                totalSent: 0,
            }),
    }),
);

const routeMock = (name, ...params) => (params.length ? `/mocked/${name}/${params.join('/')}` : `/mocked/${name}`);

const defaultGlobal = {
    stubs: {
        AdminLayout: { template: '<div><slot /></div>' },
        MetadataStrip: { template: '<div data-testid="metadata-strip" />' },
        TimeSeriesChart: { template: '<div data-testid="bandwidth-chart" />' },
        ConfirmModal: {
            template: '<div v-if="show" data-testid="confirm-modal"><slot /></div>',
            props: ['show', 'title', 'message', 'confirmLabel', 'variant', 'loading'],
        },
        FormField: {
            template: '<div><slot /></div>',
            props: ['label', 'name', 'required', 'error'],
        },
        teleport: true,
    },
    mocks: {
        route: routeMock,
    },
};

describe('Users Show', () => {
    const makeProps = (overrides = {}) => ({
        user: {
            id: 1,
            nickname: 'testuser',
            email: 'test@example.com',
            internet_blocked: false,
            ...(overrides.user ?? {}),
        },
        roles: [{ name: 'user' }],
        networkDevices: [
            {
                mac_address: 'aa:bb:cc:dd:ee:01',
                ip_address: '10.0.0.1',
                hostname: 'device-one',
                switch_name: 'Switch-A',
                switch_id: 1,
                port_name: 'Gi0/1',
                internet_enabled: true,
                rate_limit_enabled: false,
                last_seen_at: '2026-04-17T11:00:00.000Z',
            },
            {
                mac_address: 'aa:bb:cc:dd:ee:02',
                ip_address: '10.0.0.2',
                hostname: null,
                switch_name: null,
                switch_id: null,
                port_name: null,
                internet_enabled: false,
                rate_limit_enabled: true,
                last_seen_at: null,
            },
        ],
        allInternetEnabled: true,
        allRateLimited: false,
        ipCount: 2,
        auditLogs: [],
        parameters: [],
        ...Object.fromEntries(Object.entries(overrides).filter(([k]) => k !== 'user')),
    });

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-17T12:00:00.000Z'));
        vi.mocked(global.fetch).mockClear();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders user nickname as page title', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('testuser');
    });

    it('renders the network devices section', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="user-devices-section"]').exists()).toBe(true);
    });

    it('renders device MAC addresses as links', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const macCells = wrapper.findAll('[data-testid="device-mac"]');
        expect(macCells[0].text()).toContain('aa:bb:cc:dd:ee:01');
        expect(macCells[0].find('a').exists()).toBe(true);
    });

    it('renders device IP addresses as links', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const ipCells = wrapper.findAll('[data-testid="device-ip"]');
        expect(ipCells[0].text()).toContain('10.0.0.1');
        expect(ipCells[0].find('a').exists()).toBe(true);
    });

    it('renders device internet status with dot indicators', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const internetCells = wrapper.findAll('[data-testid="device-internet"]');
        expect(internetCells[0].text()).toContain('Enabled');
        expect(internetCells[1].text()).toContain('Disabled');
    });

    it('renders device rate limit status', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const rateLimitCells = wrapper.findAll('[data-testid="device-rate-limit"]');
        expect(rateLimitCells[0].text()).toContain('None');
        expect(rateLimitCells[1].text()).toContain('Limited');
    });

    it('renders bandwidth chart section', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="user-bandwidth-section"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-chart"]').exists()).toBe(true);
    });

    it('renders bandwidth range selector buttons', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        const rangeSelector = wrapper.find('[data-testid="bandwidth-range-selector"]');
        expect(rangeSelector.exists()).toBe(true);

        const buttons = rangeSelector.findAll('button');
        expect(buttons).toHaveLength(3);
        expect(buttons[0].text()).toBe('1H');
        expect(buttons[1].text()).toBe('24H');
        expect(buttons[2].text()).toBe('72H');
    });

    it('renders bandwidth download and upload totals', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="bandwidth-download"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-upload"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="bandwidth-download"]').text()).toContain('Down');
        expect(wrapper.find('[data-testid="bandwidth-upload"]').text()).toContain('Up');
    });

    it('renders audit log section', () => {
        const wrapper = mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="user-audit-section"]').exists()).toBe(true);
    });

    it('calls fetch for bandwidth data on mount when ipCount > 0', () => {
        mount(Show, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(global.fetch).toHaveBeenCalled();
        expect(global.fetch.mock.calls[0][0]).toContain('admin.users.bandwidth');
    });

    it('does not call fetch for bandwidth when ipCount is 0', () => {
        mount(Show, {
            props: makeProps({ ipCount: 0 }),
            global: defaultGlobal,
        });

        expect(global.fetch).not.toHaveBeenCalled();
    });
});
