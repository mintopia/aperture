import { getCsrfToken } from '@/utils/webauthn.js';

export class HttpError extends Error {
    constructor(status, data) {
        super(data?.message ?? `Request failed with status ${status}`);
        this.status = status;
        this.data = data;
    }
}

export async function requestJson(method, url, body) {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': getCsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) throw new HttpError(response.status, data);
    return data;
}

export const getJson = (url) => requestJson('GET', url);
export const postJson = (url, body = {}) => requestJson('POST', url, body);
export const putJson = (url, body = {}) => requestJson('PUT', url, body);
export const deleteJson = (url) => requestJson('DELETE', url);
