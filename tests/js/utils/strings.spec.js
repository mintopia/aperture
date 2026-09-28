import { describe, it, expect } from 'vitest';
import { kebabToTitle } from '@/utils/strings';

describe('kebabToTitle', () => {
    it.each([
        ['dhcp', 'Dhcp'],
        ['dhcp-leases', 'Dhcp Leases'],
        ['--a--b-', 'A B'],
        ['', ''],
        [null, ''],
        [undefined, ''],
    ])('converts %s', (input, expected) => {
        expect(kebabToTitle(input)).toBe(expected);
    });
});
