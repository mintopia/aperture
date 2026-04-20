<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import ConnectedDevicesSummary from '@/Components/UI/ConnectedDevicesSummary.vue';
import { formatBytes } from '@/helpers.js';
import { formatRelative } from '@/utils/dates';
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

function deviceUserLinks(mac) {
    return mac.resolved_ips?.filter((resolved) => resolved.user) ?? [];
}

function deviceHasUser(mac) {
    return deviceUserLinks(mac).length > 0;
}

function deviceHasResolvedIps(mac) {
    return (mac.resolved_ips?.length ?? 0) > 0;
}

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
        <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 sm:p-5">
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
            <div
                data-testid="port-context-strip"
                class="mt-4 rounded-xl border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2.5 sm:px-4 sm:py-3"
            >
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
                class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.35fr)]"
            >
                <div data-testid="layout-column-left" class="space-y-6">
                    <!-- Connected Devices -->
                    <div data-testid="section-connected-devices">
                        <div data-testid="connected-devices-section">
                            <SectionHeader :title="`Connected Devices (${macs.length})`" accent-line />
                            <div
                                v-if="macs.length === 0"
                                data-testid="mac-list-empty"
                                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                            >
                                <p class="text-center text-sm text-[var(--color-text-muted)]">No devices connected</p>
                            </div>
                            <div v-else class="space-y-2.5">
                                <div
                                    v-for="(mac, index) in macs"
                                    :key="index"
                                    :data-testid="'connected-device-' + index"
                                    class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 sm:p-5"
                                >
                                    <template v-if="deviceHasUser(mac)">
                                        <Link
                                            v-for="(resolved, rIdx) in deviceUserLinks(mac)"
                                            :key="'user-' + rIdx"
                                            :href="route('admin.users.show', resolved.user.id)"
                                            :data-testid="'device-user-link-' + index"
                                            class="block text-sm font-semibold text-[var(--color-primary)] hover:underline"
                                        >
                                            {{ resolved.user.nickname }}
                                        </Link>
                                    </template>
                                    <p v-else class="font-mono text-sm font-semibold text-[var(--color-text)]">
                                        {{ mac.mac_address }}
                                    </p>

                                    <div v-if="deviceHasResolvedIps(mac)" class="mt-1 space-y-0.5">
                                        <div
                                            v-for="(resolved, rIdx) in mac.resolved_ips"
                                            :key="'ip-' + rIdx"
                                            class="flex items-center gap-2"
                                        >
                                            <Link
                                                v-if="resolved.id"
                                                :href="route('admin.ips.show', resolved.id)"
                                                :data-testid="`device-ip-link-${index}-${rIdx}`"
                                                class="font-mono text-xs text-[var(--color-accent)] hover:underline"
                                            >
                                                {{ resolved.ip }}
                                            </Link>
                                            <span v-else class="font-mono text-xs text-[var(--color-accent)]">{{
                                                resolved.ip
                                            }}</span>
                                            <span class="font-mono text-xs text-[var(--color-text-muted)]">
                                                {{ mac.mac_address }}
                                            </span>
                                        </div>
                                    </div>

                                    <p class="mt-0.5 text-xs text-[var(--color-text-secondary)]">
                                        VLAN {{ mac.vlan ?? '—' }}
                                    </p>

                                    <div class="mt-1.5" :data-testid="'device-status-' + index">
                                        <StatusPill v-if="deviceHasUser(mac)" status="success" label="Allowed" />
                                        <StatusPill
                                            v-else-if="deviceHasResolvedIps(mac)"
                                            status="warning"
                                            label="Unknown Device"
                                        />
                                        <StatusPill v-else status="neutral" label="Infrastructure" />
                                    </div>

                                    <p v-if="mac.last_seen_at" class="mt-1 text-xs text-[var(--color-text-muted)]">
                                        Last seen {{ formatRelative(mac.last_seen_at) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Running Config -->
                    <div data-testid="section-running-config">
                        <SectionHeader title="Running Config" accent-line />
                        <div data-testid="running-config-section">
                            <ConfigBlock v-if="port.config_text" :code="port.config_text" />
                            <div
                                v-else
                                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text-muted)]"
                            >
                                No running config available
                            </div>
                        </div>
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
                    <div data-testid="layout-row-secondary" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                        <!-- Interface Errors -->
                        <div data-testid="section-errors">
                            <SectionHeader title="Interface Errors" accent-line />
                            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
                                <div data-testid="errors-section" class="grid grid-cols-2 gap-4">
                                    <StatCard
                                        label="Input Errors"
                                        :value="errors.input ?? 0"
                                        :color="(errors.input ?? 0) > 0 ? 'danger' : 'text'"
                                    />
                                    <StatCard
                                        label="Output Errors"
                                        :value="errors.output ?? 0"
                                        :color="(errors.output ?? 0) > 0 ? 'danger' : 'text'"
                                    />
                                    <StatCard
                                        label="CRC Errors"
                                        :value="errors.crc ?? 0"
                                        :color="(errors.crc ?? 0) > 0 ? 'warning' : 'text'"
                                    />
                                    <StatCard
                                        label="Collisions"
                                        :value="errors.collisions ?? 0"
                                        :color="(errors.collisions ?? 0) > 0 ? 'warning' : 'text'"
                                    />
                                </div>
                                <p
                                    v-if="
                                        (errors.input ?? 0) === 0 &&
                                        (errors.output ?? 0) === 0 &&
                                        (errors.crc ?? 0) === 0 &&
                                        (errors.collisions ?? 0) === 0
                                    "
                                    class="mt-3 flex items-center gap-1.5 text-xs font-medium text-[var(--color-success)]"
                                >
                                    <span aria-hidden="true">✓</span
                                    ><span data-testid="errors-clean">Clean — no errors detected</span>
                                </p>
                                <div v-if="metricsAvailable" class="mt-4">
                                    <TimeSeriesChart
                                        data-testid="errors-chart"
                                        :series="errorSeries"
                                        y-axis-label="errors/s"
                                        height="160px"
                                        empty-message="No error data for this port"
                                    />
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
                </div>
            </div>
        </div>
    </div>
</template>
