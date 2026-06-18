import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { detectIpv6 } from '@/utils/ipv6-detection';

vi.stubGlobal('crypto', { randomUUID: () => 'test-uuid' });

describe('detectIpv6', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns JWT token when endpoint responds', async () => {
        fetch.mockResolvedValue({
            ok: true,
            text: () => Promise.resolve('eyJhbGciOiJSUzI1NiJ9.payload.signature'),
        });

        const result = await detectIpv6('https://{random}.test.example.com');

        expect(fetch).toHaveBeenCalledWith(
            'https://test-uuid.test.example.com',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        expect(result).toBe('eyJhbGciOiJSUzI1NiJ9.payload.signature');
    });

    it('returns null when response is not ok', async () => {
        fetch.mockResolvedValue({ ok: false });

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBeNull();
    });

    it('returns null when response is empty', async () => {
        fetch.mockResolvedValue({
            ok: true,
            text: () => Promise.resolve(''),
        });

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBeNull();
    });

    it('returns null on fetch error', async () => {
        fetch.mockRejectedValue(new Error('Network error'));

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBeNull();
    });

    it('returns null when endpoint is empty', async () => {
        const result = await detectIpv6('');
        expect(result).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('returns null when endpoint is null', async () => {
        const result = await detectIpv6(null);
        expect(result).toBeNull();
    });

    it('trims whitespace from token', async () => {
        fetch.mockResolvedValue({
            ok: true,
            text: () => Promise.resolve('  eyJ.token.here  '),
        });

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBe('eyJ.token.here');
    });
});
