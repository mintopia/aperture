<script setup>
import { ref, onMounted, onBeforeUnmount, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import { formatRelative } from '@/utils/dates';
import { formatBytes, formatBytesComponents } from '@/helpers.js';
import { useBandwidthChart } from '@/composables/useBandwidthChart.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ip: { type: Object, default: () => ({}) },
    port: { type: Object, default: () => ({}) }, // kept for metadata — passed from controller
    switchInfo: { type: Object, default: null },
    portBandwidth: { type: Object, default: null },
    portErrors: { type: Object, default: null },
    metricsAvailable: { type: Boolean, default: false },
    users: { type: Array, default: () => [] },
    macAddresses: { type: Array, default: () => [] },
    dhcpLeases: { type: Array, default: () => [] },
    auditLogs: { type: Array, default: () => [] },
});

const showAccessModal = ref(false);
const togglingAccess = ref(false);

function toggleInternet() {
    showAccessModal.value = true;
}

function confirmToggleInternet() {
    togglingAccess.value = true;
    router.post(
        route('admin.ips.internet', props.ip.address),
        {
            allow: props.ip.internet_enabled ? 0 : 1,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingAccess.value = false;
                showAccessModal.value = false;
            },
        },
    );
}

function toggleRateLimit() {
    router.post(
        route('admin.ips.limit', props.ip.address),
        { limit: props.ip.rate_limit_enabled ? 0 : 1 },
        { preserveScroll: true },
    );
}

function toggleDnsFilter() {
    router.post(
        route('admin.ips.dns-filter', props.ip.address),
        { filter: props.ip.dns_filtering_enabled ? 0 : 1 },
        { preserveScroll: true },
    );
}

const userColumns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'last_seen', label: 'Last Seen' },
];

const macColumns = [
    { key: 'mac_address', label: 'MAC Address' },
    { key: 'source', label: 'Source' },
    { key: 'last_seen_at', label: 'Last Seen' },
    { key: 'user', label: 'User' },
];

const dhcpColumns = [
    { key: 'mac_address', label: 'MAC Address' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'expires_at', label: 'Expires' },
    { key: 'updated_at', label: 'Last Updated' },
];

const auditColumns = [
    { key: 'action', label: 'Action' },
    { key: 'process', label: 'Process' },
    { key: 'created_at', label: 'Timestamp' },
];

const ranges = ['1h', '24h', '4d'];

const { selectedRange, bandwidthData, bandwidthLoading, bandwidthError, chartSeries, selectRange } =
    useBandwidthChart(route('admin.ips.bandwidth', props.ip.address), '24h', 30000);

function getThemeColor(variableName, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(variableName).trim() || fallback;
}

const portBandwidthSeries = computed(() => {
    const series = [];
    if (props.portBandwidth?.in?.length) {
        series.push({
            label: 'Inbound',
            data: props.portBandwidth.in,
            color: getThemeColor('--color-success', '#22c55e'),
            fill: true,
        });
    }
    if (props.portBandwidth?.out?.length) {
        series.push({
            label: 'Outbound',
            data: props.portBandwidth.out,
            color: getThemeColor('--color-info', '#3b82f6'),
            fill: true,
        });
    }
    return series;
});

const portErrorSeries = computed(() => {
    const series = [];
    if (props.portErrors?.in_series?.length) {
        series.push({
            label: 'Input Errors',
            data: props.portErrors.in_series,
            color: getThemeColor('--color-danger', '#ef4444'),
        });
    }
    if (props.portErrors?.out_series?.length) {
        series.push({
            label: 'Output Errors',
            data: props.portErrors.out_series,
            color: getThemeColor('--color-warning', '#f59e0b'),
        });
    }
    return series;
});

let portMetricsPoll = null;

function refreshPortMetrics() {
    router.reload({
        only: ['portBandwidth', 'portErrors'],
        preserveScroll: true,
    });
}

onMounted(() => {
    if (props.switchInfo) {
        portMetricsPoll = setInterval(refreshPortMetrics, 30000);
    }
});

onBeforeUnmount(() => {
    if (portMetricsPoll) clearInterval(portMetricsPoll);
});
</script>

