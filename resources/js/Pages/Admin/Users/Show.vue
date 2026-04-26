<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import { formatBytes } from '@/helpers.js';
import { formatRelative } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, default: () => ({}) },
    roles: { type: Array, default: () => [] },
    networkDevices: { type: Array, default: () => [] },
    allInternetEnabled: { type: Boolean, default: false },
    allRateLimited: { type: Boolean, default: false },
    ipCount: { type: Number, default: 0 },
    auditLogs: { type: Array, default: () => [] },
});

const showBlockModal = ref(false);
const blocking = ref(false);
const showInternetModal = ref(false);
const togglingInternet = ref(false);
const showRateLimitModal = ref(false);
const togglingRateLimit = ref(false);

function toggleBlock() {
    showBlockModal.value = true;
}

function confirmBlock() {
    blocking.value = true;
    router.post(
        route('admin.users.block', props.user.id),
        { block: props.user.internet_blocked ? 0 : 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                blocking.value = false;
                showBlockModal.value = false;
            },
        },
    );
}

function toggleInternet() {
    showInternetModal.value = true;
}

function confirmToggleInternet() {
    togglingInternet.value = true;
    router.post(
        route('admin.users.internet', props.user.id),
        { enable: props.allInternetEnabled ? 0 : 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingInternet.value = false;
                showInternetModal.value = false;
            },
        },
    );
}

function toggleRateLimit() {
    showRateLimitModal.value = true;
}

function confirmToggleRateLimit() {
    togglingRateLimit.value = true;
    router.post(
        route('admin.users.limit', props.user.id),
        { limit: props.allRateLimited ? 0 : 1 },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingRateLimit.value = false;
                showRateLimitModal.value = false;
            },
        },
    );
}

const deviceColumns = [
    { key: 'mac_address', label: 'MAC' },
    { key: 'ip_address', label: 'IP' },
    { key: 'hostname', label: 'Hostname' },
    { key: 'switch_port', label: 'Switch / Port' },
    { key: 'internet', label: 'Internet' },
    { key: 'rate_limit', label: 'Rate Limit' },
    { key: 'last_seen_at', label: 'Last Seen' },
];

const auditColumns = [
    { key: 'action', label: 'Action' },
    { key: 'process', label: 'Process' },
    { key: 'timestamp', label: 'Timestamp' },
];

const selectedRange = ref('24h');
const bandwidthData = ref({ timestamps: [], download: [], upload: [], totalReceived: 0, totalSent: 0 });
const bandwidthLoading = ref(true);
const bandwidthError = ref(false);
const ranges = ['1h', '24h', '4d'];

const chartSeries = computed(() => {
    const { timestamps, download, upload } = bandwidthData.value;
    if (!timestamps.length) return [];
    return [
        {
            label: 'Download',
            color: 'var(--color-success)',
            fill: true,
            data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: download[i] ?? 0 })),
        },
        {
            label: 'Upload',
            color: 'var(--color-info)',
            fill: true,
            data: timestamps.map((ts, i) => ({ timestamp: Number(ts), value: upload[i] ?? 0 })),
        },
    ];
});

async function fetchBandwidth() {
    bandwidthLoading.value = true;
    bandwidthError.value = false;
    try {
        const response = await fetch(route('admin.users.bandwidth', props.user.id) + '?range=' + selectedRange.value);
        if (response.ok) {
            bandwidthData.value = await response.json();
        } else {
            bandwidthError.value = true;
        }
    } catch (_e) {
        bandwidthError.value = true;
    } finally {
        bandwidthLoading.value = false;
    }
}

function selectRange(range) {
    selectedRange.value = range;
    fetchBandwidth();
}

let bandwidthPoll = null;

onMounted(() => {
    if (props.ipCount > 0) {
        fetchBandwidth();
        bandwidthPoll = setInterval(fetchBandwidth, 30000);
    } else {
        bandwidthLoading.value = false;
    }
});

onUnmounted(() => {
    if (bandwidthPoll) clearInterval(bandwidthPoll);
});
</script>

