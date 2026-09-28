import http from 'node:http';
import { pathToFileURL } from 'node:url';
import { sshProxyStubApiKey, sshProxyStubPort } from '../../../playwright/env.js';

const hosts = new Map();

const PORTS = [
    { name: 'Gi1/0/1', description: 'e2e-uplink', vlan: '10', mac: 'aabb.ccdd.0001' },
    { name: 'Gi1/0/2', description: 'e2e-access', vlan: '20', mac: 'aabb.ccdd.0002' },
];

function hostState(hostname) {
    if (!hosts.has(hostname)) {
        hosts.set(hostname, { down: new Set(), log: [] });
    }
    return hosts.get(hostname);
}

function interfaceStatusTable(state) {
    const rows = PORTS.map((p) => {
        const status = state.down.has(p.name) ? 'disabled' : 'connected';
        return `${p.name.padEnd(9)} ${p.description.padEnd(18)} ${status.padEnd(12)} ${p.vlan.padEnd(10)} a-full  a-1000 10/100/1000BaseTX`;
    });
    return ['Port      Name               Status       Vlan       Duplex  Speed Type', ...rows].join('\n');
}

function macTable() {
    return [
        'Vlan    Mac Address       Type        Ports',
        ...PORTS.map((p) => `${p.vlan.padStart(4)}    ${p.mac}    DYNAMIC     ${p.name}`),
    ].join('\n');
}

function runCommands(state, commands) {
    let currentInterface = null;

    return commands.map(({ command }) => {
        state.log.push(command);

        const iface = command.match(/^interface (\S+)$/);
        if (iface) currentInterface = iface[1];
        if (command === 'shutdown' && currentInterface) state.down.add(currentInterface);
        if (command === 'no shutdown' && currentInterface) state.down.delete(currentInterface);

        const output = {
            'show interface status': interfaceStatusTable(state),
            'show mac address-table': macTable(),
            'show ip dhcp pool':
                'Pool e2e-pool :\n Utilization mark (high/low)    : 100 / 0\n Total addresses                : 254\n Leased addresses               : 1',
            'show running-config': 'hostname e2e-stub\n!\nend',
        }[command];

        return { command, output: output ?? '' };
    });
}

function readBody(req) {
    return new Promise((resolve) => {
        let raw = '';
        req.on('data', (chunk) => (raw += chunk));
        req.on('end', () => resolve(raw));
    });
}

function send(res, status, body) {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(body));
}

export function createSshProxyStub() {
    return http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://stub');

        if (url.pathname === '/health') return send(res, 200, { ok: true });

        if (url.pathname === '/__log') {
            return send(res, 200, { commands: hostState(url.searchParams.get('hostname') ?? '').log });
        }

        if (req.headers.authorization !== `Bearer ${sshProxyStubApiKey}`) {
            return send(res, 401, { error: 'unauthorized' });
        }

        if (url.pathname === '/status') return send(res, 200, { uptime_seconds: 1, connections: [] });

        if (url.pathname === '/execute' && req.method === 'POST') {
            const { hostname, commands } = JSON.parse(await readBody(req));

            if (String(hostname).includes('unreachable')) {
                return send(res, 200, { success: false, output: [], error: 'dial tcp: connection refused' });
            }

            return send(res, 200, { success: true, output: runCommands(hostState(hostname), commands) });
        }

        return send(res, 404, { error: 'not found' });
    });
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    createSshProxyStub().listen(Number(sshProxyStubPort), '127.0.0.1');
}
