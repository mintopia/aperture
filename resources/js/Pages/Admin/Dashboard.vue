<script setup>
import { computed, ref, watch } from 'vue';
import { Deferred, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import DhcpPoolsCard from '@/Components/Admin/DhcpPoolsCard.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import EventFeed from '@/Components/Admin/EventFeed.vue';
import { formatBytes } from '@/helpers.js';
import { formatRelativeTime } from '@/utils/dates';
import { useAdminChannel } from '@/composables/useAdminChannel';
import { useCountUp } from '@/composables/useCountUp';
import { useBandwidthChart } from '@/composables/useBandwidthChart.js';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    totalUsers: { type: Number, default: 0 },
    onlineUsers: { type: Number, default: 0 },
    activeIps: { type: Number, default: 0 },
    blockedUsers: { type: Number, default: 0 },
    dhcpPools: { type: Array, default: undefined },
    recentUsers: { type: Object, default: undefined },
    recentEvents: { type: Array, default: undefined },
});

const onlinePercentage = computed(() => {
    if (!props.totalUsers) return 0;

    return Math.round((props.onlineUsers / props.totalUsers) * 100);
});

const statsRef = ref(null);
const animatedOnline = useCountUp(() => props.onlineUsers, statsRef);
const animatedTotal = useCountUp(() => props.totalUsers, statsRef, { delay: 60 });
const animatedActive = useCountUp(() => props.activeIps, statsRef, { delay: 120 });
const animatedBlocked = useCountUp(() => props.blockedUsers, statsRef, { delay: 180 });

const recentUserColumns = [
    { key: 'nickname', label: 'Nickname' },
    { key: 'email', label: 'Email' },
    { key: 'ips_count', label: 'IPs' },
    { key: 'down', label: 'Down' },
    { key: 'up', label: 'Up' },
    { key: 'status', label: 'Status' },
    { key: 'seen', label: 'Seen' },
];

const recentUserRows = computed(() => props.recentUsers?.data ?? []);

function userHref(id) {
    return route('admin.users.show', id);
}

const ranges = [
    { value: '1h', label: '1H' },
    { value: '24h', label: '24H' },
    { value: '4d', label: '4D' },
    { value: '7d', label: '7D' },
];

const { selectedRange, bandwidthData, bandwidthLoading, bandwidthError, chartSeries, selectRange, fetchBandwidth } =
    useBandwidthChart(route('admin.dashboard.bandwidth'), '1h', 0);

function refreshDashboard() {
    fetchBandwidth();
    router.reload({
        only: ['totalUsers', 'onlineUsers', 'activeIps', 'blockedUsers', 'dhcpPools', 'recentUsers'],
        preserveScroll: true,
    });
}

const eventFeedItems = ref([]);

const EVENT_FORMATTERS = {
    UserConnected: (data) => `${data.user_name} connected from ${data.ip_address}`,
    DeviceDiscovered: (data) => {
        const location = data.ip_address ? ` on ${data.ip_address}` : '';
        return `New device ${data.mac_address} discovered${location}`;
    },
    PortStateChanged: (data) => `Port ${data.port_name} changed to ${data.new_status}`,
    SwitchSyncCompleted: (data) => {
        const base = `Switch ${data.hostname} sync completed (${data.ports_updated} ports updated)`;
        return data.errors?.length ? `${base} - ${data.errors.length} errors` : base;
    },
    DhcpPoolThresholdReached: (data) => `DHCP pool ${data.pool} reached ${data.usage}% utilisation`,
    InternetAccessChanged: (data) => `Internet access ${data.enabled ? 'enabled' : 'disabled'} for ${data.ip_address}`,
    RateLimitChanged: (data) => `Rate limit changed for ${data.ip_address} from ${data.old_limit} to ${data.new_limit}`,
    UserBlocked: (data) => `${data.user_name} blocked on ${data.ip_address}: ${data.reason}`,
    DnsFilterChanged: (data) => `DNS filter ${data.enabled ? 'enabled' : 'disabled'} for ${data.ip_address}`,
    SwitchUnreachable: (data) => `Switch ${data.hostname} unreachable after ${data.failure_count} failures`,
    BandwidthAnomalyDetected: (data) => {
        const parts = ['Bandwidth anomaly detected'];
        if (data.ip_address) parts.push(`on ${data.ip_address}`);
        return parts.join(' ');
    },
};

function addEventFeedItem(type, data) {
    const entry = {
        id: Date.now() + Math.random(),
        type,
        message: EVENT_FORMATTERS[type]?.(data) ?? `${type} event received`,
        created_at: new Date().toISOString(),
    };
    eventFeedItems.value = [entry, ...eventFeedItems.value].slice(0, 50);
}

function handleBroadcastEvent(eventType) {
    return (data) => {
        refreshDashboard();
        addEventFeedItem(eventType, data);
    };
}

