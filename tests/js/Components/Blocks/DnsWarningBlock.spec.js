import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';

describe('DnsWarningBlock', () => {
    let fetchMock;

    beforeEach(() => {
        vi.useFakeTimers();
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: vi.fn(() => 'test-uuid-1234') });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    function mountBlock(props = {}) {
        return mount(DnsWarningBlock, { props });
    }

    function mockFetchResponse(server) {
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ server }),
        });
    }

    function mockFetchError() {
        fetchMock.mockRejectedValueOnce(new Error('Network error'));
    }

    it('renders nothing when no checkUrl provided', () => {
        const wrapper = mountBlock({});
        expect(wrapper.text()).toBe('');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('shows warning when fetch returns server "online"', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Fix your DNS!');
    });

    it('hides warning when fetch returns server "event"', async () => {
        mockFetchResponse('event');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('treats fetch errors as pass (no warning)', async () => {
        mockFetchError();

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('replaces {uuid} in URL with a random UUID', async () => {
        mockFetchResponse('event');

        mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(fetchMock).toHaveBeenCalledWith('https://test-uuid-1234.example.com');
    });

    it('retries every 60 seconds after failure', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(true);

        // Advance 60 seconds, trigger retry
        mockFetchResponse('event');
        vi.advanceTimersByTime(60000);
        await flushPromises();

        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('manual refresh triggers re-check', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        mockFetchResponse('event');
        await wrapper.find('[data-testid="dns-refresh"]').trigger('click');
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(wrapper.find('[data-testid="block-dns-warning"]').exists()).toBe(false);
    });

    it('clears interval on unmount', async () => {
        mockFetchResponse('online');

        const wrapper = mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        const clearIntervalSpy = vi.spyOn(global, 'clearInterval');
        wrapper.unmount();
        expect(clearIntervalSpy).toHaveBeenCalled();
    });

    it('stops retrying after pass', async () => {
        mockFetchResponse('event');

        mountBlock({
            checkUrl: 'https://{uuid}.example.com',
            warningMessage: 'Fix your DNS!',
        });

        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // Advancing time should NOT trigger another fetch
        vi.advanceTimersByTime(120000);
        await flushPromises();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
