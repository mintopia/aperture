import { describe, it, expect } from 'vitest';
import { renderTemplate } from '@/utils/contentTemplating.js';

describe('renderTemplate', () => {
    const context = {
        currentIp: '192.168.1.42',
        macAddress: 'AA:BB:CC:DD:EE:FF',
        user: { seat: 'A42', team: 'Red' },
    };

    it('replaces {user.seat} with user parameter value', () => {
        expect(renderTemplate('Your seat is {user.seat}', context)).toBe('Your seat is A42');
    });

    it('replaces {user.team} with user parameter value', () => {
        expect(renderTemplate('Team: {user.team}', context)).toBe('Team: Red');
    });

    it('replaces {ip} with current IP', () => {
        expect(renderTemplate('IP: {ip}', context)).toBe('IP: 192.168.1.42');
    });

    it('replaces {mac} with MAC address', () => {
        expect(renderTemplate('MAC: {mac}', context)).toBe('MAC: AA:BB:CC:DD:EE:FF');
    });

    it('replaces multiple placeholders in one string', () => {
        expect(renderTemplate('Seat {user.seat} at {ip}', context)).toBe('Seat A42 at 192.168.1.42');
    });

    it('renders empty string for missing user parameter', () => {
        expect(renderTemplate('Value: {user.missing}', context)).toBe('Value: ');
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
});
