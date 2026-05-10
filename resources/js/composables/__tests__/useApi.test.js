import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { useApi } from '../useApi.js';

describe('useApi', () => {
    beforeEach(() => {
        window.axios = {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
            delete: vi.fn(),
        };
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('get() delegates to axios.get with correct URL', async () => {
        window.axios.get.mockResolvedValue({ data: { ok: true } });
        const { get } = useApi();
        const result = await get('/api/test');
        expect(window.axios.get).toHaveBeenCalledWith('/api/test', expect.any(Object));
        expect(result).toEqual({ ok: true });
    });

    it('post() delegates to axios.post with data', async () => {
        window.axios.post.mockResolvedValue({ data: { id: 1 } });
        const { post } = useApi();
        const result = await post('/api/items', { name: 'Test' });
        expect(window.axios.post).toHaveBeenCalledWith('/api/items', { name: 'Test' }, expect.any(Object));
        expect(result).toEqual({ id: 1 });
    });

    it('exposes loading and error state', async () => {
        let resolve;
        window.axios.get.mockReturnValue(new Promise((r) => (resolve = r)));
        const { get, loading, error } = useApi();

        expect(loading.value).toBe(false);
        const promise = get('/api/test');
        expect(loading.value).toBe(true);
        resolve({ data: { ok: true } });
        await promise;
        expect(loading.value).toBe(false);
        expect(error.value).toBeNull();
    });

    it('put() delegates to axios.put with data', async () => {
        window.axios.put.mockResolvedValue({ data: { updated: true } });
        const { put } = useApi();
        const result = await put('/api/items/1', { name: 'Updated' });
        expect(window.axios.put).toHaveBeenCalledWith('/api/items/1', { name: 'Updated' }, expect.any(Object));
        expect(result).toEqual({ updated: true });
    });

    it('patch() delegates to axios.patch with data', async () => {
        window.axios.patch.mockResolvedValue({ data: { patched: true } });
        const { patch } = useApi();
        const result = await patch('/api/items/1', { status: 'active' });
        expect(window.axios.patch).toHaveBeenCalledWith('/api/items/1', { status: 'active' }, expect.any(Object));
        expect(result).toEqual({ patched: true });
    });

    it('delete() delegates to axios.delete with correct URL', async () => {
        window.axios.delete.mockResolvedValue({ data: { deleted: true } });
        const { delete: del } = useApi();
        const result = await del('/api/items/1');
        expect(window.axios.delete).toHaveBeenCalledWith('/api/items/1', expect.any(Object));
        expect(result).toEqual({ deleted: true });
    });

    it('sets error.value on axios failure', async () => {
        const axiosError = { response: { data: { message: 'Not found' } } };
        window.axios.get.mockRejectedValue(axiosError);
        const { get, error } = useApi();

        await expect(get('/api/missing')).rejects.toEqual(axiosError);
        expect(error.value).toBe('Not found');
    });

    it('sets error.value from err.message when no response', async () => {
        const networkError = new Error('Network error');
        window.axios.get.mockRejectedValue(networkError);
        const { get, error } = useApi();

        await expect(get('/api/test')).rejects.toThrow('Network error');
        expect(error.value).toBe('Network error');
    });

    it('resets error to null on successful request', async () => {
        window.axios.get.mockRejectedValueOnce(new Error('fail'));
        window.axios.get.mockResolvedValueOnce({ data: { ok: true } });
        const { get, error } = useApi();

        await expect(get('/api/test')).rejects.toThrow();
        expect(error.value).toBe('fail');

        await get('/api/test');
        expect(error.value).toBeNull();
    });
});