<template>
    <div>
        <!-- Header -->
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                {{ ip.address }}
            </h1>
            <div class="flex items-center gap-2">
                <button
                    :data-testid="ip.internet_enabled ? 'action-revoke' : 'action-grant'"
                    :class="
                        ip.internet_enabled
                            ? 'border-[var(--color-danger)] bg-[var(--color-danger)]'
                            : 'border-[var(--color-success)] bg-[var(--color-success)]'
                    "
                    class="rounded-md border px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)]"
                    @click="toggleInternet"
                >
                    {{ ip.internet_enabled ? 'Revoke Access' : 'Grant Access' }}
                </button>
                <button
                    :data-testid="ip.rate_limit_enabled ? 'action-disable-rate-limit' : 'action-enable-rate-limit'"
                    class="rounded-md border border-[var(--color-border)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] hover:border-[var(--color-border-hover)] hover:text-[var(--color-text)]"
                    @click="toggleRateLimit"
                >
                    {{ ip.rate_limit_enabled ? 'Disable Rate Limit' : 'Enable Rate Limit' }}
                </button>
                <button
                    :data-testid="ip.dns_filtering_enabled ? 'action-disable-dns-filter' : 'action-enable-dns-filter'"
                    class="rounded-md border border-[var(--color-border)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] hover:border-[var(--color-border-hover)] hover:text-[var(--color-text)]"
                    @click="toggleDnsFilter"
                >
                    {{ ip.dns_filtering_enabled ? 'Disable DNS Filter' : 'Enable DNS Filter' }}
                </button>
            </div>
        </div>

        <!-- Confirm Modal -->
        <ConfirmModal
            :show="showAccessModal"
            :title="ip.internet_enabled ? 'Revoke Access?' : 'Grant Access?'"
            :message="
                ip.internet_enabled
                    ? 'This will deny internet access for this IP address.'
                    : 'This will grant internet access for this IP address.'
            "
            :confirm-label="ip.internet_enabled ? 'Revoke Access' : 'Grant Access'"
            :variant="ip.internet_enabled ? 'danger' : 'primary'"
            :loading="togglingAccess"
            @confirm="confirmToggleInternet"
            @cancel="showAccessModal = false"
        >
            <p v-if="users && users.length" class="mt-2 text-[13px] text-[var(--color-text-secondary)]">
                This IP has {{ users.length }} associated user(s).
            </p>
        </ConfirmModal>

        <!-- Metadata Strip -->
        <MetadataStrip
            :items="[
                { label: 'MAC Address', value: ip.current_mac?.mac_address || '\u2014', mono: true },
                {
                    label: 'Switch',
                    value: switchInfo ? switchInfo.switchName : '\u2014',
                    href: switchInfo?.switchId ? route('admin.switches.show', switchInfo.switchId) : null,
                },
                {
                    label: 'Port',
                    value: switchInfo ? switchInfo.portId : '\u2014',
                    href: switchInfo?.switchId
                        ? route('admin.switches.ports.show', [switchInfo.switchId, switchInfo.portId])
                        : null,
                },
                { label: 'Comment', value: ip.comment || '\u2014' },
            ]"
        />

        <!-- Two-column grid -->
        <div class="mt-5 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Left: Users -->
            <div>
                <SectionHeader title="Associated Users" />
                <DataTable
                    :columns="userColumns"
                    :rows="users ?? []"
                    clickable
                    :row-href="(row) => route('admin.users.show', row.user?.id)"
                    empty-message="No associated users"
                >
                    <template #row="{ row }">
                        <td class="font-mono text-[13px] text-[var(--color-primary)]">
                            {{ row.user?.nickname }}
                        </td>
                        <td class="text-[13px] text-[var(--color-text-secondary)]">
                            {{ formatRelative(row.last_seen_at) }}
                        </td>
                    </template>
                </DataTable>
            </div>

            <!-- Right: Internet + Port Metrics -->
            <div class="space-y-6">
                <div>
                    <div class="flex items-center justify-between">
                        <SectionHeader title="Internet" />
                        <div class="flex gap-1" data-testid="bandwidth-range-selector">
                            <button
                                v-for="range in ranges"
                                :key="range"
                                type="button"
                                :data-testid="'range-' + range"
                                :class="
                                    selectedRange === range
                                        ? 'bg-[var(--color-accent-dim)] font-semibold text-[var(--color-primary)]'
                                        : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text)]'
                                "
                                class="rounded-md px-3 py-1 text-[12px] font-medium transition-all"
                                @click="selectRange(range)"
                            >
                                {{ range }}
                            </button>
                        </div>
                    </div>
                    <div class="mt-2 flex items-baseline gap-4">
                        <div data-testid="bandwidth-download">
                            <span
                                class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                >Down</span
                            >
                            <span class="ml-1 font-mono text-sm font-bold text-[var(--color-success)]">
                                {{ formatBytes(bandwidthData.totalReceived) }}
                            </span>
                        </div>
                        <div data-testid="bandwidth-upload">
                            <span
                                class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                                >Up</span
                            >
                            <span class="ml-1 font-mono text-sm font-bold text-[var(--color-info)]">
                                {{ formatBytes(bandwidthData.totalSent) }}
                            </span>
                        </div>
                    </div>
                    <TimeSeriesChart
                        :series="chartSeries"
                        :loading="bandwidthLoading"
                        y-axis-label="bps"
                        height="200px"
                        empty-message="No bandwidth data available"
                        data-testid="admin-bandwidth-chart"
                        class="mt-2"
                    />
                    <p
                        v-if="bandwidthError"
                        class="mt-2 text-[12px] text-[var(--color-danger)]"
                        data-testid="bandwidth-error"
                    >
                        Failed to load bandwidth data
                    </p>
                </div>

                <!-- Port Bandwidth -->
                <div v-if="switchInfo" data-testid="section-port-bandwidth">
                    <SectionHeader title="Port Bandwidth — Last 24h" />
                    <div class="mb-3 flex items-baseline gap-6">
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-[10px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                >In</span
                            >
                            <span
                                class="font-heading text-[22px] font-bold tracking-[-0.02em] text-[var(--color-success)]"
                                :style="{ fontVariationSettings: '\'opsz\' 32' }"
                                >{{ formatBytesComponents(portBandwidth?.in_bytes ?? 0).value }}</span
                            >
                            <span class="text-[11px] font-semibold text-[var(--color-text-muted)]">{{
                                formatBytesComponents(portBandwidth?.in_bytes ?? 0).unit
                            }}</span>
                        </div>
                        <div class="flex items-baseline gap-1.5">
                            <span
                                class="text-[10px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                >Out</span
                            >
                            <span
                                class="font-heading text-[22px] font-bold tracking-[-0.02em] text-[var(--color-info)]"
                                :style="{ fontVariationSettings: '\'opsz\' 32' }"
                                >{{ formatBytesComponents(portBandwidth?.out_bytes ?? 0).value }}</span
                            >
                            <span class="text-[11px] font-semibold text-[var(--color-text-muted)]">{{
                                formatBytesComponents(portBandwidth?.out_bytes ?? 0).unit
                            }}</span>
                        </div>
                    </div>
                    <TimeSeriesChart
                        data-testid="port-bandwidth-chart"
                        :series="portBandwidthSeries"
                        y-axis-label="bps"
                        height="200px"
                        :empty-message="
                            metricsAvailable ? 'No port bandwidth data available' : 'Prometheus not configured'
                        "
                    />
                </div>

                <!-- Port Errors -->
                <div v-if="switchInfo" data-testid="section-port-errors">
                    <SectionHeader title="Port Errors — Last 24h" />
                    <div v-if="metricsAvailable">
                        <TimeSeriesChart
                            data-testid="port-errors-chart"
                            :series="portErrorSeries"
                            y-axis-label="errors/s"
                            height="160px"
                            empty-message="No error data for this port"
                        />
                    </div>
                    <p v-else class="text-[13px] text-[var(--color-text-muted)]">Prometheus not configured</p>
                </div>
            </div>
        </div>

        <!-- MAC Addresses -->
        <div class="mt-6" data-testid="ip-macs-section">
            <SectionHeader title="MAC Address History" />
            <DataTable
                :columns="macColumns"
                :rows="macAddresses ?? []"
                clickable
                :row-href="(row) => route('admin.macs.show', row.mac_address)"
                empty-message="No MAC address associations"
            >
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-primary)]">
                        {{ row.mac_address }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.source }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.last_seen_at ? formatRelative(row.last_seen_at) : '—' }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.user?.nickname ?? '—' }}
                    </td>
                </template>
            </DataTable>
        </div>

        <!-- DHCP Leases -->
        <div class="mt-6" data-testid="ip-dhcp-section">
            <SectionHeader title="DHCP Leases" />
            <DataTable :columns="dhcpColumns" :rows="dhcpLeases ?? []" empty-message="No DHCP leases">
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-primary)]">
                        {{ row.mac_address?.mac_address ?? '—' }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.hostname ?? '—' }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.expires_at ? formatRelative(row.expires_at) : '—' }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.updated_at ? formatRelative(row.updated_at) : '—' }}
                    </td>
                </template>
            </DataTable>
        </div>

        <!-- Audit Log -->
        <div class="mt-6" data-testid="ip-audit-section">
            <SectionHeader title="Audit Log" />
            <DataTable :columns="auditColumns" :rows="auditLogs ?? []" empty-message="No audit log entries">
                <template #row="{ row }">
                    <td class="font-mono text-[13px] text-[var(--color-text)]">
                        {{ row.action }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.process }}
                    </td>
                    <td class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.created_at ? formatRelative(row.created_at) : '—' }}
                    </td>
                </template>
            </DataTable>
        </div>
    </div>
</template>
