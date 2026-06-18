<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import ConnectedDevicesSummary from '@/Components/UI/ConnectedDevicesSummary.vue';
import { formatBytesComponents, normalizeMac } from '@/helpers.js';
import { formatPortStatus, formatSpeed, formatDuplex, formatVlan } from '@/utils/switches';
import { formatRelative } from '@/utils/dates';
import { useAdminChannel } from '@/composables/useAdminChannel';

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
const optimisticAdminStatus = ref(null);

const lastUpdated = ref(new Date());
const displayTime = ref('just now');
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
            optimisticAdminStatus.value = null;
        },
    });
}

function onPortStateChanged(event) {
    if (event.switch_port_id === props.port.id || event.port_name === props.port.interface) {
        refreshData();
    }
}

function onSwitchSyncCompleted(event) {
    if (event.switch_config_id === props.switchConfig.id) {
        refreshData();
    }
}

useAdminChannel({
    events: {
        PortStateChanged: onPortStateChanged,
        SwitchSyncCompleted: onSwitchSyncCompleted,
    },
    poll: refreshData,
    pollInterval: 30000,
});

onMounted(() => {
    displayTimer = setInterval(updateDisplayTime, 5000);
});

onBeforeUnmount(() => {
    if (displayTimer) clearInterval(displayTimer);
});

function getThemeColor(variableName, fallback) {
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
            color: getThemeColor('--color-info', '#3b82f6'),
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
    { label: 'Last Sync', value: props.port.last_synced_at ? formatRelative(props.port.last_synced_at) : 'Never' },
]);

function statusType(status) {
    if (['up', 'connected'].includes(status)) return 'success';
    if (['down', 'err-disabled'].includes(status)) return 'danger';
    if (['disabled', 'notconnect'].includes(status)) return 'neutral';
    return 'warning';
}

const statusColorClass = computed(() => {
    const type = statusType(props.port.status);
    if (type === 'success') return 'text-[var(--color-success)]';
    if (type === 'danger') return 'text-[var(--color-danger)]';
    return 'text-[var(--color-text-muted)]';
});

const statusDotClass = computed(() => {
    const type = statusType(props.port.status);
    if (type === 'success') return 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]';
    if (type === 'danger') return 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]';
    return 'bg-[var(--color-text-muted)]';
});

function isIpv6Address(ip) {
    return typeof ip === 'string' && ip.includes(':');
}

function resolveIpEntries(mac, version) {
    return (mac.resolved_ips ?? []).filter((resolved) => {
        if (!resolved?.ip) return false;
        return version === 'ipv6' ? isIpv6Address(resolved.ip) : !isIpv6Address(resolved.ip);
    });
}

const visibleMacs = computed(() => {
    const seen = new Map();
    for (const mac of props.macs) {
        const key = normalizeMac(mac.mac_address);
        if (seen.has(key)) {
            const existing = seen.get(key);
            const existingIps = existing.resolved_ips ?? [];
            const newIps = (mac.resolved_ips ?? []).filter((ip) => !existingIps.some((e) => e.ip === ip.ip));
            existing.resolved_ips = [...existingIps, ...newIps];
            if (mac.last_seen_at && (!existing.last_seen_at || mac.last_seen_at > existing.last_seen_at)) {
                existing.last_seen_at = mac.last_seen_at;
            }
        } else {
            seen.set(key, { ...mac, mac_address: key });
        }
    }
    return [...seen.values()];
});

