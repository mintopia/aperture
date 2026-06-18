import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount } from '@vue/test-utils';
import DnsFilterBlock from '../DnsFilterBlock.vue';

const routeMock = vi.fn(() => '/api/portal/dns-filter/toggle');

const globalConfig = {
    config: {
        globalProperties: {
            route: routeMock,
        },
    },
};

describe('DnsFilterBlock', () => {
    beforeEach(() => {
        global.fetch = vi.fn().mockResolvedValue({ ok: true });
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the dns filter block', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: false },
            },
            global: globalConfig,
        });

        expect(wrapper.find('[data-testid="block-dns-filter"]').exists()).toBe(true);
    });

    it('shows enabled state when dnsFilteringEnabled is true', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: true },
            },
            global: globalConfig,
        });

        const toggle = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(toggle.classes()).toContain('bg-[var(--color-accent)]');
    });

    it('shows disabled state when dnsFilteringEnabled is false', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: false },
            },
            global: globalConfig,
        });

        const toggle = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(toggle.classes()).toContain('bg-[var(--color-surface-alt)]');
    });

    it('exposes updateDnsFilter method', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: false },
            },
            global: globalConfig,
        });

        expect(wrapper.vm.updateDnsFilter).toBeInstanceOf(Function);
    });

    it('updates toggle state when updateDnsFilter is called', async () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: false },
            },
            global: globalConfig,
        });

        wrapper.vm.updateDnsFilter(true);
        await wrapper.vm.$nextTick();

        const toggle = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(toggle.classes()).toContain('bg-[var(--color-accent)]');
    });
});
