const V4_MAPPED_PREFIX = 0xffffn << 32n;

function ipv4ToBigInt(address) {
    const octets = address.split('.');
    if (octets.length !== 4) return null;

    let value = 0n;
    for (const octet of octets) {
        if (!/^\d{1,3}$/.test(octet) || Number(octet) > 255) return null;
        value = (value << 8n) | BigInt(octet);
    }
    return value;
}

function ipv6ToBigInt(address) {
    let text = address.toLowerCase();

    if (text.includes('.')) {
        const lastColon = text.lastIndexOf(':');
        const v4 = ipv4ToBigInt(text.slice(lastColon + 1));
        if (lastColon === -1 || v4 === null) return null;
        const hi = (v4 >> 16n).toString(16);
        const lo = (v4 & 0xffffn).toString(16);
        text = `${text.slice(0, lastColon + 1)}${hi}:${lo}`;
    }

    const parts = text.split('::');
    if (parts.length > 2) return null;

    const split = (segment) => (segment === '' ? [] : segment.split(':'));
    let hextets;
    if (parts.length === 2) {
        const head = split(parts[0]);
        const tail = split(parts[1]);
        const missing = 8 - head.length - tail.length;
        if (missing < 1) return null;
        hextets = [...head, ...Array(missing).fill('0'), ...tail];
    } else {
        hextets = split(parts[0]);
    }
    if (hextets.length !== 8) return null;

    let value = 0n;
    for (const hextet of hextets) {
        if (!/^[0-9a-f]{1,4}$/.test(hextet)) return null;
        value = (value << 16n) | BigInt(parseInt(hextet, 16));
    }
    return value;
}

export function ipToBigInt(ip) {
    if (typeof ip !== 'string' || ip === '') return null;
    const trimmed = ip.trim();
    return trimmed.includes(':') ? ipv6ToBigInt(trimmed) : ipv4ToBigInt(trimmed);
}

// Mirrors IpAddress::sortKey (IPv4 sorts as IPv4-mapped IPv6).
export function ipSortKey(ip) {
    const value = ipToBigInt(ip);
    if (value === null) return null;
    return ip.includes(':') ? value : value | V4_MAPPED_PREFIX;
}

export function compareIps(a, b) {
    const aKey = ipSortKey(a);
    const bKey = ipSortKey(b);
    if (aKey === null || bKey === null) {
        if (aKey === bKey) return 0;
        return aKey === null ? 1 : -1;
    }

    if (aKey === bKey) return 0;
    return aKey < bKey ? -1 : 1;
}

export function isIpInRange(ip, start, end) {
    const value = ipToBigInt(ip);
    const low = ipToBigInt(start);
    const high = ipToBigInt(end);
    if (value === null || low === null || high === null) return false;

    const v6 = ip.includes(':');
    if (v6 !== start.includes(':') || v6 !== end.includes(':')) return false;

    return value >= low && value <= high;
}
