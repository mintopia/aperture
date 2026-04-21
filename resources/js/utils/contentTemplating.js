/**
 * Replace template placeholders in content with values from block context.
 *
 * Supported placeholders:
 *   {user.<key>}  - User parameter value
 *   {ip}          - Current IP address
 *   {mac}         - MAC address
 *
 * @param {string|null} content - Template string with placeholders
 * @param {Object} context - Block context with currentIp, macAddress, user
 * @returns {string} Rendered content
 */
export function renderTemplate(content, context) {
    if (!content) return '';

    return content
        .replace(/\{user\.([^}]+)\}/g, (_, key) => context.user?.[key] ?? '')
        .replace(/\{ip\}/g, context.currentIp ?? '')
        .replace(/\{mac\}/g, context.macAddress ?? '');
}