useAdminChannel({
    events: {
        UserConnected: handleBroadcastEvent('UserConnected'),
        DeviceDiscovered: handleBroadcastEvent('DeviceDiscovered'),
        DhcpPoolThresholdReached: handleBroadcastEvent('DhcpPoolThresholdReached'),
        PortStateChanged: handleBroadcastEvent('PortStateChanged'),
        SwitchSyncCompleted: handleBroadcastEvent('SwitchSyncCompleted'),
        InternetAccessChanged: handleBroadcastEvent('InternetAccessChanged'),
        RateLimitChanged: handleBroadcastEvent('RateLimitChanged'),
        UserBlocked: handleBroadcastEvent('UserBlocked'),
        DnsFilterChanged: handleBroadcastEvent('DnsFilterChanged'),
        SwitchUnreachable: handleBroadcastEvent('SwitchUnreachable'),
        BandwidthAnomalyDetected: handleBroadcastEvent('BandwidthAnomalyDetected'),
    },
    poll: fetchBandwidth,
    pollInterval: 30000,
});

watch(
    () => props.recentEvents,
    (newEvents) => {
        if (newEvents && eventFeedItems.value.length === 0) {
            eventFeedItems.value = [...newEvents];
        }
    },
    { immediate: true },
);

const showResetModal = ref(false);
const resetForm = useForm({ password: '' });

function openResetModal() {
    resetForm.reset();
    resetForm.clearErrors();
    showResetModal.value = true;
}

function cancelReset() {
    showResetModal.value = false;
    resetForm.reset();
    resetForm.clearErrors();
}

