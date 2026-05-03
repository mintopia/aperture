/**
 * Replace template placeholders in content with values from block context.
 *
 * Supported placeholders:
 *   {user.params.<key>} - User parameter value
 *   {user.<key>}        - User property
 *   {ipv4}              - Client IPv4 address
 *   {ipv6}              - Client IPv6 address
 *   {mac}               - MAC address
 *
 * @param {string|null} content - Template string with placeholders
 * @param {Object} context - Block context with currentIpv4, currentIpv6, macAddress, user
 * @returns {string} Rendered content
 */
export function renderTemplate(content, context) {
    if (!content) return '';

    return content
        .replace(/\{user\.params\.([^}]+)\}/g, (_, key) => context.user?.params?.[key] ?? '')
        .replace(/\{user\.([^}]+)\}/g, (_, key) => context.user?.[key] ?? context.user?.params?.[key] ?? '')
        .replace(/\{ipv4\}/g, context.currentIpv4 ?? '')
        .replace(/\{ipv6\}/g, context.currentIpv6 ?? '')
        .replace(/\{mac\}/g, context.macAddress ?? '');
}
