/**
 * Expand an IPv6 address to its full 8-hextet form and return its value as a
 * 128-bit BigInt, or null if the address is not valid pure IPv6.
 *
 * Handles `::` zero-compression. IPv4 and IPv4-mapped forms (anything
 * containing a dot) are rejected.
 *
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
 * Check whether an IPv6 address falls within a CIDR prefix.
 *
 * Both the address and the prefix network are expanded from any compressed
 * form (`::`), converted to 128-bit values, masked by the prefix length, and
 * compared — so zero-compressed prefixes such as "2001:db8::/64" and
 * non-hextet-aligned lengths such as /63 are handled correctly. Matching is
 * case-insensitive and tolerant of non-canonical forms (e.g. leading zeros).
 *
 * Returns false for anything that cannot be evaluated as a pure-IPv6
 * containment check: null/empty inputs, a prefix without "/len", a length
 * outside 1-128, malformed addresses, and IPv4 or IPv4-mapped inputs.
 *
 * A /0 prefix also returns false: a degenerate "match everything" pool would
 * otherwise show every lease, which is never the intent of pool filtering.
 *
 * @param {string} ip
 * @param {string} prefix
 * @returns {boolean}
 */
export function isIpInPrefix(ip, prefix) {
    if (!ip || !prefix) return false;

    const slash = prefix.lastIndexOf('/');
    if (slash === -1) return false;

    const length = Number(prefix.slice(slash + 1));
    if (!Number.isInteger(length) || length < 1 || length > 128) return false;

    const ipValue = ipv6ToBigInt(ip);
    const networkValue = ipv6ToBigInt(prefix.slice(0, slash));
    if (ipValue === null || networkValue === null) return false;

    const mask = ((1n << BigInt(length)) - 1n) << BigInt(128 - length);
    return (ipValue & mask) === (networkValue & mask);
}
