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
const bouncing = ref(false);
const toggling = ref(false);
const showBounceModal = ref(false);
const showShutdownModal = ref(false);

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

function bouncePort() {
    showBounceModal.value = true;
}

function confirmBounce() {
    bouncing.value = true;
    router.post(
        route('admin.switches.ports.bounce', {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                bouncing.value = false;
                showBounceModal.value = false;
            },
        },
    );
}

function togglePort() {
    if (props.port.admin_status === 'up') {
        showShutdownModal.value = true;
        return;
    }

    toggling.value = true;
    router.post(
        route('admin.switches.ports.enable', {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
            },
        },
    );
}

function confirmShutdown() {
    toggling.value = true;
    router.post(
        route('admin.switches.ports.shutdown', {
            switchConfig: props.switchConfig.id,
            portId: props.port.interface,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                toggling.value = false;
                showShutdownModal.value = false;
            },
        },
    );
}
</script>

<template>
    <div>
        <!-- Header -->
        <div class="mb-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                <h1
                    data-testid="page-title"
                    class="font-heading font-mono text-xl font-bold text-[var(--color-text)] sm:text-2xl"
                >
                    {{ port.interface }}
                </h1>
                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <button
                        data-testid="action-refresh"
                        title="Sync this port's data from the switch"
                        :disabled="refreshing"
                        class="rounded-lg border border-[var(--color-border)] px-3.5 py-1.5 text-sm font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:opacity-50"
                        @click="refreshPort"
                    >
                        {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                    </button>
                    <button
                        data-testid="action-bounce"
                        title="Briefly take this port offline and bring it back up"
                        :disabled="bouncing"
                        class="rounded-lg border border-[var(--color-warning)] px-3.5 py-1.5 text-sm font-semibold text-[var(--color-warning)] transition-colors hover:bg-[var(--color-warning)]/10 disabled:opacity-50"
                        @click="bouncePort"
                    >
                        {{ bouncing ? 'Bouncing…' : 'Bounce Port' }}
                    </button>
                    <button
                        data-testid="action-toggle"
                        :title="
                            port.admin_status === 'up'
                                ? 'Administratively disable this port'
                                : 'Administratively enable this port'
                        "
                        :disabled="toggling"
                        :class="
                            port.admin_status === 'up'
                                ? 'bg-[var(--color-danger)] hover:bg-[var(--color-danger)]/80'
                                : 'bg-[var(--color-success)] hover:bg-[var(--color-success)]/80'
                        "
                        class="rounded-lg px-3.5 py-1.5 text-sm font-semibold text-white transition-colors disabled:opacity-50"
                        @click="togglePort"
                    >
                        <template v-if="toggling">
                            {{ port.admin_status === 'up' ? 'Shutting down…' : 'Enabling…' }}
                        </template>
                        <template v-else>
                            {{ port.admin_status === 'up' ? 'Shutdown' : 'Enable' }}
                        </template>
                    </button>
                    <StatusPill
                        data-testid="port-status"
                        :status="statusType(port.status)"
                        :label="formatPortStatus(port.admin_status, port.status)"
                    />
                </div>
            </div>
            <div class="mt-1 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-[var(--color-text-secondary)]">
                    on
                    <Link
                        data-testid="port-switch-link"
                        :href="route('admin.switches.show', switchConfig.id)"
                        class="font-medium hover:text-[var(--color-primary)]"
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
                        class="text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-primary)]"
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
                        class="text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-primary)]"
                    >
                        {{ nextPort }} →
                    </Link>
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="showBounceModal"
            title="Bounce Port?"
            :message="`This will briefly take ${port.interface} offline and bring it back up. Any connected devices will be temporarily disconnected.`"
            confirm-label="Bounce Port"
            variant="warning"
            :loading="bouncing"
            @confirm="confirmBounce"
            @cancel="showBounceModal = false"
        >
            <ConnectedDevicesSummary :macs="macs" />
        </ConfirmModal>

        <ConfirmModal
            :show="showShutdownModal"
            title="Shut Down Port?"
            :message="`This will disable ${port.interface}. All connected devices will lose connectivity.`"
            confirm-label="Shut Down"
            variant="danger"
            :loading="toggling"
            @confirm="confirmShutdown"
            @cancel="showShutdownModal = false"
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

        <p data-testid="last-updated" class="mb-4 text-xs text-[var(--color-text-muted)]">
            Last updated {{ displayTime }}
        </p>

        <!-- Main Content: two columns on desktop -->
        <div class="mt-5 grid gap-6 lg:grid-cols-3">
            <!-- Left Column (2/3) -->
            <div class="lg:col-span-2">
                <!-- Bandwidth -->
                <div>
                    <SectionHeader title="Bandwidth — Last 24 Hours" accent-line />
                    <div
                        data-testid="bandwidth-section"
                        class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                    >
                        <div class="mb-4 flex gap-6">
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
            </div>

            <!-- Right Column (1/3): Connected Devices -->
            <div data-testid="connected-devices-section">
                <SectionHeader :title="`Connected Devices (${macs.length})`" accent-line />
                <div
                    v-if="macs.length === 0"
                    data-testid="mac-list-empty"
                    class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                >
                    <p class="text-center text-sm text-[var(--color-text-muted)]">No devices connected</p>
                </div>
                <div v-else class="space-y-3">
                    <div
                        v-for="(mac, index) in macs"
                        :key="index"
                        :data-testid="'connected-device-' + index"
                        class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
                    >
                        <!-- Device title: user name (linked) or MAC address -->
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

                        <!-- IP + MAC line -->
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

                        <!-- VLAN -->
                        <p class="mt-0.5 text-xs text-[var(--color-text-secondary)]">VLAN {{ mac.vlan ?? '—' }}</p>

                        <!-- Status Badge -->
                        <div class="mt-1.5" :data-testid="'device-status-' + index">
                            <StatusPill v-if="deviceHasUser(mac)" status="success" label="Allowed" />
                            <StatusPill v-else-if="deviceHasResolvedIps(mac)" status="warning" label="Unknown Device" />
                            <StatusPill v-else status="neutral" label="Infrastructure" />
                        </div>

                        <!-- Last seen -->
                        <p v-if="mac.last_seen_at" class="mt-1 text-xs text-[var(--color-text-muted)]">
                            Last seen {{ formatRelative(mac.last_seen_at) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <!-- Interface Errors -->
            <div>
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

            <!-- Running Config -->
            <div>
                <SectionHeader title="Running Config" accent-line />
                <ConfigBlock v-if="port.config_text" data-testid="port-config" :code="port.config_text" />
                <div
                    v-else
                    class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 text-sm text-[var(--color-text-muted)]"
                >
                    No running config available
                </div>
            </div>
        </div>
    </div>
</template>