function refreshPort() {
    refreshing.value = true;
    router.post(
        route('admin.switches.ports.refresh', {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}

const effectiveAdminStatus = computed(() => optimisticAdminStatus.value ?? props.port.admin_status);
const isAdminUp = computed(() => effectiveAdminStatus.value === 'up');
const toggleLabel = computed(() => (isAdminUp.value ? 'Shut' : 'Unshut'));
const toggleTitle = computed(() =>
    isAdminUp.value ? 'Administratively disable this port' : 'Administratively enable this port',
);
const toggleLoadingLabel = computed(() => (isAdminUp.value ? 'Shutting down…' : 'Enabling…'));
const toggleClass = computed(() =>
    isAdminUp.value
        ? 'rounded-md border border-[var(--color-danger)]/40 px-4 py-[7px] text-[13px] font-semibold text-[var(--color-danger)] transition-colors hover:bg-[var(--color-danger)]/12 hover:border-[var(--color-danger)] focus-visible:ring-2 focus-visible:ring-[var(--color-danger)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none disabled:opacity-50'
        : 'rounded-md border border-[var(--color-success)]/40 px-4 py-[7px] text-[13px] font-semibold text-[var(--color-success)] transition-colors hover:bg-[var(--color-success)]/12 hover:border-[var(--color-success)] focus-visible:ring-2 focus-visible:ring-[var(--color-success)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none disabled:opacity-50',
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
            onSuccess: () => {
                optimisticAdminStatus.value = action === 'shutdown' ? 'down' : 'up';
            },
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
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                {{ port.interface }}
            </h1>
            <div data-testid="header-actions" class="flex flex-wrap items-center gap-2">
                <button
                    data-testid="action-refresh"
                    title="Sync this port's data from the switch"
                    :disabled="refreshing"
                    class="rounded-md border border-[var(--color-border-hover)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-surface)] focus-visible:outline-none disabled:opacity-50"
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

        <!-- Port Navigation -->
        <div v-if="prevPort || nextPort" data-testid="port-nav" class="flex items-center gap-3">
            <Link
                v-if="prevPort"
                data-testid="port-nav-prev"
                :aria-label="`Previous port: ${prevPort}`"
                :href="
                    route('admin.switches.ports.show', {
                        switchConfig: switchConfig.id,
                        portId: prevPort,
                    })
                "
                class="font-mono text-xs text-[var(--color-text-muted)] transition-colors hover:text-[var(--color-text)] focus-visible:underline focus-visible:outline-none"
            >
                &larr; {{ prevPort }}
            </Link>
            <Link
                v-if="nextPort"
                data-testid="port-nav-next"
                :aria-label="`Next port: ${nextPort}`"
                :href="
                    route('admin.switches.ports.show', {
                        switchConfig: switchConfig.id,
                        portId: nextPort,
                    })
                "
                class="font-mono text-xs text-[var(--color-text-muted)] transition-colors hover:text-[var(--color-text)] focus-visible:underline focus-visible:outline-none"
            >
                {{ nextPort }} &rarr;
            </Link>
            <span data-testid="last-updated" class="ml-auto text-xs text-[var(--color-text-muted)]">{{
                displayTime
            }}</span>
        </div>
        <span v-else data-testid="last-updated" class="text-xs text-[var(--color-text-muted)]">{{ displayTime }}</span>

        <!-- Status Strip -->
        <MetadataStrip :items="metadataItems">
            <template #Status>
                <span class="inline-flex items-center gap-1.5 font-semibold">
                    <span :class="statusDotClass" class="inline-block h-[7px] w-[7px] rounded-full" />
                    <span :class="statusColorClass">{{ formatPortStatus(port.admin_status, port.status) }}</span>
                </span>
            </template>
        </MetadataStrip>

        <div data-testid="layout-columns" class="grid grid-cols-1 gap-10 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <div data-testid="layout-column-left" class="space-y-8">
                <!-- Connected Devices -->
                <div data-testid="section-connected-devices">
                    <div data-testid="connected-devices-section">
                        <SectionHeader :title="`Connected Devices (${visibleMacs.length})`" />
                        <div v-if="visibleMacs.length === 0" data-testid="mac-list-empty" class="py-8">
                            <p class="text-center text-[13px] text-[var(--color-text-muted)]">No devices to display</p>
                        </div>
                        <div v-else data-testid="connected-devices-table-wrapper" class="overflow-x-auto">
                            <table
                                data-testid="connected-devices-table"
                                class="min-w-full divide-y divide-[var(--color-border)]"
                            >
                                <thead>
                                    <tr class="border-b border-[var(--color-border-hover)]">
                                        <th
                                            class="py-2 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                        >
                                            MAC Address
                                        </th>
                                        <th
                                            class="py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                        >
                                            IPv4
                                        </th>
                                        <th
                                            class="py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                        >
                                            IPv6
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="text-[13px]">
                                    <tr
                                        v-for="(mac, index) in visibleMacs"
                                        :key="index"
                                        :data-testid="'connected-device-row-' + index"
                                        class="border-b border-[var(--color-border)] last:border-b-0"
                                    >
                                        <td class="py-2.5 font-mono text-[13px] text-[var(--color-text)]">
                                            <Link
                                                v-if="mac.mac_id"
                                                :href="route('admin.macs.show', mac.mac_address)"
                                                class="font-mono hover:underline"
                                            >
                                                {{ normalizeMac(mac.mac_address) }}
                                            </Link>
                                            <span v-else class="font-mono">{{ normalizeMac(mac.mac_address) }}</span>
                                        </td>
                                        <td class="py-2.5 pl-6 break-all">
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
                                                        class="font-mono text-[13px] break-all text-[var(--color-accent)] hover:underline"
                                                    >
                                                        {{ resolved.ip }}
                                                    </Link>
                                                </template>
                                            </div>
                                            <span v-else class="text-[13px] text-[var(--color-text-muted)]"
                                                >&mdash;</span
                                            >
                                        </td>
                                        <td class="py-2.5 pl-6 break-all">
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
                                                        class="font-mono text-[13px] break-all text-[var(--color-accent)] hover:underline"
                                                    >
                                                        {{ resolved.ip }}
                                                    </Link>
                                                </template>
                                            </div>
                                            <span v-else class="text-[13px] text-[var(--color-text-muted)]"
                                                >&mdash;</span
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Interface Output -->
                <div data-testid="section-interface-output">
                    <SectionHeader title="Interface Output" />
                    <div data-testid="interface-output-section">
                        <ConfigBlock v-if="port.interface_output" :code="port.interface_output" />
                        <div v-else class="text-sm text-[var(--color-text-muted)]">No interface output available</div>
                    </div>
                </div>
            </div>

            <div data-testid="layout-column-right" class="space-y-8">
                <!-- Bandwidth -->
                <div data-testid="section-bandwidth">
                    <SectionHeader title="Bandwidth — Last 24h" />
                    <div data-testid="bandwidth-section">
                        <div class="mb-4 flex items-baseline gap-6">
                            <div class="flex items-baseline gap-1.5">
                                <span
                                    class="text-[10px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                                    >In</span
                                >
                                <span
                                    class="font-heading text-[22px] font-bold tracking-[-0.02em] text-[var(--color-success)]"
                                    :style="{ fontVariationSettings: '\'opsz\' 32' }"
                                    >{{ formatBytesComponents(bandwidth.in_bytes ?? 0).value }}</span
                                >
                                <span class="text-[11px] font-semibold text-[var(--color-text-muted)]">{{
                                    formatBytesComponents(bandwidth.in_bytes ?? 0).unit
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
                                    >{{ formatBytesComponents(bandwidth.out_bytes ?? 0).value }}</span
                                >
                                <span class="text-[11px] font-semibold text-[var(--color-text-muted)]">{{
                                    formatBytesComponents(bandwidth.out_bytes ?? 0).unit
                                }}</span>
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
                    <SectionHeader title="Interface Errors — Last 24h" />
                    <div>
                        <div v-if="metricsAvailable" data-testid="errors-section">
                            <TimeSeriesChart
                                data-testid="errors-chart"
                                :series="errorSeries"
                                y-axis-label="errors/s"
                                height="160px"
                                empty-message="No error data for this port"
                            />
                        </div>
                        <p v-else data-testid="errors-fallback" class="text-[13px] text-[var(--color-text-muted)]">
                            Prometheus not configured
                        </p>
                    </div>
                </div>

                <!-- Interface Config -->
                <div data-testid="section-interface-config">
                    <SectionHeader title="Running Config" />
                    <div data-testid="running-config-section">
                        <ConfigBlock v-if="port.config_text" :code="port.config_text" />
                        <div v-else class="text-[13px] text-[var(--color-text-muted)]">No running config available</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
