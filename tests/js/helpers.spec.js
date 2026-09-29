import { describe, it, expect } from 'vitest';
import { formatBytes, formatBytesComponents, formatPoolTotal, normalizeMac } from '@/helpers.js';

describe('formatBytes', () => {
    it.each([
        { name: 'zero', input: 0, expected: '0 B' },
        { name: 'null', input: null, expected: '0 B' },
        { name: 'undefined', input: undefined, expected: '0 B' },
        { name: 'bytes', input: 500, expected: '500.0 B' },
        { name: 'kilobytes', input: 1024, expected: '1.0 KB' },
        { name: 'megabytes', input: 1048576, expected: '1.0 MB' },
        { name: 'gigabytes', input: 1073741824, expected: '1.0 GB' },
        { name: 'terabytes', input: 1099511627776, expected: '1.0 TB' },
        { name: 'fractional values', input: 1536, expected: '1.5 KB' },
        { name: 'large MB values', input: 524288000, expected: '500.0 MB' },
    ])('formats $name -> $expected', ({ input, expected }) => {
        expect(formatBytes(input)).toBe(expected);
    });
});

describe('normalizeMac', () => {
    it.each([
        { name: 'null', input: null, expected: '—' },
        { name: 'undefined', input: undefined, expected: '—' },
        { name: 'empty string', input: '', expected: '—' },
        { name: 'colon-separated MAC', input: 'AA:BB:CC:DD:EE:FF', expected: 'aa:bb:cc:dd:ee:ff' },
        { name: 'hyphen-separated MAC', input: 'AA-BB-CC-DD-EE-FF', expected: 'aa:bb:cc:dd:ee:ff' },
        { name: 'Cisco dot notation MAC', input: 'aabb.ccdd.eeff', expected: 'aa:bb:cc:dd:ee:ff' },
        { name: 'bare hex MAC', input: 'aabbccddeeff', expected: 'aa:bb:cc:dd:ee:ff' },
        { name: 'invalid MAC (wrong length) passthrough', input: 'AA:BB:CC:DD:EE', expected: 'AA:BB:CC:DD:EE' },
        {
            name: 'MAC with non-hex characters passthrough',
            input: 'ZZ:BB:CC:DD:EE:FF',
            expected: 'ZZ:BB:CC:DD:EE:FF',
        },
    ])('$name -> $expected', ({ input, expected }) => {
        expect(normalizeMac(input)).toBe(expected);
    });
});

describe('formatPoolTotal', () => {
    it.each([
        { name: 'string "0"', input: '0', expected: '0' },
        { name: 'small string total (passthrough)', input: '117', expected: '117' },
        { name: 'small numeric total (passthrough)', input: 117, expected: '117' },
        { name: 'locale grouping below one million', input: '999999', expected: '999,999' },
        { name: 'scientific notation for 2^32', input: '4294967296', expected: '4.3e9' },
        { name: 'scientific notation for 2^64', input: '18446744073709551616', expected: '1.8e19' },
    ])('$name -> $expected', ({ input, expected }) => {
        expect(formatPoolTotal(input)).toBe(expected);
    });
});

describe('formatBytesComponents', () => {
    it.each([
        { name: 'zero', input: 0, expected: { value: '0', unit: 'B' } },
        { name: 'null', input: null, expected: { value: '0', unit: 'B' } },
        { name: 'kilobytes', input: 1536, expected: { value: '1.5', unit: 'KB' } },
        { name: 'megabytes', input: 204800000, expected: { value: '195.3', unit: 'MB' } },
        { name: 'gigabytes', input: 1073741824, expected: { value: '1.0', unit: 'GB' } },
    ])('splits $name into value/unit', ({ input, expected }) => {
        expect(formatBytesComponents(input)).toEqual(expected);
    });
});
