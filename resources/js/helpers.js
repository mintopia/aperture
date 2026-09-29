/**
 * @param {number} bytes
 * @returns {string}
 */
export function formatBytes(bytes) {
    const { value, unit } = formatBytesComponents(bytes);
    return `${value} ${unit}`;
}

/**
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
 * @param {string} mac
 * @returns {string}
 */
export function normalizeMac(mac) {
    if (!mac) return '—';
    const hex = mac.replace(/[:\-.]/g, '').toLowerCase();
    if (hex.length !== 12 || !/^[0-9a-f]{12}$/.test(hex)) return mac;
    return hex.match(/.{2}/g).join(':');
}
