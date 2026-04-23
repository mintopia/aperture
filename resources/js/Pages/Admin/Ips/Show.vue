<script setup>
import { ref, onMounted, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import MetadataStrip from '@/Components/UI/MetadataStrip.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import { formatRelative } from '@/utils/dates';
import { formatBytes } from '@/helpers.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    ip: { type: Object, default: () => ({}) },
    port: { type: Object, default: () => ({}) },
    switchInfo: { type: Object, default: null },
    users: { type: Array, default: () => [] },
});

const showAccessModal = ref(false);
const togglingAccess = ref(false);

function toggleInternet() {
    showAccessModal.value = true;
}

function confirmToggleInternet() {
    togglingAccess.value = true;
    router.post(
        route('admin.ips.internet', props.ip.id),
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
        route('admin.ips.limit', props.ip.id),
        { limit: props.ip.rate_limit_enabled ? 0 : 1 },
        { preserveScroll: true },
    );
}

function toggleDnsFilter() {
    router.post(
        route('admin.ips.dns-filter', props.ip.id),
        { filter: props.ip.dns_filtering_enabled ? 0 : 1 },
        { preserveScroll: true },
    );
}

const userColumns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'last_seen', label: 'Last Seen' },
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
        const response = await fetch(route('admin.ips.bandwidth', props.ip.address) + '?range=' + selectedRange.value);
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

const statusValue = computed(() => {
    if (props.ip.internet_enabled) return 'Allowed';
    if (props.users?.some((u) => u.user?.internet_enabled === false)) return 'Denied';
    return '\u2014';
});

onMounted(() => {
    fetchBandwidth();
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
                    : 'This will restore internet access for this IP address.'
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
                { label: 'Status', value: statusValue },
                { label: 'MAC Address', value: ip.mac || '\u2014' },
                { label: 'Rate Limiting', value: ip.rate_limit_enabled ? 'Enabled' : '\u2014' },
                { label: 'DNS Filtering', value: ip.dns_filtering_enabled ? 'Enabled' : '\u2014' },
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

            <!-- Right: Bandwidth -->
            <div>
                <div class="flex items-center justify-between">
                    <SectionHeader title="Bandwidth" />
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
                        <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                            >Down</span
                        >
                        <span class="ml-1 font-mono text-sm font-bold text-[var(--color-success)]">
                            {{ formatBytes(bandwidthData.totalReceived) }}
                        </span>
                    </div>
                    <div data-testid="bandwidth-upload">
                        <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
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
        </div>
    </div>
</template>
