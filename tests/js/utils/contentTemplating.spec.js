import { describe, it, expect } from 'vitest';
import { renderTemplate } from '@/utils/contentTemplating.js';

describe('renderTemplate', () => {
    const context = {
        currentIpv4: '10.0.0.1',
        currentIpv6: 'fe80::1',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: { name: 'Player', params: { seat: 'A42', team: 'Red' } },
    };

    it.each([
        { name: 'user.name property', template: 'Hello {user.name}', expected: 'Hello Player' },
        { name: 'user.params.seat parameter', template: 'Seat: {user.params.seat}', expected: 'Seat: A42' },
        { name: 'user.params.team parameter', template: 'Team: {user.params.team}', expected: 'Team: Red' },
        { name: 'ipv4 address', template: 'IP: {ipv4}', expected: 'IP: 10.0.0.1' },
        { name: 'ipv6 address', template: 'IP: {ipv6}', expected: 'IP: fe80::1' },
        { name: 'mac address', template: 'MAC: {mac}', expected: 'MAC: AA:BB:CC:DD:EE:FF' },
        {
            name: 'multiple placeholders in one string',
            template: 'Seat {user.params.seat} at {ipv4}',
            expected: 'Seat A42 at 10.0.0.1',
        },
        { name: 'missing user parameter -> empty', template: '{user.params.missing}', expected: '' },
        { name: 'missing user property and param -> empty', template: '{user.missing}', expected: '' },
        { name: 'user.seat falls through to user.params.seat', template: '{user.seat}', expected: 'A42' },
        {
            name: 'no placeholders -> original string',
            template: 'No placeholders here',
            expected: 'No placeholders here',
        },
        { name: 'null content -> empty', template: null, expected: '' },
        { name: 'empty string content -> empty', template: '', expected: '' },
    ])('$name', ({ template, expected }) => {
        expect(renderTemplate(template, context)).toBe(expected);
    });

    it.each([
        {
            name: 'user with no params object',
            ctx: { ...context, user: { name: 'Test' } },
            template: '{user.params.seat}',
            expected: '',
        },
        {
            name: 'null MAC address',
            ctx: { ...context, macAddress: null },
            template: 'MAC: {mac}',
            expected: 'MAC: ',
        },
    ])('handles $name', ({ ctx, template, expected }) => {
        expect(renderTemplate(template, ctx)).toBe(expected);
    });
});
