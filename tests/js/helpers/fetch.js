export function jsonResponse(data, { ok = true, status = 200 } = {}) {
    return { ok, status, json: async () => data };
}
