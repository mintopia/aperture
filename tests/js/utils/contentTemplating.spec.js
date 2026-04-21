import { describe, it, expect } from 'vitest';
import { renderTemplate } from '@/utils/contentTemplating.js';

describe('renderTemplate', () => {
    const context = {
        currentIpv4: '10.0.0.1',
        currentIpv6: 'fe80::1',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: { name: 'Player', params: { seat: 'A42', team: 'Red' } },
    };

    it('replaces {user.name} with user property', () => {
        expect(renderTemplate('Hello {user.name}', context)).toBe('Hello Player');
    });

    it('replaces {user.params.seat} with user parameter', () => {
        expect(renderTemplate('Seat: {user.params.seat}', context)).toBe('Seat: A42');
    });

    it('replaces {user.params.team} with user parameter', () => {
        expect(renderTemplate('Team: {user.params.team}', context)).toBe('Team: Red');
    });

    it('replaces {ipv4} with IPv4 address', () => {
        expect(renderTemplate('IP: {ipv4}', context)).toBe('IP: 10.0.0.1');
    });

    it('replaces {ipv6} with IPv6 address', () => {
        expect(renderTemplate('IP: {ipv6}', context)).toBe('IP: fe80::1');
    });

    it('replaces {mac} with MAC address', () => {
        expect(renderTemplate('MAC: {mac}', context)).toBe('MAC: AA:BB:CC:DD:EE:FF');
    });

    it('replaces multiple placeholders in one string', () => {
        expect(renderTemplate('Seat {user.params.seat} at {ipv4}', context)).toBe('Seat A42 at 10.0.0.1');
    });

    it('renders empty string for missing user parameter', () => {
        expect(renderTemplate('{user.params.missing}', context)).toBe('');
    });

    it('handles user with no params object', () => {
        const ctx = { ...context, user: { name: 'Test' } };
        expect(renderTemplate('{user.params.seat}', ctx)).toBe('');
    });

    it('renders empty string for missing user property', () => {
        expect(renderTemplate('{user.missing}', context)).toBe('');
    });

    it('renders empty string for null MAC', () => {
        const ctx = { ...context, macAddress: null };
        expect(renderTemplate('MAC: {mac}', ctx)).toBe('MAC: ');
    });

    it('returns original string when no placeholders', () => {
        expect(renderTemplate('No placeholders here', context)).toBe('No placeholders here');
    });

    it('handles null content gracefully', () => {
        expect(renderTemplate(null, context)).toBe('');
    });

    it('handles empty string content', () => {
        expect(renderTemplate('', context)).toBe('');
    });

    it('user.params takes priority over user property for nested keys', () => {
        // {user.params.seat} should resolve from params, not try user.params as a property
        expect(renderTemplate('{user.params.seat}', context)).toBe('A42');
    });
});
