import { mount } from '@vue/test-utils';
import { defineComponent } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '@/Pages/Portal/Dashboard.vue';

vi.mock('@inertiajs/vue3', async () => {
    return {
        usePage: () => ({
            props: {
                auth: { user: { nickname: 'TestUser' } },
            },
        }),
    };
});

vi.stubGlobal(
    'route',
    vi.fn(() => '/mock-route'),
);

const BlockGridStub = defineComponent({
    name: 'BlockGrid',
    props: ['blocks', 'blockContext'],
    template: '<div data-testid="block-grid-stub"></div>',
});

const DnsWarningBlockStub = defineComponent({
    name: 'DnsWarningBlock',
    props: ['checkUrl', 'warningMessage'],
    template: '<div data-testid="dns-warning-stub"></div>',
});

const defaultGlobal = {
    stubs: {
        PortalLayout: { template: '<div><slot /></div>' },
        BlockGrid: BlockGridStub,
        DnsWarningBlock: DnsWarningBlockStub,
    },
};

describe('Portal Dashboard', () => {
    const makeProps = (overrides = {}) => ({
        blocks: [],
        blockContext: {
            currentIpv4: '10.0.0.1',
            currentIpv6: 'fe80::1',
            internetEnabled: true,
            macAddress: 'AA:BB:CC:DD:EE:FF',
            user: { name: 'Player', params: { seat: 'A42' } },
        },
        dnsDetection: null,
        ...overrides,
    });

    it('renders the welcome heading with user nickname', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Welcome, TestUser');
    });

    it('renders the page title element', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="page-title"]').exists()).toBe(true);
    });

    it('passes blocks and blockContext to BlockGrid', () => {
        const blocks = [
            {
                id: 1,
                type: 'custom_markdown',
                title: 'Info',
                content: 'Hello',
                grid_col: 1,
                grid_row: 1,
                col_span: 1,
                row_span: 1,
                is_active: true,
                settings: null,
            },
        ];
        const blockContext = {
            currentIpv4: '192.168.1.1',
            currentIpv6: '',
            internetEnabled: false,
            macAddress: null,
            user: { name: '', params: {} },
        };

        const wrapper = mount(Dashboard, {
            props: makeProps({ blocks, blockContext }),
            global: defaultGlobal,
        });

        const blockGrid = wrapper.findComponent(BlockGridStub);
        expect(blockGrid.exists()).toBe(true);
        expect(blockGrid.props('blocks')).toEqual(blocks);
        expect(blockGrid.props('blockContext')).toEqual(blockContext);
    });

    it('renders DnsWarningBlock when dnsDetection is provided', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({
                dnsDetection: {
                    checkUrl: 'https://test.example.com',
                    warningMessage: 'Fix your DNS!',
                },
            }),
            global: defaultGlobal,
        });

        const dnsWarning = wrapper.findComponent(DnsWarningBlockStub);
        expect(dnsWarning.exists()).toBe(true);
        expect(dnsWarning.props('checkUrl')).toBe('https://test.example.com');
        expect(dnsWarning.props('warningMessage')).toBe('Fix your DNS!');
    });

    it('does not render DnsWarningBlock when dnsDetection is null', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({ dnsDetection: null }),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="dns-warning-stub"]').exists()).toBe(false);
    });

    it('renders BlockGrid even with empty blocks', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({ blocks: [] }),
            global: defaultGlobal,
        });

        const blockGrid = wrapper.findComponent(BlockGridStub);
        expect(blockGrid.exists()).toBe(true);
        expect(blockGrid.props('blocks')).toEqual([]);
    });

    it('does not render hardcoded connection strip or hero blocks', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps(),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="connection-strip"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('IPv4');
        expect(wrapper.text()).not.toContain('IPv6');
    });

    it('shows cover image when coverImage prop is provided', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({ coverImage: 'https://example.com/cover.jpg' }),
            global: defaultGlobal,
        });

        const cover = wrapper.find('[data-testid="dashboard-cover"]');
        expect(cover.exists()).toBe(true);
    });

    it('does not show cover element when coverImage is null', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({ coverImage: null }),
            global: defaultGlobal,
        });

        expect(wrapper.find('[data-testid="dashboard-cover"]').exists()).toBe(false);
    });

    it('cover image uses the provided URL', () => {
        const wrapper = mount(Dashboard, {
            props: makeProps({ coverImage: 'https://example.com/event-banner.jpg' }),
            global: defaultGlobal,
        });

        const cover = wrapper.find('[data-testid="dashboard-cover"]');
        expect(cover.exists()).toBe(true);
        // The URL should appear in the element's style (as background-image) or as an img src
        const html = cover.html();
        expect(html).toContain('https://example.com/event-banner.jpg');
    });
});