function confirmReset() {
    if (resetForm.processing) return;

    resetForm.post(route('admin.reset'), {
        onSuccess: () => {
            showResetModal.value = false;
            resetForm.reset();
        },
        preserveScroll: true,
    });
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Dashboard
            </h1>
            <button
                data-testid="reset-portal-button"
                type="button"
                class="rounded-md border border-[var(--color-danger)]/40 px-4 py-2 text-[13px] font-semibold text-[var(--color-danger)] transition-colors hover:bg-[var(--color-danger)]/12"
                @click="openResetModal"
            >
                Reset Portal
            </button>
        </div>

        <!-- Stat Strip -->
        <div
            ref="statsRef"
            data-testid="dashboard-stats"
            class="mt-6 mb-7 flex flex-wrap gap-y-4 border-b border-[var(--color-border)] pb-5"
        >
            <div
                class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-full max-sm:border-0 max-sm:pr-0"
            >
                <StatCard label="Online Now" :value="animatedOnline" color="success" label-dot-color="success">
                    <p class="mt-[2px] font-mono text-[11px] text-[var(--color-text-muted)]">
                        of {{ animatedTotal }} &middot;
                        <span class="text-[var(--color-success)]">{{ onlinePercentage }}%</span>
                    </p>
                </StatCard>
            </div>

            <div
                class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-1/3 max-sm:border-0 max-sm:pr-0"
            >
                <StatCard label="Total Users" :value="animatedTotal" />
            </div>

            <div
                class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-1/3 max-sm:border-0 max-sm:pr-0"
            >
                <StatCard label="IPs Active" :value="animatedActive" />
            </div>

            <div class="flex-1 max-sm:basis-1/3">
                <StatCard label="Blocked" :value="animatedBlocked" color="danger" />
            </div>
        </div>

        <!-- Two Column: DHCP Pools + Total Bandwidth Chart -->
        <div class="mb-10 grid grid-cols-1 gap-6 md:grid-cols-[2fr_3fr]">
            <Deferred data="dhcpPools">
                <template #fallback>
                    <div data-testid="dhcp-pools-loading">
                        <SectionHeader title="DHCP Pools" />
                        <div class="space-y-3">
                            <div
                                v-for="i in 3"
                                :key="i"
                                class="h-5 animate-pulse rounded-lg bg-[var(--color-surface-hover)]"
                            />
                        </div>
                    </div>
                </template>

                <DhcpPoolsCard :pools="dhcpPools ?? []" />
            </Deferred>

            <div data-testid="total-bandwidth-section">
                <div class="flex items-baseline justify-between">
                    <SectionHeader title="Total Bandwidth" />
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
                                :key="r.value"
                                type="button"
                                :data-testid="'bandwidth-range-' + r.value"
                                :class="[
                                    'rounded px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase transition-all',
                                    selectedRange === r.value
                                        ? 'bg-[var(--color-accent-dim)] text-[var(--color-primary)]'
                                        : 'text-[var(--color-text-muted)] hover:text-[var(--color-text-secondary)]',
                                ]"
                                @click="selectRange(r.value)"
                            >
                                {{ r.label }}
                            </button>
                        </div>
                    </div>
                </div>
                <TimeSeriesChart
                    :series="chartSeries"
                    :loading="bandwidthLoading"
                    y-axis-label="bps"
                    height="200px"
                    empty-message="No bandwidth data available"
                    data-testid="bandwidth-chart"
                />
                <p
                    v-if="bandwidthError"
                    role="status"
                    class="mt-2 text-[12px] text-[var(--color-danger)]"
                    data-testid="bandwidth-error"
                >
                    Failed to load bandwidth data
                </p>
            </div>
        </div>

        <!-- Recent Users Table -->
        <Deferred data="recentUsers">
            <template #fallback>
                <div data-testid="recent-users-loading">
                    <SectionHeader title="Top Bandwidth &amp; Recent Users" />
                    <div class="space-y-3">
                        <div
                            v-for="i in 5"
                            :key="i"
                            class="h-10 animate-pulse rounded-lg bg-[var(--color-surface-hover)]"
                        />
                    </div>
                </div>
            </template>

            <section data-testid="recent-users-section">
                <SectionHeader title="Top Bandwidth &amp; Recent Users" />

                <div v-if="recentUserRows.length" class="space-y-4">
                    <DataTable :columns="recentUserColumns" :rows="recentUserRows">
                        <template #row="{ row }">
                            <td data-testid="user-nickname" class="py-[10px] text-[13px]">
                                <Link
                                    :href="userHref(row.id)"
                                    class="font-medium text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                                >
                                    {{ row.nickname }}
                                </Link>
                            </td>
                            <td
                                data-testid="user-email"
                                class="py-[10px] text-[13px] text-[var(--color-text-secondary)]"
                            >
                                {{ row.email }}
                            </td>
                            <td
                                data-testid="user-ips-count"
                                class="py-[10px] font-mono text-[13px] text-[var(--color-text-secondary)]"
                            >
                                {{ row.ips_count ?? 0 }}
                            </td>
                            <td
                                data-testid="user-down"
                                class="py-[10px] font-mono text-[13px] text-[var(--color-success)]"
                            >
                                {{ formatBytes(row.weekly_received ?? 0) }}
                            </td>
                            <td data-testid="user-up" class="py-[10px] font-mono text-[13px] text-[var(--color-info)]">
                                {{ formatBytes(row.weekly_sent ?? 0) }}
                            </td>
                            <td data-testid="user-status" class="py-[10px]">
                                <span class="inline-flex items-center gap-1.5">
                                    <span
                                        class="h-[7px] w-[7px] rounded-full"
                                        :class="
                                            row.internet_blocked
                                                ? 'bg-[var(--color-danger)] shadow-[0_0_6px_var(--color-danger)]'
                                                : 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                        "
                                    />
                                    <span
                                        class="text-[12px] font-semibold"
                                        :class="
                                            row.internet_blocked
                                                ? 'text-[var(--color-danger)]'
                                                : 'text-[var(--color-success)]'
                                        "
                                    >
                                        {{ row.internet_blocked ? 'Denied' : 'Allowed' }}
                                    </span>
                                </span>
                            </td>
                            <td
                                data-testid="user-last-seen"
                                class="py-[10px] font-mono text-[12px] text-[var(--color-text-muted)]"
                            >
                                {{ formatRelativeTime(row.last_seen) }}
                            </td>
                        </template>
                    </DataTable>

                    <Pagination :paginator="recentUsers" />
                </div>

                <EmptyState
                    v-else
                    title="No recent users"
                    description="Recent user activity and bandwidth usage will appear here."
                />
            </section>
        </Deferred>

        <!-- Live Event Feed -->
        <div class="mt-10">
            <EventFeed :events="eventFeedItems" data-testid="event-feed" />
        </div>

        <!-- Reset Portal Confirmation Modal -->
        <ConfirmModal
            :show="showResetModal"
            title="Reset Portal"
            message="This will wipe all portal data including users, IP addresses, and bandwidth records. This action cannot be undone. Enter your password to confirm."
            confirm-label="Reset Portal"
            variant="danger"
            :loading="resetForm.processing"
            @cancel="cancelReset"
            @confirm="confirmReset"
        >
            <div class="mt-4">
                <label for="reset-password" class="block text-[12px] font-semibold text-[var(--color-text-secondary)]">
                    Password
                </label>
                <input
                    id="reset-password"
                    v-model="resetForm.password"
                    data-testid="reset-password-input"
                    type="password"
                    placeholder="Enter your password"
                    :aria-invalid="resetForm.errors.password ? 'true' : undefined"
                    :aria-describedby="resetForm.errors.password ? 'reset-password-error' : undefined"
                    class="mt-1 w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-[13px] text-[var(--color-text)] placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)] focus:ring-1 focus:ring-[var(--color-primary)] focus:outline-none"
                    @keydown.enter="confirmReset"
                />
                <p
                    v-if="resetForm.errors.password"
                    id="reset-password-error"
                    data-testid="reset-password-error"
                    role="alert"
                    class="mt-1 text-[12px] text-[var(--color-danger)]"
                >
                    {{ resetForm.errors.password }}
                </p>
            </div>
        </ConfirmModal>
    </div>
</template>
