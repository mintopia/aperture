export const switchTypeLabels = {
    cisco: 'Cisco IOS',
    cisco_ios: 'Cisco IOS',
    cisco_nxos: 'Cisco NX-OS',
    arista_eos: 'Arista EOS',
    juniper_junos: 'Juniper JunOS',
    snmp: 'SNMP',
};

export function typeLabel(type) {
    return switchTypeLabels[type] ?? type;
}

function capitalize(value) {
    return value.charAt(0).toUpperCase() + value.slice(1);
}

function formatNumericSpeed(value) {
    if (value >= 1000) return `${value / 1000} Gbps`;
    return `${value} Mbps`;
}

const statusLabels = {
    connected: 'Connected',
    notconnect: 'Not Connected',
    'err-disabled': 'Error Disabled',
    up: 'Up',
    down: 'Down',
    disabled: 'Disabled',
};

export function statusLabel(status) {
    if (!status) return '—';
    if (statusLabels[status] !== undefined) return statusLabels[status];
    return capitalize(status);
}

export function formatPortStatus(adminStatus, operStatus) {
    if (!adminStatus && !operStatus) return '—';
    const admin = statusLabel(adminStatus);
    const oper = statusLabel(operStatus);
    if (adminStatus === 'down' && !operStatus) return 'Admin Down';
    return `${admin} / ${oper}`;
}

export function formatSpeed(raw) {
    if (!raw) return '—';
    if (raw === 'auto') return 'Auto';

    const gbpsMatch = raw.match(/^(\d+)G$/);
    if (gbpsMatch) return `${gbpsMatch[1]} Gbps`;

    const autoMbpsMatch = raw.match(/^a-(\d+)$/);
    if (autoMbpsMatch) return formatNumericSpeed(Number(autoMbpsMatch[1]));

    if (/^\d+$/.test(raw)) return formatNumericSpeed(Number(raw));

    return capitalize(raw);
}

export function formatDuplex(raw) {
    if (!raw) return '—';
    if (raw === 'auto') return 'Auto';
    if (raw === 'a-full' || raw === 'full') return 'Full';
    if (raw === 'a-half' || raw === 'half') return 'Half';
    return capitalize(raw);
}

export function formatVlan(vlan, switchportMode) {
    if (switchportMode === 'trunk' && (vlan === null || vlan === 0)) return 'Trunk';
    if (switchportMode === 'routed' && vlan === null) return 'Routed';
    if (switchportMode === 'unassigned') return 'Unassigned';
    if (switchportMode === 'suspended') return 'Suspended';
    if (vlan === null || vlan === 0) return '—';
    return String(vlan);
}
