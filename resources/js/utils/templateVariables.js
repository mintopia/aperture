export const TEMPLATE_VARIABLES = [
    { key: '{ipv4}', label: 'Client IPv4 address', group: 'Connection' },
    { key: '{ipv6}', label: 'Client IPv6 address', group: 'Connection' },
    { key: '{mac}', label: 'Client MAC address', group: 'Connection' },
    { key: '{user.name}', label: "User's display name", group: 'User' },
    { key: '{user.*}', label: 'Any user property', group: 'User' },
    { key: '{user.params.*}', label: 'User parameter by key (e.g. {user.params.seat})', group: 'User Parameters' },
];

export const TEMPLATE_VARIABLE_GROUPS = [...new Set(TEMPLATE_VARIABLES.map((v) => v.group))];
