import { describe, it, expect } from 'vitest';
import { typeLabel, statusLabel, formatPortStatus, formatSpeed, formatDuplex, formatVlan } from '@/utils/switches';

describe('typeLabel', () => {
    it.each([
        { input: 'cisco', expected: 'Cisco IOS' },
        { input: 'cisco_ios', expected: 'Cisco IOS' },
        { input: 'cisco_nxos', expected: 'Cisco NX-OS' },
        { input: 'arista_eos', expected: 'Arista EOS' },
        { input: 'juniper_junos', expected: 'Juniper JunOS' },
        { input: 'snmp', expected: 'SNMP' },
        { input: 'unknown', expected: 'unknown' },
    ])('$input -> $expected', ({ input, expected }) => {
        expect(typeLabel(input)).toBe(expected);
    });
});

describe('statusLabel', () => {
    it.each([
        { name: 'connected', input: 'connected', expected: 'Connected' },
        { name: 'notconnect', input: 'notconnect', expected: 'Not Connected' },
        { name: 'err-disabled', input: 'err-disabled', expected: 'Error Disabled' },
        { name: 'up', input: 'up', expected: 'Up' },
        { name: 'down', input: 'down', expected: 'Down' },
        { name: 'disabled', input: 'disabled', expected: 'Disabled' },
        { name: 'unknown status (capitalized)', input: 'monitoring', expected: 'Monitoring' },
        { name: 'single character status (capitalized)', input: 'x', expected: 'X' },
        { name: 'null', input: null, expected: '—' },
        { name: 'undefined', input: undefined, expected: '—' },
        { name: 'empty string', input: '', expected: '—' },
    ])('$name -> $expected', ({ input, expected }) => {
        expect(statusLabel(input)).toBe(expected);
    });
});

describe('formatPortStatus', () => {
    it.each([
        { name: 'up/connected', admin: 'up', oper: 'connected', expected: 'Up / Connected' },
        { name: 'down/notconnect', admin: 'down', oper: 'notconnect', expected: 'Down / Not Connected' },
        { name: 'up/err-disabled', admin: 'up', oper: 'err-disabled', expected: 'Up / Error Disabled' },
        { name: 'down/down', admin: 'down', oper: 'down', expected: 'Down / Down' },
        { name: 'down/null oper -> Admin Down', admin: 'down', oper: null, expected: 'Admin Down' },
        { name: 'down/undefined oper -> Admin Down', admin: 'down', oper: undefined, expected: 'Admin Down' },
        { name: 'down/empty oper -> Admin Down', admin: 'down', oper: '', expected: 'Admin Down' },
        { name: 'both null -> dash', admin: null, oper: null, expected: '—' },
        { name: 'both undefined -> dash', admin: undefined, oper: undefined, expected: '—' },
        { name: 'both empty -> dash', admin: '', oper: '', expected: '—' },
        { name: 'only oper provided', admin: null, oper: 'connected', expected: '— / Connected' },
        { name: 'only admin provided (non-down)', admin: 'up', oper: null, expected: 'Up / —' },
    ])('$name -> $expected', ({ admin, oper, expected }) => {
        expect(formatPortStatus(admin, oper)).toBe(expected);
    });
});

describe('formatSpeed', () => {
    it.each([
        { name: 'auto-negotiated Gbps', input: 'a-1000', expected: '1 Gbps' },
        { name: 'auto-negotiated 100 Mbps', input: 'a-100', expected: '100 Mbps' },
        { name: 'auto-negotiated 10 Mbps', input: 'a-10', expected: '10 Mbps' },
        { name: 'fixed Gbps (1000)', input: '1000', expected: '1 Gbps' },
        { name: 'fixed 100 Mbps', input: '100', expected: '100 Mbps' },
        { name: 'fixed 10 Mbps', input: '10', expected: '10 Mbps' },
        { name: 'fixed 10G', input: '10G', expected: '10 Gbps' },
        { name: 'fixed 25G', input: '25G', expected: '25 Gbps' },
        { name: 'fixed 40G', input: '40G', expected: '40 Gbps' },
        { name: 'fixed 100G', input: '100G', expected: '100 Gbps' },
        { name: 'auto', input: 'auto', expected: 'Auto' },
        { name: 'null', input: null, expected: '—' },
        { name: 'undefined', input: undefined, expected: '—' },
        { name: 'empty string', input: '', expected: '—' },
        { name: 'unknown value (capitalized)', input: 'custom', expected: 'Custom' },
    ])('$name -> $expected', ({ input, expected }) => {
        expect(formatSpeed(input)).toBe(expected);
    });
});

describe('formatDuplex', () => {
    it.each([
        { name: 'auto-negotiated full', input: 'a-full', expected: 'Full' },
        { name: 'auto-negotiated half', input: 'a-half', expected: 'Half' },
        { name: 'fixed full', input: 'full', expected: 'Full' },
        { name: 'fixed half', input: 'half', expected: 'Half' },
        { name: 'auto', input: 'auto', expected: 'Auto' },
        { name: 'null', input: null, expected: '—' },
        { name: 'undefined', input: undefined, expected: '—' },
        { name: 'empty string', input: '', expected: '—' },
        { name: 'unknown value (capitalized)', input: 'custom', expected: 'Custom' },
    ])('$name -> $expected', ({ input, expected }) => {
        expect(formatDuplex(input)).toBe(expected);
    });
});

describe('formatVlan', () => {
    it.each([
        { name: 'trunk mode with null vlan', vlan: null, mode: 'trunk', expected: 'Trunk' },
        { name: 'trunk mode with zero vlan', vlan: 0, mode: 'trunk', expected: 'Trunk' },
        { name: 'routed mode', vlan: null, mode: 'routed', expected: 'Routed' },
        { name: 'unassigned mode', vlan: null, mode: 'unassigned', expected: 'Unassigned' },
        { name: 'suspended mode', vlan: null, mode: 'suspended', expected: 'Suspended' },
        { name: 'real vlan id with access mode', vlan: 100, mode: 'access', expected: '100' },
        { name: 'real vlan id with null mode', vlan: 100, mode: null, expected: '100' },
        { name: 'null vlan and mode -> dash', vlan: null, mode: null, expected: '—' },
        { name: 'zero vlan and null mode -> dash', vlan: 0, mode: null, expected: '—' },
    ])('$name -> $expected', ({ vlan, mode, expected }) => {
        expect(formatVlan(vlan, mode)).toBe(expected);
    });
});
