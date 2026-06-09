/**
 * Check whether an IP address falls within a CIDR prefix by comparing the
 * network portion of the prefix against the start of the address.
 * Intended for IPv6 ranges that expose only a prefix (no start/end),
 * e.g. "2001:db8:1::/64" matches "2001:db8:1::5".
 *
 * @param {string} ip
 * @param {string} prefix
 * @returns {boolean}
 */
export function isIpInPrefix(ip, prefix) {
    if (!ip || !prefix) return false;

    const networkPart = prefix.replace(/\/\d+$/, '').replace(/::?$/, '');
    if (!networkPart) return false;

    return ip.toLowerCase().startsWith(networkPart.toLowerCase());
}
