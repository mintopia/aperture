import { describe, it, expect } from 'vitest';
import { typeLabel, statusLabel, formatPortStatus, formatSpeed, formatDuplex, formatVlan } from '@/utils/switches';

describe('typeLabel', () => {
    it('returns label for known types', () => {
        expect(typeLabel('cisco')).toBe('Cisco IOS');
        expect(typeLabel('cisco_ios')).toBe('Cisco IOS');
        expect(typeLabel('cisco_nxos')).toBe('Cisco NX-OS');
        expect(typeLabel('arista_eos')).toBe('Arista EOS');
        expect(typeLabel('juniper_junos')).toBe('Juniper JunOS');
        expect(typeLabel('snmp')).toBe('SNMP');
    });

    it('returns raw value for unknown types', () => {
        expect(typeLabel('unknown')).toBe('unknown');
    });
});

describe('statusLabel', () => {
    it('returns "Connected" for connected', () => {
        expect(statusLabel('connected')).toBe('Connected');
    });

    it('returns "Not Connected" for notconnect', () => {
        expect(statusLabel('notconnect')).toBe('Not Connected');
    });

    it('returns "Error Disabled" for err-disabled', () => {
        expect(statusLabel('err-disabled')).toBe('Error Disabled');
    });

    it('returns "Up" for up', () => {
        expect(statusLabel('up')).toBe('Up');
    });

    it('returns "Down" for down', () => {
        expect(statusLabel('down')).toBe('Down');
    });

    it('returns "Disabled" for disabled', () => {
        expect(statusLabel('disabled')).toBe('Disabled');
    });

    it('capitalizes unknown status values', () => {
        expect(statusLabel('monitoring')).toBe('Monitoring');
    });

    it('capitalizes single character status', () => {
        expect(statusLabel('x')).toBe('X');
    });

    it('returns dash for null', () => {
        expect(statusLabel(null)).toBe('—');
    });

    it('returns dash for undefined', () => {
        expect(statusLabel(undefined)).toBe('—');
    });

    it('returns dash for empty string', () => {
        expect(statusLabel('')).toBe('—');
    });
});

describe('formatPortStatus', () => {
    it('formats up/connected as "Up / Connected"', () => {
        expect(formatPortStatus('up', 'connected')).toBe('Up / Connected');
    });

    it('formats down/notconnect as "Down / Not Connected"', () => {
        expect(formatPortStatus('down', 'notconnect')).toBe('Down / Not Connected');
    });

    it('formats up/err-disabled as "Up / Error Disabled"', () => {
        expect(formatPortStatus('up', 'err-disabled')).toBe('Up / Error Disabled');
    });

    it('returns "Admin Down" when admin is down and oper is missing', () => {
        expect(formatPortStatus('down', null)).toBe('Admin Down');
        expect(formatPortStatus('down', undefined)).toBe('Admin Down');
        expect(formatPortStatus('down', '')).toBe('Admin Down');
    });

    it('formats down/down as "Down / Down"', () => {
        expect(formatPortStatus('down', 'down')).toBe('Down / Down');
    });

    it('returns dash when both are missing', () => {
        expect(formatPortStatus(null, null)).toBe('—');
        expect(formatPortStatus(undefined, undefined)).toBe('—');
        expect(formatPortStatus('', '')).toBe('—');
    });

    it('handles only oper status provided', () => {
        expect(formatPortStatus(null, 'connected')).toBe('— / Connected');
    });

    it('handles only admin status provided (non-down)', () => {
        expect(formatPortStatus('up', null)).toBe('Up / —');
    });
});

describe('formatSpeed', () => {
    it('formats auto-negotiated Mbps speeds', () => {
        expect(formatSpeed('a-1000')).toBe('1 Gbps');
        expect(formatSpeed('a-100')).toBe('100 Mbps');
        expect(formatSpeed('a-10')).toBe('10 Mbps');
    });

    it('formats fixed Mbps speeds', () => {
        expect(formatSpeed('1000')).toBe('1 Gbps');
        expect(formatSpeed('100')).toBe('100 Mbps');
        expect(formatSpeed('10')).toBe('10 Mbps');
    });

    it('formats fixed Gbps speeds', () => {
        expect(formatSpeed('10G')).toBe('10 Gbps');
        expect(formatSpeed('25G')).toBe('25 Gbps');
        expect(formatSpeed('40G')).toBe('40 Gbps');
        expect(formatSpeed('100G')).toBe('100 Gbps');
    });

    it('returns Auto for auto', () => {
        expect(formatSpeed('auto')).toBe('Auto');
    });

    it('returns dash for missing values', () => {
        expect(formatSpeed(null)).toBe('—');
        expect(formatSpeed(undefined)).toBe('—');
        expect(formatSpeed('')).toBe('—');
    });

    it('capitalizes unknown values', () => {
        expect(formatSpeed('custom')).toBe('Custom');
    });
});

describe('formatDuplex', () => {
    it('formats auto-negotiated duplex values', () => {
        expect(formatDuplex('a-full')).toBe('Full');
        expect(formatDuplex('a-half')).toBe('Half');
    });

    it('formats fixed duplex values', () => {
        expect(formatDuplex('full')).toBe('Full');
        expect(formatDuplex('half')).toBe('Half');
    });

    it('returns Auto for auto', () => {
        expect(formatDuplex('auto')).toBe('Auto');
    });

    it('returns dash for missing values', () => {
        expect(formatDuplex(null)).toBe('—');
        expect(formatDuplex(undefined)).toBe('—');
        expect(formatDuplex('')).toBe('—');
    });

    it('capitalizes unknown duplex values', () => {
        expect(formatDuplex('custom')).toBe('Custom');
    });
});

describe('formatVlan', () => {
    it('formats special switchport modes', () => {
        expect(formatVlan(null, 'trunk')).toBe('Trunk');
        expect(formatVlan(0, 'trunk')).toBe('Trunk');
        expect(formatVlan(null, 'routed')).toBe('Routed');
        expect(formatVlan(null, 'unassigned')).toBe('Unassigned');
        expect(formatVlan(null, 'suspended')).toBe('Suspended');
    });

    it('formats real VLAN IDs', () => {
        expect(formatVlan(100, 'access')).toBe('100');
        expect(formatVlan(100, null)).toBe('100');
    });

    it('returns dash when VLAN is missing with no special mode', () => {
        expect(formatVlan(null, null)).toBe('—');
        expect(formatVlan(0, null)).toBe('—');
    });
});
