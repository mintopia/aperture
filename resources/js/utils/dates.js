/**
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

const relativeFormatter = new Intl.RelativeTimeFormat('en', { numeric: 'always', style: 'short' });

/**
 * @param {string|null} dateString - ISO 8601 or datetime string
 * @returns {string} Relative time or formatted date
 */
export function formatRelative(dateString) {
    if (!dateString) return '';

    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;

    const diffSeconds = Math.floor((Date.now() - date.getTime()) / 1000);

    if (diffSeconds < 60) return 'just now';

    const diffMinutes = Math.floor(diffSeconds / 60);
    if (diffMinutes < 60) return relativeFormatter.format(-diffMinutes, 'minute');

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) return relativeFormatter.format(-diffHours, 'hour');

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return relativeFormatter.format(-diffDays, 'day');

    return formatDate(dateString);
}

export function formatEpochMillis(epochMillis, options = {}) {
    const numeric = Number(epochMillis);
    if (epochMillis === null || epochMillis === undefined || epochMillis === '' || !Number.isFinite(numeric)) return '';

    return formatDate(new Date(numeric).toISOString(), options);
}

export function formatEpochSeconds(epochSeconds, options = {}) {
    if (epochSeconds === null || epochSeconds === undefined || epochSeconds === '') return '';

    return formatEpochMillis(Number(epochSeconds) * 1000, options);
}

export function formatTime(value, options = {}) {
    const date = new Date(value);
    if (isNaN(date.getTime())) return '';

    const defaults = { hour: '2-digit', minute: '2-digit' };
    return new Intl.DateTimeFormat('en-GB', { ...defaults, ...options }).format(date);
}
