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
