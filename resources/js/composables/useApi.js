import { ref } from 'vue';

/**
 * Composable for non-Inertia JSON requests using window.axios.
 *
 * window.axios is pre-configured in bootstrap.js with CSRF headers and
 * base URL — no manual X-CSRF-TOKEN handling needed.
 *
 * @returns {{ loading, error, get, post, put, patch, delete }}
 */
export function useApi() {
    const loading = ref(false);
    const error = ref(null);

    async function request(method, url, data = null, config = {}) {
        loading.value = true;
        error.value = null;
        try {
            const response =
                data !== null ? await window.axios[method](url, data, config) : await window.axios[method](url, config);
            return response.data;
        } catch (err) {
            error.value = err?.response?.data?.message ?? err?.message ?? 'Request failed';
            throw err;
        } finally {
            loading.value = false;
        }
    }

    return {
        loading,
        error,
        get: (url, config = {}) => request('get', url, null, config),
        post: (url, data = {}, config = {}) => request('post', url, data, config),
        put: (url, data = {}, config = {}) => request('put', url, data, config),
        patch: (url, data = {}, config = {}) => request('patch', url, data, config),
        delete: (url, config = {}) => request('delete', url, null, config),
    };
}