<template>
    <div data-testid="user-show-layout" class="space-y-6">
        <!-- Header -->
        <header class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    {{ user.nickname }}
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">User account details and IP history.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Link
                    :href="route('admin.users.edit', user.id)"
                    data-testid="action-edit"
                    class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                >
                    Edit
                </Link>
                <button
                    :data-testid="user.internet_blocked ? 'action-unblock' : 'action-block'"
                    :class="
                        user.internet_blocked
                            ? 'border-[var(--color-success)] bg-[var(--color-success)]'
                            : 'border-[var(--color-danger)] bg-[var(--color-danger)]'
                    "
                    class="rounded-md border px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition-colors hover:opacity-90"
                    @click="toggleBlock"
                >
                    {{ user.internet_blocked ? 'Unblock' : 'Block' }}
                </button>
                <button
                    v-if="ipCount > 0"
                    :data-testid="allInternetEnabled ? 'action-disable-internet' : 'action-enable-internet'"
                    :class="
                        allInternetEnabled
                            ? 'border-[var(--color-danger)] bg-[var(--color-danger)]'
                            : 'border-[var(--color-success)] bg-[var(--color-success)]'
                    "
                    class="rounded-md border px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition-colors hover:opacity-90"
                    @click="toggleInternet"
                >
                    {{ allInternetEnabled ? 'Disable Internet' : 'Enable Internet' }}
                </button>
                <button
                    v-if="ipCount > 0"
                    :data-testid="allRateLimited ? 'action-disable-rate-limit' : 'action-enable-rate-limit'"
                    class="rounded-md border border-[var(--color-border)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] hover:border-[var(--color-border-hover)] hover:text-[var(--color-text)]"
                    @click="toggleRateLimit"
                >
                    {{ allRateLimited ? 'Remove Rate Limit' : 'Rate Limit' }}
                </button>
            </div>
        </header>

        <!-- Block Confirm -->
        <ConfirmModal
            :show="showBlockModal"
            :title="user.internet_blocked ? 'Unblock User?' : 'Block User?'"
            :message="
                user.internet_blocked
                    ? 'This will restore internet access for this user and their associated IPs.'
                    : 'This will deny internet access for this user and their associated IPs.'
            "
            :confirm-label="user.internet_blocked ? 'Unblock' : 'Block'"
            :variant="user.internet_blocked ? 'primary' : 'danger'"
            :loading="blocking"
            @confirm="confirmBlock"
            @cancel="showBlockModal = false"
        >
            <p class="mt-2 text-[13px] text-[var(--color-text-secondary)]">
                This user has {{ ipCount }} associated IP(s).
            </p>
        </ConfirmModal>

        <!-- Internet Confirm -->
        <ConfirmModal
            :show="showInternetModal"
            :title="allInternetEnabled ? 'Disable Internet?' : 'Enable Internet?'"
            :message="
                allInternetEnabled
                    ? `This will disable internet for all ${ipCount} IP(s) associated with this user.`
                    : `This will enable internet for all ${ipCount} IP(s) associated with this user.`
            "
            :confirm-label="allInternetEnabled ? 'Disable Internet' : 'Enable Internet'"
            :variant="allInternetEnabled ? 'danger' : 'primary'"
            :loading="togglingInternet"
            @confirm="confirmToggleInternet"
            @cancel="showInternetModal = false"
        />

        <!-- Rate Limit Confirm -->
        <ConfirmModal
            :show="showRateLimitModal"
            :title="allRateLimited ? 'Remove Rate Limit?' : 'Apply Rate Limit?'"
            :message="
                allRateLimited
                    ? `This will remove rate limiting from all ${ipCount} IP(s) associated with this user.`
                    : `This will rate limit all ${ipCount} IP(s) associated with this user.`
            "
            :confirm-label="allRateLimited ? 'Remove Rate Limit' : 'Rate Limit'"
            :variant="allRateLimited ? 'primary' : 'danger'"
            :loading="togglingRateLimit"
            @confirm="confirmToggleRateLimit"
            @cancel="showRateLimitModal = false"
        />

        <MetadataStrip
            :items="[
                { label: 'Email', value: user.email },
                { label: 'Roles', value: roles.map((r) => r.name).join(', ') || 'None' },
            ]"
        />

        <!-- Bandwidth Chart -->
        <section data-testid="user-bandwidth-section">
            <div class="flex items-baseline justify-between">
                <SectionHeader title="Bandwidth" class="mt-5" />
                <div class="flex items-center gap-3">
                    <div class="flex items-baseline gap-4">
                        <div data-testid="bandwidth-download">
                            <span
                                class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                            >
                                Down
                            </span>
                            <span class="ml-1 font-mono text-sm font-bold text-[var(--color-success)]">
                                {{ formatBytes(bandwidthData.totalReceived) }}
                            </span>
                        </div>
                        <div data-testid="bandwidth-upload">
                            <span
                                class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                            >
                                Up
                            </span>
                            <span class="ml-1 font-mono text-sm font-bold text-[var(--color-info)]">
                                {{ formatBytes(bandwidthData.totalSent) }}
                            </span>
                        </div>
                    </div>
                    <div class="flex gap-1" data-testid="bandwidth-range-selector">
                        <button
                            v-for="r in ranges"
                            :key="r"
                            type="button"
                            :data-testid="'bandwidth-range-' + r"
                            :class="[
                                'rounded px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase transition-all',
                                selectedRange === r
                                    ? 'bg-[var(--color-accent-dim)] text-[var(--color-primary)]'
                                    : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)]',
                            ]"
                            @click="selectRange(r)"
                        >
                            {{ r === '4d' ? '72H' : r.toUpperCase() }}
                        </button>
                    </div>
                </div>
            </div>
            <TimeSeriesChart
                :series="chartSeries"
                :loading="bandwidthLoading"
                y-axis-label="bps"
                height="200px"
                :empty-message="ipCount === 0 ? 'No IPs associated with this user' : 'No bandwidth data available'"
                data-testid="bandwidth-chart"
            />
        </section>

        <!-- Converged Network Devices Table -->
        <section data-testid="user-devices-section">
            <SectionHeader title="Network Devices" class="mt-5" />

            <DataTable :columns="deviceColumns" :rows="networkDevices" empty-message="No network devices associated.">
                <template #row="{ row }">
                    <td data-testid="device-mac" class="font-mono text-[13px]">
                        <Link
                            v-if="row.mac_address"
                            :href="route('admin.macs.show', row.mac_address)"
                            class="text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                        >
                            {{ row.mac_address }}
                        </Link>
                        <span v-else class="text-[var(--color-text-muted)]">&mdash;</span>
                    </td>
                    <td data-testid="device-ip" class="font-mono text-[13px]">
                        <Link
                            v-if="row.ip_address"
                            :href="route('admin.ips.show', row.ip_address)"
                            class="text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                        >
                            {{ row.ip_address }}
                        </Link>
                        <span v-else class="text-[var(--color-text-muted)]">&mdash;</span>
                    </td>
                    <td data-testid="device-hostname" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.hostname ?? '—' }}
                    </td>
                    <td data-testid="device-switch-port" class="text-[13px]">
                        <template v-if="row.switch_name && row.port_name">
                            <Link
                                :href="route('admin.switches.ports.show', [row.switch_id, row.port_name])"
                                class="text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                            >
                                {{ row.switch_name }} / {{ row.port_name }}
                            </Link>
                        </template>
                        <span v-else class="text-[var(--color-text-muted)]">&mdash;</span>
                    </td>
                    <td data-testid="device-internet">
                        <template v-if="row.internet_enabled !== null">
                            <span class="inline-flex items-center gap-1.5">
                                <span
                                    class="h-[7px] w-[7px] rounded-full"
                                    :class="
                                        row.internet_enabled
                                            ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                            : 'bg-[var(--color-text-muted)] shadow-none'
                                    "
                                />
                                <span
                                    class="text-[12px] font-semibold"
                                    :class="
                                        row.internet_enabled
                                            ? 'text-[var(--color-success)]'
                                            : 'text-[var(--color-text-muted)]'
                                    "
                                >
                                    {{ row.internet_enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </span>
                        </template>
                        <span v-else class="text-[var(--color-text-muted)]">&mdash;</span>
                    </td>
                    <td data-testid="device-rate-limit">
                        <template v-if="row.rate_limit_enabled !== null">
                            <span
                                class="text-[12px] font-semibold"
                                :class="
                                    row.rate_limit_enabled
                                        ? 'text-[var(--color-warning)]'
                                        : 'text-[var(--color-text-muted)]'
                                "
                            >
                                {{ row.rate_limit_enabled ? 'Limited' : 'None' }}
                            </span>
                        </template>
                        <span v-else class="text-[var(--color-text-muted)]">&mdash;</span>
                    </td>
                    <td data-testid="device-last-seen" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.last_seen_at ? formatRelative(row.last_seen_at) : '—' }}
                    </td>
                </template>
            </DataTable>
        </section>

        <section data-testid="user-audit-section">
            <SectionHeader title="Audit Log" class="mt-5" />
            <DataTable :columns="auditColumns" :rows="auditLogs" empty-message="No audit entries.">
                <template #row="{ row }">
                    <td data-testid="audit-action" class="font-mono text-[13px] text-[var(--color-text)]">
                        {{ row.action }}
                    </td>
                    <td data-testid="audit-process" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ row.process }}
                    </td>
                    <td data-testid="audit-timestamp" class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ formatRelative(row.created_at) }}
                    </td>
                </template>
            </DataTable>
        </section>
    </div>
</template>
