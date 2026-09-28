import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { formatDate, formatRelative, formatRelativeTime } from '@/utils/dates';

describe('formatDate', () => {
    it.each([
        {
            name: 'ISO date to human readable',
            input: '2026-04-16T12:33:29+00:00',
            options: undefined,
            contains: ['16', 'Apr', '2026'],
        },
        { name: 'datetime string', input: '2026-04-16 12:33:29', options: undefined, contains: ['16', 'Apr'] },
        {
            name: 'custom options (long month)',
            input: '2026-04-16T12:33:29Z',
            options: { month: 'long' },
            contains: ['April'],
        },
    ])('formats $name', ({ input, options, contains }) => {
        const result = formatDate(input, options);
        contains.forEach((substr) => expect(result).toMatch(new RegExp(substr)));
    });

    it.each([
        { name: 'null', input: null, expected: '' },
        { name: 'undefined', input: undefined, expected: '' },
        { name: 'empty string', input: '', expected: '' },
        { name: 'invalid date (passthrough)', input: 'not-a-date', expected: 'not-a-date' },
    ])('returns $expected for $name', ({ input, expected }) => {
        expect(formatDate(input)).toBe(expected);
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

    it.each([
        { name: 'just now (< 60s)', input: '2026-04-16T11:59:30Z', expected: 'just now' },
        { name: 'minutes ago', input: '2026-04-16T11:45:00Z', expected: '15m ago' },
        { name: 'hours ago', input: '2026-04-16T09:00:00Z', expected: '3h ago' },
        { name: 'days ago', input: '2026-04-14T12:00:00Z', expected: '2d ago' },
    ])('returns $expected for $name', ({ input, expected }) => {
        expect(formatRelative(input)).toBe(expected);
    });

    it('falls back to formatted date for old dates', () => {
        const result = formatRelative('2026-01-01T12:00:00Z');
        expect(result).toMatch(/1/);
        expect(result).toMatch(/Jan/);
        expect(result).toMatch(/2026/);
    });

    it.each([
        { name: 'null', input: null, expected: '' },
        { name: 'undefined', input: undefined, expected: '' },
        { name: 'empty string', input: '', expected: '' },
        { name: 'invalid date (passthrough)', input: 'not-a-date', expected: 'not-a-date' },
    ])('returns $expected for $name', ({ input, expected }) => {
        expect(formatRelative(input)).toBe(expected);
    });
});

describe('formatRelativeTime', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-04-16T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it.each([
        { name: 'null', input: null, expected: '—' },
        { name: 'undefined', input: undefined, expected: '—' },
        { name: 'empty string', input: '', expected: '—' },
        { name: 'invalid date (passthrough)', input: 'not-a-date', expected: 'not-a-date' },
        { name: 'very recent (< 60s)', input: '2026-04-16T11:59:30Z', expected: 'now' },
        { name: 'minutes under an hour', input: '2026-04-16T11:45:00Z', expected: '15m' },
        { name: 'hours under a day', input: '2026-04-16T09:00:00Z', expected: '3h' },
        { name: 'days older than a day', input: '2026-04-14T12:00:00Z', expected: '2d' },
    ])('returns $expected for $name', ({ input, expected }) => {
        expect(formatRelativeTime(input)).toBe(expected);
    });
});
