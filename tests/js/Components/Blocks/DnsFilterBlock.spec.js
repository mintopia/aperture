import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import DnsFilterBlock from '@/Components/Blocks/DnsFilterBlock.vue';

describe('DnsFilterBlock', () => {
    let fetchMock;
    let originalQuerySelector;

    beforeEach(() => {
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal(
            'route',
            vi.fn(() => '/mock/dns-filter/toggle'),
        );

        // Only intercept the CSRF meta tag query, pass through everything else
        originalQuerySelector = document.querySelector.bind(document);
        vi.spyOn(document, 'querySelector').mockImplementation((selector) => {
            if (selector === 'meta[name="csrf-token"]') {
                return { getAttribute: () => 'test-token' };
            }
            return originalQuerySelector(selector);
        });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders the title as an h3 heading', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { title: 'My DNS Filter' },
        });
        const heading = wrapper.find('[data-testid="dns-filter-title"]');
        expect(heading.exists()).toBe(true);
        expect(heading.element.tagName).toBe('H3');
        expect(heading.text()).toBe('My DNS Filter');
    });

    it('renders default title when not provided', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        const heading = wrapper.find('[data-testid="dns-filter-title"]');
        expect(heading.text()).toBe('DNS Ad Blocking');
    });

    it('renders content between title and toggle', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { title: 'Title', content: 'Custom description here' },
        });
        const contentEl = wrapper.find('[data-testid="dns-filter-content"]');
        expect(contentEl.exists()).toBe(true);
        expect(contentEl.text()).toBe('Custom description here');
    });

    it('does not render content section when content is empty', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { title: 'Title', content: '' },
        });
        expect(wrapper.find('[data-testid="dns-filter-content"]').exists()).toBe(false);
    });

    it('renders toggle label from settings.label', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { settings: { label: 'Block Ads' } },
        });
        const label = wrapper.find('[data-testid="dns-filter-label"]');
        expect(label.exists()).toBe(true);
        expect(label.text()).toBe('Block Ads');
    });

    it('renders default toggle label when settings.label is not provided', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        const label = wrapper.find('[data-testid="dns-filter-label"]');
        expect(label.text()).toBe('Enable DNS Filtering');
    });

    it('has a toggle button', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.find('[data-testid="dns-filter-toggle"]').exists()).toBe(true);
    });

    it('calls toggle endpoint on click', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true });

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('dns-filter/toggle'),
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('flips enabled state immediately on click (optimistic update)', async () => {
        let resolvePromise;
        fetchMock.mockReturnValueOnce(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mount(DnsFilterBlock, { props: {} });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');

        // Initially off
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');

        // Trigger click without awaiting — observe optimistic flip
        button.trigger('click');
        await wrapper.vm.$nextTick();

        // Should flip to enabled immediately, before the request resolves
        expect(button.classes()).toContain('bg-[var(--color-accent)]');

        resolvePromise({ ok: true });
        await flushPromises();
    });

    it('keeps enabled state when toggle succeeds', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true });

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await flushPromises();
        await wrapper.vm.$nextTick();

        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('bg-[var(--color-accent)]');
    });

    it('reverts enabled state when toggle response is not ok', async () => {
        let resolvePromise;
        fetchMock.mockReturnValueOnce(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mount(DnsFilterBlock, { props: {} });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');

        // Initially off
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');

        button.trigger('click');
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        // Optimistic: should be on now
        expect(button.classes()).toContain('bg-[var(--color-accent)]');

        resolvePromise({ ok: false });
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Reverted after failure
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');
    });

    it('reverts enabled state on network error', async () => {
        let rejectPromise;
        fetchMock.mockReturnValueOnce(
            new Promise((_resolve, reject) => {
                rejectPromise = reject;
            }),
        );

        const wrapper = mount(DnsFilterBlock, { props: {} });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');

        // Initially off
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');

        button.trigger('click');
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        // Optimistic: should be on
        expect(button.classes()).toContain('bg-[var(--color-accent)]');

        rejectPromise(new Error('Network error'));
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Reverted after error
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');
    });

    it('shows reduced opacity while loading', async () => {
        let resolvePromise;
        fetchMock.mockReturnValueOnce(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mount(DnsFilterBlock, { props: {} });

        wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('opacity-60');

        resolvePromise({ ok: true });
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(button.classes()).not.toContain('opacity-60');
    });

    it('prevents double-click while loading', async () => {
        let resolvePromise;
        fetchMock.mockReturnValueOnce(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mount(DnsFilterBlock, { props: {} });

        wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        // Second click while loading — should be ignored
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await wrapper.vm.$nextTick();

        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolvePromise({ ok: true });
        await flushPromises();
    });

    it('does not have a disabled attribute on the button', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.element.disabled).toBe(false);
    });

    it('renders with data-testid block-dns-filter', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.find('[data-testid="block-dns-filter"]').exists()).toBe(true);
    });

    it('renders title in h3 with correct styling classes', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { title: 'Test Title' },
        });
        const heading = wrapper.find('[data-testid="dns-filter-title"]');
        expect(heading.classes()).toContain('uppercase');
        expect(heading.classes()).toContain('font-heading');
        expect(heading.classes()).toContain('font-bold');
    });

    it('initializes enabled from blockContext.dnsFilteringEnabled true', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: true },
            },
        });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('bg-[var(--color-accent)]');
    });

    it('initializes enabled from blockContext.dnsFilteringEnabled false', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                blockContext: { dnsFilteringEnabled: false },
            },
        });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');
    });

    it('defaults enabled to false when blockContext is empty', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { blockContext: {} },
        });
        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');
    });
});
