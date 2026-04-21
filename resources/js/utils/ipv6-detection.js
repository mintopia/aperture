export async function detectIpv6(endpoint, timeout = 5000) {
    if (!endpoint) return null;

    const url = endpoint.replace('{random}', crypto.randomUUID());
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch(url, { signal: controller.signal });
        if (!response.ok) return null;

        const token = (await response.text()).trim();

        return token.length > 0 ? token : null;
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
    }
}
