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

    it('renders the title from props', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { title: 'My DNS Filter' },
        });
        expect(wrapper.text()).toContain('My DNS Filter');
    });

    it('renders default title when not provided', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.text()).toContain('DNS Ad Blocking');
    });

    it('renders the content/description from props', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: { content: 'Custom description here' },
        });
        expect(wrapper.text()).toContain('Custom description here');
    });

    it('has a toggle button', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.find('[data-testid="dns-filter-toggle"]').exists()).toBe(true);
    });

    it('calls toggle endpoint on click', async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ enabled: true }),
        });

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            expect.stringContaining('dns-filter/toggle'),
            expect.objectContaining({ method: 'POST' }),
        );
    });

    it('updates enabled state after successful toggle', async () => {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ enabled: true }),
        });

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await flushPromises();
        await wrapper.vm.$nextTick();

        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('bg-[var(--color-accent)]');
    });

    it('does not update state when toggle fails', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false });

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await flushPromises();

        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.classes()).toContain('bg-[var(--color-surface-alt)]');
    });

    it('disables button while loading', async () => {
        let resolvePromise;
        fetchMock.mockReturnValueOnce(
            new Promise((resolve) => {
                resolvePromise = resolve;
            }),
        );

        const wrapper = mount(DnsFilterBlock, { props: {} });

        // Trigger click without awaiting so we can observe the loading state
        wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        // Allow microtask for the sync part of toggle() to set loading=true
        await new Promise((r) => setTimeout(r, 0));
        await wrapper.vm.$nextTick();

        const button = wrapper.find('[data-testid="dns-filter-toggle"]');
        expect(button.element.disabled).toBe(true);

        resolvePromise({ ok: true, json: () => Promise.resolve({ enabled: true }) });
        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="dns-filter-toggle"]').element.disabled).toBe(false);
    });

    it('handles network errors gracefully', async () => {
        fetchMock.mockRejectedValueOnce(new Error('Network error'));

        const wrapper = mount(DnsFilterBlock, { props: {} });
        await wrapper.find('[data-testid="dns-filter-toggle"]').trigger('click');
        await flushPromises();

        // Should not throw, button should be re-enabled
        expect(wrapper.find('[data-testid="dns-filter-toggle"]').element.disabled).toBe(false);
    });

    it('renders with data-testid block-dns-filter', () => {
        const wrapper = mount(DnsFilterBlock, { props: {} });
        expect(wrapper.find('[data-testid="block-dns-filter"]').exists()).toBe(true);
    });

    it('uses settings.title when available', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'Prop Title',
                content: 'Prop Content',
                settings: { title: 'Settings Title', description: 'Settings Desc' },
            },
        });
        expect(wrapper.text()).toContain('Settings Title');
    });

    it('uses settings.description when available', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'Prop Title',
                content: 'Prop Content',
                settings: { title: 'T', description: 'Custom description text' },
            },
        });
        expect(wrapper.text()).toContain('Custom description text');
    });

    it('falls back to title prop when settings.title is empty', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'Fallback Title',
                content: 'Fallback Content',
                settings: {},
            },
        });
        expect(wrapper.text()).toContain('Fallback Title');
    });

    it('falls back to content prop when settings.description is empty', () => {
        const wrapper = mount(DnsFilterBlock, {
            props: {
                title: 'T',
                content: 'Fallback description',
                settings: {},
            },
        });
        expect(wrapper.text()).toContain('Fallback description');
    });
});
