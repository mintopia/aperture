import { describe, it, expect } from 'vitest';
import { TEMPLATE_VARIABLES, TEMPLATE_VARIABLE_GROUPS } from '@/utils/templateVariables.js';

describe('templateVariables', () => {
    it('exports a non-empty array of variables', () => {
        expect(Array.isArray(TEMPLATE_VARIABLES)).toBe(true);
        expect(TEMPLATE_VARIABLES.length).toBeGreaterThan(0);
    });

    it('each variable has key, label, and group', () => {
        for (const v of TEMPLATE_VARIABLES) {
            expect(v).toHaveProperty('key');
            expect(v).toHaveProperty('label');
            expect(v).toHaveProperty('group');
        }
    });

    it('includes {ipv4}, {ipv6}, {mac}', () => {
        const keys = TEMPLATE_VARIABLES.map((v) => v.key);
        expect(keys).toContain('{ipv4}');
        expect(keys).toContain('{ipv6}');
        expect(keys).toContain('{mac}');
    });

    it('includes {user.name} and {user.params.*}', () => {
        const keys = TEMPLATE_VARIABLES.map((v) => v.key);
        expect(keys).toContain('{user.name}');
        expect(keys).toContain('{user.params.*}');
    });

    it('exports groups as an array of group names', () => {
        expect(Array.isArray(TEMPLATE_VARIABLE_GROUPS)).toBe(true);
        expect(TEMPLATE_VARIABLE_GROUPS).toContain('Connection');
        expect(TEMPLATE_VARIABLE_GROUPS).toContain('User');
        expect(TEMPLATE_VARIABLE_GROUPS).toContain('User Parameters');
    });
});
