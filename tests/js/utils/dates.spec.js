import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { formatDate, formatRelative } from '@/utils/dates';

describe('formatDate', () => {
    it('formats ISO date to human readable', () => {
        const result = formatDate('2026-04-16T12:33:29+00:00');
        expect(result).toMatch(/16/);
        expect(result).toMatch(/Apr/);
        expect(result).toMatch(/2026/);
    });

    it('formats datetime string', () => {
        const result = formatDate('2026-04-16 12:33:29');
        expect(result).toMatch(/16/);
        expect(result).toMatch(/Apr/);
    });

    it('returns empty string for null', () => {
        expect(formatDate(null)).toBe('');
    });

    it('returns empty string for undefined', () => {
        expect(formatDate(undefined)).toBe('');
    });

    it('returns empty string for empty string', () => {
        expect(formatDate('')).toBe('');
    });

    it('returns original string for invalid date', () => {
        expect(formatDate('not-a-date')).toBe('not-a-date');
    });

    it('accepts custom options', () => {
        const result = formatDate('2026-04-16T12:33:29Z', { month: 'long' });
        expect(result).toMatch(/April/);
    });
});

describe('formatRelative', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-16T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('returns "just now" for recent dates', () => {
        expect(formatRelative('2026-04-16T11:59:30Z')).toBe('just now');
    });

    it('returns minutes ago', () => {
        expect(formatRelative('2026-04-16T11:45:00Z')).toBe('15m ago');
    });

    it('returns hours ago', () => {
        expect(formatRelative('2026-04-16T09:00:00Z')).toBe('3h ago');
    });

    it('returns days ago', () => {
        expect(formatRelative('2026-04-14T12:00:00Z')).toBe('2d ago');
    });

    it('falls back to formatted date for old dates', () => {
        const result = formatRelative('2026-01-01T12:00:00Z');
        expect(result).toMatch(/1/);
        expect(result).toMatch(/Jan/);
        expect(result).toMatch(/2026/);
    });

    it('returns empty string for null', () => {
        expect(formatRelative(null)).toBe('');
    });

    it('returns empty string for undefined', () => {
        expect(formatRelative(undefined)).toBe('');
    });

    it('returns empty string for empty string', () => {
        expect(formatRelative('')).toBe('');
    });

    it('returns original string for invalid date', () => {
        expect(formatRelative('not-a-date')).toBe('not-a-date');
    });
});
