import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('vue', () => ({
    onMounted: vi.fn((cb) => cb()),
    onUnmounted: vi.fn(),
}));

import { useIpv6Detection } from '@/composables/useIpv6Detection.js';
import { onUnmounted } from 'vue';

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
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt-token' }) });
        fetchMock.mockResolvedValueOnce({ ok: true });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        const firstCallUrl = fetchMock.mock.calls[0][0];
        expect(firstCallUrl).toMatch(/https:\/\/[a-f0-9-]+\.ipv6\.example\.com/);

        expect(fetchMock.mock.calls[1][0]).toBe('/ipv6');
        expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    });

    it('submits token from JSON response to /ipv6 endpoint', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'my-jwt' }) });
        fetchMock.mockResolvedValueOnce({ ok: true });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        const postCall = fetchMock.mock.calls[1];
        expect(postCall[0]).toBe('/ipv6');
        const body = JSON.parse(postCall[1].body);
        expect(body.token).toBe('my-jwt');
    });

    it('calls onDetected with ip and internetEnabled from POST response', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt-token' }) });
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ ip: '2001:db8::1', internetEnabled: true }),
        });

        const onDetected = vi.fn();
        useIpv6Detection('https://{uuid}.ipv6.example.com', { onDetected });

        await vi.advanceTimersByTimeAsync(0);

        expect(onDetected).toHaveBeenCalledWith({ ip: '2001:db8::1', internetEnabled: true });
    });

    it('does not call onDetected when detection response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false });

        const onDetected = vi.fn();
        useIpv6Detection('https://{uuid}.ipv6.example.com', { onDetected });

        await vi.advanceTimersByTimeAsync(0);

        expect(onDetected).not.toHaveBeenCalled();
    });

    it('does not call onDetected when POST response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt' }) });
        fetchMock.mockResolvedValueOnce({ ok: false });

        const onDetected = vi.fn();
        useIpv6Detection('https://{uuid}.ipv6.example.com', { onDetected });

        await vi.advanceTimersByTimeAsync(0);

        expect(onDetected).not.toHaveBeenCalled();
    });

    it('works without onDetected callback', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: 'jwt' }) });
        fetchMock.mockResolvedValueOnce({
            ok: true,
            json: () => Promise.resolve({ ip: '::1', internetEnabled: true }),
        });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await expect(vi.advanceTimersByTimeAsync(0)).resolves.not.toThrow();
    });

    it('does not POST when detection response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not POST when token is missing from JSON response', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({}) });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not POST when token is empty string in JSON response', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, json: () => Promise.resolve({ token: '' }) });

        useIpv6Detection('https://{uuid}.ipv6.example.com');

        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('repeats detection at configured interval', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: () => Promise.resolve({ token: 'jwt' }) });

        useIpv6Detection('https://{uuid}.ipv6.example.com', { intervalMs: 5000 });

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
