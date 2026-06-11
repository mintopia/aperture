/**
 * Format a byte count into a human-readable string.
 *
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}

/**
 * Format a byte count into separate value and unit components.
 *
 * @param {number} bytes
 * @returns {{ value: string, unit: string }}
 */
export function formatBytesComponents(bytes) {
    if (!bytes || bytes === 0) return { value: '0', unit: 'B' };
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return { value: (bytes / Math.pow(1024, i)).toFixed(1), unit: units[i] };
}

/**
 * Format a DHCP pool address total for display.
 *
 * Totals arrive as exact decimal numeric strings (they can exceed
 * Number.MAX_SAFE_INTEGER, e.g. 2^64 for an IPv6 /64). Totals below one
 * million render with locale grouping; larger totals render in scientific
 * notation with two significant digits (e.g. "1.8e19").
 *
 * @param {string|number} value
 * @returns {string}
 */
export function formatPoolTotal(value) {
    const num = Number(value);
    if (!Number.isFinite(num)) return String(value);
    if (num < 1e6) return num.toLocaleString('en-US');
    return num.toExponential(1).replace('e+', 'e');
}

/**
 * Normalize a MAC address to aa:bb:cc:dd:ee:ff format.
 * Handles colon-separated, hyphen-separated, Cisco dot notation, and bare hex.
 *
 * @param {string} mac
 * @returns {string}
 */
export function normalizeMac(mac) {
    if (!mac) return '—';
    const hex = mac.replace(/[:\-.]/g, '').toLowerCase();
    if (hex.length !== 12 || !/^[0-9a-f]{12}$/.test(hex)) return mac;
    return hex.match(/.{2}/g).join(':');
}
