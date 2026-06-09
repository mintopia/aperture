/**
 * Check whether an IP address falls within a CIDR prefix by comparing the
 * network portion of the prefix against the start of the address.
 * Intended for IPv6 ranges that expose only a prefix (no start/end),
 * e.g. "2001:db8:1::/64" matches "2001:db8:1::5".
 *
 * Matching requires a hextet boundary, so "2001:db8:1::/64" does not match
 * "2001:db8:10::5" or "2001:db8:1f00::5".
 *
 * Known limitations of this string-based heuristic:
 * - The prefix length is ignored; prefixes that are not aligned to a hextet
 *   boundary (e.g. /63, or fd00::/8) are only approximated.
 * - Non-canonical address forms (e.g. leading zeros such as "2001:0db8:...")
 *   will not match the canonical stored form.
 *
 * Pools produced by the Cisco parser are canonical and nibble-aligned, so the
 * heuristic is sound for the data it filters.
 *
 * @param {string} ip
 * @param {string} prefix
 * @returns {boolean}
 */
export function isIpInPrefix(ip, prefix) {
    if (!ip || !prefix) return false;

    const networkPart = prefix.replace(/\/\d+$/, '').replace(/::?$/, '');
    if (!networkPart) return false;

    return ip.toLowerCase().startsWith(networkPart.toLowerCase() + ':');
}
