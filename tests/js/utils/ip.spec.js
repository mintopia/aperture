import { describe, it, expect } from 'vitest';
import { ipToBigInt, compareIps, ipSortKey, isIpInRange } from '@/utils/ip';

describe('ipToBigInt', () => {
    it.each([
        ['0.0.0.0', 0n],
        ['10.0.0.1', 167772161n],
        ['255.255.255.255', 4294967295n],
        ['::', 0n],
        ['::1', 1n],
        ['2001:db8::2', 0x20010db8000000000000000000000002n],
        ['2001:DB8:0:0:0:0:0:2', 0x20010db8000000000000000000000002n],
        ['::ffff:10.0.0.1', 0xffff0a000001n],
        ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', (1n << 128n) - 1n],
    ])('parses %s', (ip, expected) => {
        expect(ipToBigInt(ip)).toBe(expected);
    });

    it.each([null, undefined, '', 'nope', '1.2.3', '256.1.1.1', '1::2::3', '1:2:3:4:5:6:7', 'g::1', '1.2.3.4.5'])(
        'returns null for %s',
        (ip) => {
            expect(ipToBigInt(ip)).toBeNull();
        },
    );
});

describe('ipSortKey', () => {
    it('maps IPv4 to its IPv4-mapped IPv6 key', () => {
        expect(ipSortKey('1.2.3.4')).toBe(ipSortKey('::ffff:1.2.3.4'));
        expect(ipSortKey('1.2.3.4')).toBe(0xffff01020304n);
    });

    it('orders IPv4 above ::1 and below addresses past ::ffff:255.255.255.255', () => {
        expect(ipSortKey('::1')).toBeLessThan(ipSortKey('0.0.0.0'));
        expect(ipSortKey('255.255.255.255')).toBeLessThan(ipSortKey('::1:0:0:0'));
    });

    it('returns null for invalid input', () => {
        expect(ipSortKey('bad')).toBeNull();
        expect(ipSortKey('')).toBeNull();
    });
});

describe('compareIps', () => {
    it('orders IPv4 numerically, not lexically', () => {
        expect(compareIps('10.0.0.9', '10.0.0.10')).toBe(-1);
        expect(compareIps('10.0.0.10', '10.0.0.9')).toBe(1);
        expect(compareIps('10.0.0.1', '10.0.0.1')).toBe(0);
    });

    it('orders compressed IPv6 numerically', () => {
        expect(compareIps('::1', '2001:db8::2')).toBe(-1);
        expect(compareIps('2001:db8::10', '2001:db8::2')).toBe(1);
        expect(compareIps('2001:db8::1', '2001:0db8:0:0:0:0:0:1')).toBe(0);
    });

    it('orders IPv4 as ::ffff-mapped and invalid last', () => {
        expect(compareIps('255.255.255.255', '::1')).toBe(1);
        expect(compareIps('::1', '1.1.1.1')).toBe(-1);
        expect(compareIps('bad', '1.1.1.1')).toBe(1);
        expect(compareIps('1.1.1.1', 'bad')).toBe(-1);
        expect(compareIps('bad', 'worse')).toBe(0);
    });

    it('sorts a mixed list stably', () => {
        const ips = ['2001:db8::10', '10.0.0.10', '2001:db8::2', '10.0.0.9', '::1'];
        expect([...ips].sort(compareIps)).toEqual(['::1', '10.0.0.9', '10.0.0.10', '2001:db8::2', '2001:db8::10']);
    });
});

describe('isIpInRange', () => {
    it('checks IPv4 ranges inclusively', () => {
        expect(isIpInRange('10.0.0.1', '10.0.0.1', '10.0.0.254')).toBe(true);
        expect(isIpInRange('10.0.0.254', '10.0.0.1', '10.0.0.254')).toBe(true);
        expect(isIpInRange('10.0.1.0', '10.0.0.1', '10.0.0.254')).toBe(false);
        expect(isIpInRange('10.0.0.100', '10.0.0.9', '10.0.0.200')).toBe(true);
    });

    it('checks compressed IPv6 ranges numerically', () => {
        expect(isIpInRange('2001:db8::10', '2001:db8::2', '2001:db8::ff')).toBe(true);
        expect(isIpInRange('2001:db8::100', '2001:db8::2', '2001:db8::ff')).toBe(false);
        expect(isIpInRange('2001:db8:0:0:0:0:0:5', '2001:db8::2', '2001:db8::ff')).toBe(true);
    });

    it('rejects invalid, empty and mixed-family input', () => {
        expect(isIpInRange('', '10.0.0.1', '10.0.0.2')).toBe(false);
        expect(isIpInRange('bad', '10.0.0.1', '10.0.0.2')).toBe(false);
        expect(isIpInRange('::1', '0.0.0.0', '255.255.255.255')).toBe(false);
    });
});
