import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Dashboard from '../Dashboard.vue';

const mockChannel = {
    listen: vi.fn().mockReturnThis(),
    stopListening: vi.fn().mockReturnThis(),
};

const mockEcho = {
    private: vi.fn().mockReturnValue(mockChannel),
    leave: vi.fn(),
};

const routeMock = vi.fn(() => '#');

const defaultProps = {
    blocks: [],
    blockContext: {
        currentIpv4: '10.0.0.1',
        internetEnabled: true,
        dnsFilteringEnabled: false,
        macAddress: 'AA:BB:CC:DD:EE:FF',
    },
    dnsDetection: null,
};

const globalConfig = {
    stubs: ['PortalLayout', 'BlockGrid', 'DnsWarningBlock'],
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            auth: { user: { id: 42, nickname: 'TestUser' } },
        },
    }),
    router: {
        reload: vi.fn(),
    },
}));

describe('Portal/Dashboard', () => {
    beforeEach(() => {
        window.Echo = mockEcho;
        mockEcho.private.mockClear();
        mockEcho.leave.mockClear();
        mockChannel.listen.mockClear().mockReturnThis();
    });

    afterEach(() => {
        delete window.Echo;
    });

    it('renders the welcome heading', () => {
        const wrapper = mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });
        expect(wrapper.find('[data-testid="page-title"]').text()).toContain('Welcome');
    });

    it('subscribes to user Echo channel on mount', () => {
        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(mockEcho.private).toHaveBeenCalledWith('user.42');
    });

    it('listens for InternetAccessChanged events', () => {
        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(mockChannel.listen).toHaveBeenCalledWith('.InternetAccessChanged', expect.any(Function));
    });

    it('listens for DnsFilterChanged events', () => {
        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(mockChannel.listen).toHaveBeenCalledWith('.DnsFilterChanged', expect.any(Function));
    });

    it('listens for UserBlocked events', () => {
        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(mockChannel.listen).toHaveBeenCalledWith('.UserBlocked', expect.any(Function));
    });

    it('listens for RateLimitChanged events', () => {
        mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        expect(mockChannel.listen).toHaveBeenCalledWith('.RateLimitChanged', expect.any(Function));
    });

    it('leaves the channel on unmount', () => {
        const wrapper = mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        wrapper.unmount();

        expect(mockEcho.leave).toHaveBeenCalledWith('user.42');
    });

    it('does not subscribe when Echo is unavailable', () => {
        delete window.Echo;

        const wrapper = mount(Dashboard, {
            props: defaultProps,
            global: globalConfig,
        });

        // Should not throw
        expect(wrapper.find('[data-testid="page-title"]').exists()).toBe(true);
    });
});
