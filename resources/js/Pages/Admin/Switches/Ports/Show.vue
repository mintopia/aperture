<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import ConnectedDevicesSummary from '@/Components/UI/ConnectedDevicesSummary.vue';
import { formatBytes } from '@/helpers.js';
import { formatPortStatus, formatSpeed, formatDuplex, formatVlan, typeLabel } from '@/utils/switches';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switchConfig: { type: Object, default: () => ({}) },
    port: { type: Object, default: () => ({}) },
    macs: { type: Array, default: () => [] },
    bandwidth: { type: Object, default: () => ({}) },
    errors: { type: Object, default: () => ({}) },
    metricsAvailable: { type: Boolean, default: false },
    prevPort: { type: String, default: null },
    nextPort: { type: String, default: null },
});

const refreshing = ref(false);
const toggling = ref(false);
const showToggleModal = ref(false);
const showDevicesWithoutIp = ref(false);

const lastUpdated = ref(new Date());
const displayTime = ref('just now');
let pollInterval = null;
let displayTimer = null;

function updateDisplayTime() {
    const seconds = Math.floor((Date.now() - lastUpdated.value.getTime()) / 1000);
    if (seconds < 10) displayTime.value = 'just now';
    else if (seconds < 60) displayTime.value = `${seconds}s ago`;
    else displayTime.value = `${Math.floor(seconds / 60)}m ago`;
}

function refreshData() {
    router.reload({
        only: ['port', 'macs', 'bandwidth', 'errors'],
        preserveScroll: true,
        onSuccess: () => {
            lastUpdated.value = new Date();
        },
    });
}

onMounted(() => {
    pollInterval = setInterval(refreshData, 30000);
    displayTimer = setInterval(updateDisplayTime, 5000);
});

onBeforeUnmount(() => {
    if (pollInterval) clearInterval(pollInterval);
    if (displayTimer) clearInterval(displayTimer);
});

function getThemeColor(variableName, fallback) {
    if (typeof window === 'undefined') {
        return fallback;
    }

    return getComputedStyle(document.documentElement).getPropertyValue(variableName).trim() || fallback;
}

const bandwidthSeries = computed(() => {
    const series = [];

    if (props.bandwidth?.in?.length) {
        series.push({
            label: 'Inbound',
            data: props.bandwidth.in,
            color: getThemeColor('--color-success', '#22c55e'),
            fill: true,
        });
    }

    if (props.bandwidth?.out?.length) {
        series.push({
            label: 'Outbound',
            data: props.bandwidth.out,
            color: getThemeColor('--color-primary', '#6366f1'),
            fill: true,
        });
    }

    return series;
});

const errorSeries = computed(() => {
    const series = [];

    if (props.errors?.in_series?.length) {
        series.push({
            label: 'Input Errors',
            data: props.errors.in_series,
            color: getThemeColor('--color-danger', '#ef4444'),
        });
    }

    if (props.errors?.out_series?.length) {
        series.push({
            label: 'Output Errors',
            data: props.errors.out_series,
            color: getThemeColor('--color-warning', '#f59e0b'),
        });
    }

    return series;
});

const formattedSpeedDuplex = computed(() => {
    const speed = formatSpeed(props.port.speed);
    const duplex = formatDuplex(props.port.duplex);

    if (speed === '—') return duplex === '—' ? '—' : duplex;
    return `${speed} ${duplex !== '—' ? duplex : ''}`.trim() || '—';
});

const metadataItems = computed(() => [
    { label: 'Interface', value: props.port.interface ?? '—', mono: true },
    { label: 'Description', value: props.port.description || '—' },
    { label: 'Status', value: formatPortStatus(props.port.admin_status, props.port.status) },
    { label: 'Speed', value: formattedSpeedDuplex.value },
    { label: 'VLAN', value: formatVlan(props.port.vlan, props.port.switchport_mode), mono: true },
    { label: 'POE', value: props.port.poe || '—' },
]);

function statusType(status) {
    if (['up', 'connected'].includes(status)) return 'success';
    if (['down', 'err-disabled'].includes(status)) return 'danger';
    if (['disabled', 'notconnect'].includes(status)) return 'neutral';
    return 'warning';
}

