import { describe, it, expect } from 'vitest';
import { formatBytes, formatBytesComponents } from '@/helpers.js';

describe('formatBytes', () => {
    it('returns "0 B" for zero', () => {
        expect(formatBytes(0)).toBe('0 B');
    });

    it('returns "0 B" for null', () => {
        expect(formatBytes(null)).toBe('0 B');
    });

    it('returns "0 B" for undefined', () => {
        expect(formatBytes(undefined)).toBe('0 B');
    });

    it('formats bytes correctly', () => {
        expect(formatBytes(500)).toBe('500.0 B');
    });

    it('formats kilobytes correctly', () => {
        expect(formatBytes(1024)).toBe('1.0 KB');
    });

    it('formats megabytes correctly', () => {
        expect(formatBytes(1048576)).toBe('1.0 MB');
    });

    it('formats gigabytes correctly', () => {
        expect(formatBytes(1073741824)).toBe('1.0 GB');
    });

    it('formats terabytes correctly', () => {
        expect(formatBytes(1099511627776)).toBe('1.0 TB');
    });

    it('formats fractional values', () => {
        expect(formatBytes(1536)).toBe('1.5 KB');
    });

    it('formats large MB values', () => {
        expect(formatBytes(524288000)).toBe('500.0 MB');
    });
});

describe('formatBytesComponents', () => {
    it('returns value "0" and unit "B" for zero', () => {
        expect(formatBytesComponents(0)).toEqual({ value: '0', unit: 'B' });
    });

    it('returns value "0" and unit "B" for null', () => {
        expect(formatBytesComponents(null)).toEqual({ value: '0', unit: 'B' });
    });

    it('splits kilobytes into value and unit', () => {
        expect(formatBytesComponents(1536)).toEqual({ value: '1.5', unit: 'KB' });
    });

    it('splits megabytes into value and unit', () => {
        expect(formatBytesComponents(204800000)).toEqual({ value: '195.3', unit: 'MB' });
    });

    it('splits gigabytes into value and unit', () => {
        expect(formatBytesComponents(1073741824)).toEqual({ value: '1.0', unit: 'GB' });
    });
});
