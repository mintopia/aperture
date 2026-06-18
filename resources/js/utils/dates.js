/**
 * Format a date string to a human-readable format.
 * @param {string|null} dateString - ISO 8601 or datetime string
 * @param {object} options - Intl.DateTimeFormat options override
 * @returns {string} Formatted date like "16 Apr 2026, 12:33"
 */
export function formatDate(dateString, options = {}) {
    if (!dateString) return '';

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    const defaults = {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    };

    return new Intl.DateTimeFormat('en-GB', { ...defaults, ...options }).format(date);
}

/**
 * Format a date as relative time (e.g., "2 hours ago", "3 days ago").
 * Falls back to formatDate() for dates older than 7 days.
 * @param {string|null} dateString - ISO 8601 or datetime string
 * @returns {string} Relative time or formatted date
 */
export function formatRelative(dateString) {
    if (!dateString) return '';

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    const now = new Date();
    const diffMs = now - date;
    const diffSeconds = Math.floor(diffMs / 1000);
    const diffMinutes = Math.floor(diffSeconds / 60);
    const diffHours = Math.floor(diffMinutes / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffSeconds < 60) return 'just now';
    if (diffMinutes < 60) return `${diffMinutes}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;

    return formatDate(dateString);
}

/**
 * Format a date as a compact relative time (e.g., "5m", "2h", "3d").
 * Falls back to an em dash when missing and the original string when invalid.
 * @param {string|null} dateString
 * @returns {string}
 */
export function formatRelativeTime(dateString) {
    if (!dateString) return '—';

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    const now = new Date();
    const diffMs = now - date;
    const diffSeconds = Math.floor(diffMs / 1000);
    const diffMinutes = Math.floor(diffSeconds / 60);
    const diffHours = Math.floor(diffMinutes / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffSeconds < 60) return 'now';
    if (diffMinutes < 60) return `${diffMinutes}m`;
    if (diffHours < 24) return `${diffHours}h`;

    return `${diffDays}d`;
}
