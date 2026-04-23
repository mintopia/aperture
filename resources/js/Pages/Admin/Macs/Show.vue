<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelative } from '@/utils/dates';
import { formatBytes } from '@/helpers.js';
import { ipStatusLabel, ipStatusDotClass, ipStatusTextClass } from '@/utils/ipStatus';

defineOptions({ layout: AdminLayout });

defineProps({
    mac: { type: Object, default: () => ({}) },
    ipAddresses: { type: Array, default: () => [] },
    dhcpLeases: { type: Array, default: () => [] },
    switchPorts: { type: Array, default: () => [] },
    auditLogs: { type: Array, default: () => [] },
});

const ipColumns = [
    { key: 'address', label: 'Address' },
    { key: 'status', label: 'Status' },
    { key: 'source', label: 'Source' },
    { key: 'last_seen', label: 'Last Seen' },
    { key: 'bandwidth', label: 'Bandwidth' },
];

const dhcpColumns = [
    { key: 'ip_address', label: 'IP Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'expires_at', label: 'Expires' },
    { key: 'updated_at', label: 'Last Updated' },
];

const switchPortColumns = [
    { key: 'switch', label: 'Switch' },
    { key: 'port', label: 'Port' },
    { key: 'vlan', label: 'VLAN' },
    { key: 'last_seen', label: 'Last Seen' },
];

const auditColumns = [
    { key: 'action', label: 'Action' },
    { key: 'created_at', label: 'Timestamp' },
    { key: 'actor', label: 'Actor' },
    { key: 'process', label: 'Process' },
    { key: 'details', label: 'Details' },
];

function formatMetadata(metadata) {
    if (!metadata) return '—';
    if (typeof metadata === 'string') return metadata;
    try {
        return JSON.stringify(metadata);
    } catch {
        return '—';
    }
}
</script>

<template>
    <div data-testid="mac-show-layout">
        <!-- Page Header -->
        <header data-testid="mac-show-header" class="mb-2">
            <h1
                data-testid="page-title"
                class="font-heading font-mono text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                style="font-variation-settings: 'opsz' 48"
            >
                {{ mac.mac_address }}
            </h1>
            <p v-if="mac.hostname" class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                {{ mac.hostname }}
            </p>
        </header>

        <!-- Metadata Strip -->
        <MetadataStrip
            :items="[
                {
                    label: 'User',
                    value: mac.user ? mac.user.nickname : '\u2014',
                    href: mac.user ? route('admin.users.show', mac.user.id) : null,
                },
                { label: 'Source', value: mac.source ?? '\u2014' },
                { label: 'First Seen', value: formatRelative(mac.created_at) },
                { label: 'Description', value: mac.description ?? '\u2014' },
            ]"
        />

        <!-- Associated IPs -->
        <section data-testid="mac-ips-section" class="mb-8">
            <SectionHeader title="Associated IPs" />
            <DataTable
                :columns="ipColumns"
                :rows="ipAddresses"
                clickable
                :row-href="(row) => route('admin.ips.show', row.address)"
                :row-aria-label="(row) => `Open IP ${row.address}`"
                empty-message="No associated IP addresses."
            >
                <template #row="{ row }">
                    <td data-testid="ip-address" class="font-mono text-[13px] text-[var(--color-primary)]">
                        {{ row.address }}
                    </td>
                    <td data-testid="ip-status">
                        <span class="inline-flex items-center gap-1.5">
                            <span
                                class="h-[7px] w-[7px] rounded-full"
                                :class="ipStatusDotClass(row.internet_enabled)"
                            />
                            <span class="text-[12px] font-semibold" :class="ipStatusTextClass(row.internet_enabled)">
                                {{ ipStatusLabel(row.internet_enabled) }}
                            </span>
                        </span>
                    </td>
                    <td data-testid="ip-source" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.source ?? '—' }}
                    </td>
                    <td data-testid="ip-last-seen" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.last_seen_at) }}
                    </td>
                    <td data-testid="ip-bandwidth" class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                        <span class="text-[var(--color-success)]">↓ {{ formatBytes(row.received ?? 0) }}</span>
                        <span class="ml-2 text-[var(--color-info)]">↑ {{ formatBytes(row.sent ?? 0) }}</span>
                    </td>
                </template>
            </DataTable>
        </section>

        <!-- DHCP Leases -->
        <section data-testid="mac-dhcp-section" class="mb-8">
            <SectionHeader title="DHCP Leases" />
            <DataTable :columns="dhcpColumns" :rows="dhcpLeases" empty-message="No DHCP leases found.">
                <template #row="{ row }">
                    <td data-testid="dhcp-ip" class="font-mono text-[13px]">
                        <Link
                            v-if="row.ip_address"
                            :href="route('admin.ips.show', row.ip_address.address)"
                            class="text-[var(--color-primary)] hover:underline"
                        >
                            {{ row.ip_address.address }}
                        </Link>
                        <span v-else class="text-[var(--color-text-muted)]">—</span>
                    </td>
                    <td data-testid="dhcp-hostname" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.hostname ?? '—' }}
                    </td>
                    <td data-testid="dhcp-expires" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.expires_at) || '—' }}
                    </td>
                    <td data-testid="dhcp-updated" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.updated_at) || '—' }}
                    </td>
                </template>
            </DataTable>
        </section>

        <!-- Switch Ports -->
        <section data-testid="mac-switch-ports-section" class="mb-8">
            <SectionHeader title="Switch Ports" />
            <DataTable
                :columns="switchPortColumns"
                :rows="switchPorts"
                empty-message="No switch port associations found."
            >
                <template #row="{ row }">
                    <td data-testid="switch-name" class="text-[13px]">
                        <Link
                            v-if="row.switch_id"
                            :href="route('admin.switches.show', row.switch_id)"
                            class="text-[var(--color-primary)] hover:underline"
                        >
                            {{ row.switch_name ?? row.switch_id }}
                        </Link>
                        <span v-else class="text-[var(--color-text-muted)]">—</span>
                    </td>
                    <td data-testid="switch-port" class="font-mono text-[13px]">
                        <Link
                            v-if="row.switch_id"
                            :href="route('admin.switches.ports.show', [row.switch_id, row.id])"
                            class="text-[var(--color-primary)] hover:underline"
                        >
                            {{ row.port_name }}
                        </Link>
                        <span v-else class="text-[var(--color-text-secondary)]">{{ row.port_name ?? '—' }}</span>
                    </td>
                    <td data-testid="switch-vlan" class="font-mono text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.vlan ?? '—' }}
                    </td>
                    <td data-testid="switch-last-seen" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.last_seen_at) || '—' }}
                    </td>
                </template>
            </DataTable>
        </section>

        <!-- Audit Log -->
        <section data-testid="mac-audit-section" class="mb-8">
            <SectionHeader title="Audit Log" />
            <DataTable :columns="auditColumns" :rows="auditLogs" empty-message="No audit log entries.">
                <template #row="{ row }">
                    <td data-testid="audit-action" class="text-[13px] font-semibold text-[var(--color-text)]">
                        {{ row.action }}
                    </td>
                    <td data-testid="audit-timestamp" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.created_at) }}
                    </td>
                    <td data-testid="audit-actor" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.actor ?? '—' }}
                    </td>
                    <td data-testid="audit-process" class="font-mono text-[13px] text-[var(--color-text-muted)]">
                        {{ row.process ?? '—' }}
                    </td>
                    <td
                        data-testid="audit-details"
                        class="max-w-[240px] truncate font-mono text-[12px] text-[var(--color-text-muted)]"
                    >
                        {{ formatMetadata(row.metadata) }}
                    </td>
                </template>
            </DataTable>
        </section>
    </div>
</template>
