/**
 * Detect IPv6 address via external endpoint.
 * @param {string} endpoint - URL with optional {random} placeholder
 * @param {number} timeout - Timeout in ms (default 5000)
 * @returns {Promise<string|null>} IPv6 address or null
 */
export async function detectIpv6(endpoint, timeout = 5000) {
    if (!endpoint) return null;

    const url = endpoint.replace('{random}', crypto.randomUUID());
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch(url, { signal: controller.signal });
        if (!response.ok) return null;

        const data = await response.json();
        const ip = (data?.ip || '').trim();

        // IPv6 addresses contain colons
        return ip.includes(':') ? ip : null;
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
    }
}
