/**
 * @param {string} address
 * @returns {bigint|null}
 */
function ipv6ToBigInt(address) {
    const lower = address.toLowerCase();
    if (lower.includes('.')) return null;

    const parts = lower.split('::');
    if (parts.length > 2) return null;

    const splitHextets = (segment) => (segment === '' ? [] : segment.split(':'));

    let hextets;
    if (parts.length === 2) {
        const head = splitHextets(parts[0]);
        const tail = splitHextets(parts[1]);
        const missing = 8 - head.length - tail.length;
        if (missing < 1) return null;
        hextets = [...head, ...Array(missing).fill('0'), ...tail];
    } else {
        hextets = splitHextets(parts[0]);
    }

    if (hextets.length !== 8) return null;

    let value = 0n;
    for (const hextet of hextets) {
        if (!/^[0-9a-f]{1,4}$/.test(hextet)) return null;
        value = (value << 16n) | BigInt(parseInt(hextet, 16));
    }
    return value;
}

/**
 * @param {string} ip
 * @param {string} prefix
 * @returns {boolean}
 */
export function isIpInPrefix(ip, prefix) {
    if (!ip || !prefix) return false;

    const slash = prefix.lastIndexOf('/');
    if (slash === -1) return false;

    const length = Number(prefix.slice(slash + 1));
    if (!Number.isInteger(length) || length < 0 || length > 128) return false;

    const ipValue = ipv6ToBigInt(ip);
    const networkValue = ipv6ToBigInt(prefix.slice(0, slash));
    if (ipValue === null || networkValue === null) return false;

    const mask = ((1n << BigInt(length)) - 1n) << BigInt(128 - length);
    return (ipValue & mask) === (networkValue & mask);
}