function isIpv6Address(ip) {
    return typeof ip === 'string' && ip.includes(':');
}

function resolveIpEntries(mac, version) {
    return (mac.resolved_ips ?? []).filter((resolved) => {
        if (!resolved?.ip) return false;
        return version === 'ipv6' ? isIpv6Address(resolved.ip) : !isIpv6Address(resolved.ip);
    });
}

const visibleMacs = computed(() =>
    props.macs.filter((mac) => {
        const hasIpv4 = resolveIpEntries(mac, 'ipv4').length > 0;
        const hasIpv6 = resolveIpEntries(mac, 'ipv6').length > 0;
        return showDevicesWithoutIp.value ? true : hasIpv4 || hasIpv6;
    }),
);

function refreshPort() {
    refreshing.value = true;
    router.visit(
        route('admin.switches.ports.show', {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                lastUpdated.value = new Date();
            },
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}

const isAdminUp = computed(() => props.port.admin_status === 'up');
const toggleLabel = computed(() => (isAdminUp.value ? 'Shut' : 'Unshut'));
const toggleTitle = computed(() =>
    isAdminUp.value ? 'Administratively disable this port' : 'Administratively enable this port',
);
const toggleLoadingLabel = computed(() => (isAdminUp.value ? 'Shutting down…' : 'Enabling…'));
const toggleClass = computed(() =>
    isAdminUp.value
        ? 'rounded-lg bg-[var(--color-danger)] px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-danger)]/80 focus-visible:ring-2 focus-visible:ring-[var(--color-danger)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none disabled:opacity-50'
        : 'rounded-lg bg-[var(--color-success)] px-3.5 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-[var(--color-success)]/80 focus-visible:ring-2 focus-visible:ring-[var(--color-success)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none disabled:opacity-50',
);
const toggleConfirmTitle = computed(() => (isAdminUp.value ? 'Shut Down Port?' : 'Enable Port?'));
const toggleConfirmMessage = computed(() =>
    isAdminUp.value
        ? `This will disable ${props.port.interface}. All connected devices will lose connectivity.`
        : `This will enable ${props.port.interface} and restore connectivity for connected devices.`,
);
const toggleConfirmLabel = computed(() => (isAdminUp.value ? 'Shut Down' : 'Enable'));
const toggleConfirmVariant = computed(() => (isAdminUp.value ? 'danger' : 'primary'));

function togglePort() {
    showToggleModal.value = true;
}

function confirmToggle() {
    const action = isAdminUp.value ? 'shutdown' : 'enable';
    toggling.value = true;
    router.post(
        route(`admin.switches.ports.${action}`, {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                showToggleModal.value = false;
            },
        },
    );
}
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div class="space-y-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <h1
                    data-testid="page-title"
                    class="font-heading font-mono text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                >
                    {{ port.interface }}
                </h1>
                <div data-testid="header-actions" class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <button
                        data-testid="action-refresh"
                        title="Sync this port's data from the switch"
                        :disabled="refreshing"
                        class="rounded-lg border border-[var(--color-border)] px-3.5 py-1.5 text-sm font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none disabled:opacity-50"
                        @click="refreshPort"
                    >
                        {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                    </button>
                    <button
                        data-testid="action-toggle"
                        :title="toggleTitle"
                        :disabled="toggling"
                        :class="toggleClass"
                        @click="togglePort"
                    >
                        {{ toggling ? toggleLoadingLabel : toggleLabel }}
                    </button>
                </div>
            </div>
            <div data-testid="port-context-strip" class="pt-1">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-[var(--color-text-secondary)]">
                        on
                        <Link
                            data-testid="port-switch-link"
                            :href="route('admin.switches.show', switchConfig.id)"
                            class="font-medium hover:text-[var(--color-primary)] focus-visible:underline focus-visible:outline-none"
                        >
                            {{ switchConfig.name }}
                        </Link>
                        <span class="text-[var(--color-text-muted)]">({{ typeLabel(switchConfig.type) }})</span>
                    </p>
                    <div class="flex items-center gap-3">
                        <Link
                            v-if="prevPort"
                            data-testid="port-nav-prev"
                            :href="
                                route('admin.switches.ports.show', {
                                    switchConfig: switchConfig.id,
                                    portId: prevPort,
                                })
                            "
                            class="text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-primary)] focus-visible:underline focus-visible:outline-none"
                        >
                            ← {{ prevPort }}
                        </Link>
                        <Link
                            v-if="nextPort"
                            data-testid="port-nav-next"
                            :href="
                                route('admin.switches.ports.show', {
                                    switchConfig: switchConfig.id,
                                    portId: nextPort,
                                })
                            "
                            class="text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-primary)] focus-visible:underline focus-visible:outline-none"
                        >
                            {{ nextPort }} →
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="showToggleModal"
            :title="toggleConfirmTitle"
            :message="toggleConfirmMessage"
            :confirm-label="toggleConfirmLabel"
            :variant="toggleConfirmVariant"
            :loading="toggling"
            @confirm="confirmToggle"
            @cancel="showToggleModal = false"
        >
            <ConnectedDevicesSummary :macs="macs" />
        </ConfirmModal>

        <!-- Status Strip -->
        <MetadataStrip :items="metadataItems">
            <template #Status>
                <StatusPill
                    :status="statusType(port.status)"
                    :label="formatPortStatus(port.admin_status, port.status)"
                />
            </template>
        </MetadataStrip>

        <p data-testid="last-updated" class="-mt-2 text-xs text-[var(--color-text-muted)]">
            Last updated {{ displayTime }}
        </p>

        <div data-testid="layout-row-primary" class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)]">
            <div
                data-testid="layout-columns"
                class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]"
            >
                <div data-testid="layout-column-left" class="space-y-6">
                    <!-- Connected Devices -->
                    <div data-testid="section-connected-devices">
                        <div data-testid="connected-devices-section">
                            <SectionHeader :title="`Connected Devices (${visibleMacs.length})`" accent-line />
                            <label
                                class="mt-2 mb-3 inline-flex cursor-pointer items-center gap-2 text-sm text-[var(--color-text-secondary)]"
                            >
                                <input
                                    v-model="showDevicesWithoutIp"
                                    data-testid="show-devices-without-ip-toggle"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-[var(--color-border)] text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                                />
                                <span>Include devices without any IP address</span>
                            </label>
                            <div
                                v-if="visibleMacs.length === 0"
                                data-testid="mac-list-empty"
                                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                            >
                                <p class="text-center text-sm text-[var(--color-text-muted)]">No devices to display</p>
                            </div>
                            <div
                                v-else
                                data-testid="connected-devices-table-wrapper"
                                class="overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]"
                            >
                                <table
                                    data-testid="connected-devices-table"
                                    class="min-w-full divide-y divide-[var(--color-border)]"
                                >
                                    <thead class="bg-[var(--color-surface-hover)]">
                                        <tr>
                                            <th
                                                class="px-4 py-2 text-left text-xs font-semibold tracking-wide text-[var(--color-text-muted)] uppercase"
                                            >
                                                MAC
                                            </th>
                                            <th
                                                class="px-4 py-2 text-left text-xs font-semibold tracking-wide text-[var(--color-text-muted)] uppercase"
                                            >
                                                IPv4
                                            </th>
                                            <th
                                                class="px-4 py-2 text-left text-xs font-semibold tracking-wide text-[var(--color-text-muted)] uppercase"
                                            >
                                                IPv6
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[var(--color-border)] text-sm">
                                        <tr
                                            v-for="(mac, index) in visibleMacs"
                                            :key="index"
                                            :data-testid="'connected-device-row-' + index"
                                        >
                                            <td class="px-4 py-2 font-mono text-sm text-[var(--color-text)]">
                                                {{ mac.mac_address ?? '—' }}
                                            </td>
                                            <td class="px-4 py-2 break-all">
                                                <div
                                                    v-if="resolveIpEntries(mac, 'ipv4').length"
                                                    class="flex flex-col gap-1"
                                                >
                                                    <template
                                                        v-for="(resolved, rIdx) in resolveIpEntries(mac, 'ipv4')"
                                                        :key="'ipv4-' + rIdx"
                                                    >
                                                        <Link
                                                            :href="route('admin.ips.show', resolved.ip)"
                                                            :data-testid="`device-ipv4-link-${index}-${rIdx}`"
                                                            class="font-mono text-sm break-all text-[var(--color-accent)] hover:underline"
                                                        >
                                                            {{ resolved.ip }}
                                                        </Link>
                                                    </template>
                                                </div>
                                                <span v-else class="text-sm text-[var(--color-text-muted)]">—</span>
                                            </td>
                                            <td class="px-4 py-2 break-all">
                                                <div
                                                    v-if="resolveIpEntries(mac, 'ipv6').length"
                                                    class="flex flex-col gap-1"
                                                >
                                                    <template
                                                        v-for="(resolved, rIdx) in resolveIpEntries(mac, 'ipv6')"
                                                        :key="'ipv6-' + rIdx"
                                                    >
                                                        <Link
                                                            :href="route('admin.ips.show', resolved.ip)"
                                                            :data-testid="`device-ipv6-link-${index}-${rIdx}`"
                                                            class="font-mono text-sm break-all text-[var(--color-accent)] hover:underline"
                                                        >
                                                            {{ resolved.ip }}
                                                        </Link>
                                                    </template>
                                                </div>
                                                <span v-else class="text-sm text-[var(--color-text-muted)]">—</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Interface Output -->
                    <div data-testid="section-interface-output">
                        <details
                            data-testid="interface-output-collapsible"
                            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)]"
                        >
                            <summary
                                class="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-[var(--color-text-secondary)] hover:text-[var(--color-text)]"
                            >
                                Interface Output
                            </summary>
                            <div
                                data-testid="interface-output-section"
                                class="border-t border-[var(--color-border)] p-4"
                            >
                                <ConfigBlock v-if="port.interface_output" :code="port.interface_output" />
                                <div v-else class="text-sm text-[var(--color-text-muted)]">
                                    No interface output available
                                </div>
                            </div>
                        </details>
                    </div>
                </div>

                <div data-testid="layout-column-right" class="space-y-6">
                    <!-- Bandwidth -->
                    <div data-testid="section-bandwidth">
                        <SectionHeader title="Bandwidth — Last 24 Hours" accent-line />
                        <div
                            data-testid="bandwidth-section"
                            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                        >
                            <div class="mb-4 flex flex-wrap gap-6">
                                <div>
                                    <p
                                        class="text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                                    >
                                        In
                                    </p>
                                    <p class="font-mono text-lg font-bold text-[var(--color-success)]">
                                        {{ formatBytes(bandwidth.in_bytes ?? 0) }}
                                    </p>
                                </div>
                                <div>
                                    <p
                                        class="text-[10px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
                                    >
                                        Out
                                    </p>
                                    <p class="font-mono text-lg font-bold text-[var(--color-primary)]">
                                        {{ formatBytes(bandwidth.out_bytes ?? 0) }}
                                    </p>
                                </div>
                            </div>
                            <TimeSeriesChart
                                data-testid="bandwidth-chart"
                                :series="bandwidthSeries"
                                y-axis-label="bps"
                                height="200px"
                                :empty-message="
                                    metricsAvailable ? 'No bandwidth data for this port' : 'Prometheus not configured'
                                "
                            />
                        </div>
                    </div>

                    <!-- Interface Errors -->
                    <div data-testid="section-errors">
                        <SectionHeader title="Interface Errors" accent-line />
                        <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
                            <div v-if="metricsAvailable" data-testid="errors-section">
                                <TimeSeriesChart
                                    data-testid="errors-chart"
                                    :series="errorSeries"
                                    y-axis-label="errors/s"
                                    height="160px"
                                    empty-message="No error data for this port"
                                />
                            </div>
                            <p v-else data-testid="errors-fallback" class="text-sm text-[var(--color-text-muted)]">
                                Prometheus not configured
                            </p>
                        </div>
                    </div>

                    <!-- Interface Config -->
                    <div data-testid="section-interface-config">
                        <SectionHeader title="Interface Config" accent-line />
                        <div
                            data-testid="running-config-section"
                            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                        >
                            <ConfigBlock v-if="port.config_text" :code="port.config_text" />
                            <div v-else class="text-sm text-[var(--color-text-muted)]">No running config available</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
