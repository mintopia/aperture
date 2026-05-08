import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('vue', () => ({
    onMounted: vi.fn((cb) => cb()),
    onUnmounted: vi.fn(),
}));

import { useIpv6Detection } from '@/composables/useIpv6Detection.js';
import { onMounted, onUnmounted } from 'vue';

describe('useIpv6Detection', () => {
    let fetchMock;

    beforeEach(() => {
        vi.useFakeTimers();
        fetchMock = vi.fn();
        global.fetch = fetchMock;
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('does nothing when endpoint is empty', () => {
        useIpv6Detection('');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does nothing when endpoint is null', () => {
        useIpv6Detection(null);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fetches IPv6 endpoint immediately on mount', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, text: () => Promise.resolve('jwt-token') });
        fetchMock.mockResolvedValueOnce({ ok: true });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        const firstCallUrl = fetchMock.mock.calls[0][0];
        expect(firstCallUrl).toMatch(/https:\/\/[a-f0-9-]+\.ipv6\.example\.com/);

        expect(fetchMock.mock.calls[1][0]).toBe('/ipv6');
        expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    });

    it('submits token to /ipv6 endpoint', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, text: () => Promise.resolve('my-jwt') });
        fetchMock.mockResolvedValueOnce({ ok: true });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        const postCall = fetchMock.mock.calls[1];
        expect(postCall[0]).toBe('/ipv6');
        const body = JSON.parse(postCall[1].body);
        expect(body.token).toBe('my-jwt');
    });

    it('does not POST when detection response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not POST when token is empty', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, text: () => Promise.resolve('') });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('repeats detection at configured interval', async () => {
        fetchMock.mockResolvedValue({ ok: true, text: () => Promise.resolve('jwt') });

        useIpv6Detection('https://{uuid}.ipv6.example.com', 5000);

        await vi.advanceTimersByTimeAsync(0);
        fetchMock.mockClear();

        await vi.advanceTimersByTimeAsync(5000);

        expect(fetchMock).toHaveBeenCalled();
    });

    it('registers cleanup on unmount', () => {
        fetchMock.mockResolvedValue({ ok: false });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        expect(onUnmounted).toHaveBeenCalled();
    });

    it('silently handles fetch errors', async () => {
        fetchMock.mockRejectedValueOnce(new Error('network error'));

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await expect(vi.advanceTimersByTimeAsync(0)).resolves.not.toThrow();
    });
});
