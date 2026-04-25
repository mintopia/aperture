<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { Deferred, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DhcpPoolsCard from '@/Components/Admin/DhcpPoolsCard.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import TimeSeriesChart from '@/Components/UI/TimeSeriesChart.vue';
import { formatBytes } from '@/helpers.js';
import { formatRelativeTime } from '@/utils/dates';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    totalUsers: { type: Number, default: 0 },
    onlineUsers: { type: Number, default: 0 },
    activeIps: { type: Number, default: 0 },
    blockedUsers: { type: Number, default: 0 },
    dhcpPools: { type: Array, default: undefined },
    recentUsers: { type: Object, default: undefined },
});

const onlinePercentage = computed(() => {
    if (!props.totalUsers) return 0;

    return Math.round((props.onlineUsers / props.totalUsers) * 100);
});

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
    { value: '4d', label: '72H' },
];

const selectedRange = ref('1h');

const bandwidthData = ref({
    timestamps: [],
    download: [],
    upload: [],
    totalReceived: 0,
    totalSent: 0,
});

const bandwidthLoading = ref(true);

const chartSeries = computed(() => {
    const { timestamps, download, upload } = bandwidthData.value;
    if (!timestamps.length) return [];

    return [
        {
            label: 'Download',
            color: 'var(--color-success)',
            fill: true,
            data: timestamps.map((ts, i) => ({
                timestamp: Number(ts),
                value: download[i] ?? 0,
            })),
        },
        {
            label: 'Upload',
            color: 'var(--color-info)',
            fill: true,
            data: timestamps.map((ts, i) => ({
                timestamp: Number(ts),
                value: upload[i] ?? 0,
            })),
        },
    ];
});

let bandwidthPoll = null;

async function fetchBandwidth() {
    try {
        const response = await fetch(
            route('admin.dashboard.bandwidth') + '?range=' + selectedRange.value + '&_t=' + Date.now(),
        );
        if (response.ok) {
            bandwidthData.value = await response.json();
        }
    } catch (_e) {
        // Silently fail — data will refresh next interval
    } finally {
        bandwidthLoading.value = false;
    }
}

function selectRange(range) {
    selectedRange.value = range;
    bandwidthLoading.value = true;
    fetchBandwidth();
}

onMounted(() => {
    fetchBandwidth();
    bandwidthPoll = setInterval(fetchBandwidth, 30000);
});

onUnmounted(() => {
    if (bandwidthPoll) clearInterval(bandwidthPoll);
});
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
        </div>

        <!-- Stat Strip -->
        <div
            data-testid="dashboard-stats"
            class="my-6 mb-7 flex flex-wrap gap-y-4 border-b border-[var(--color-border)] pb-5"
        >
            <div
                class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-full max-sm:border-0 max-sm:pr-0"
            >
                <StatCard label="Online Now" :value="onlineUsers" color="success" label-dot-color="success">
                    <p class="mt-[2px] font-mono text-[11px] text-[var(--color-text-muted)]">
                        of {{ totalUsers }} &middot;
                        <span class="text-[var(--color-success)]">{{ onlinePercentage }}%</span>
                    </p>
                </StatCard>
            </div>

            <div
                class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-1/3 max-sm:border-0 max-sm:pr-0"
            >
                <StatCard label="Total Users" :value="totalUsers" />
            </div>

            <div
                class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8 max-sm:mr-0 max-sm:basis-1/3 max-sm:border-0 max-sm:pr-0"
            >
                <StatCard label="IPs Active" :value="activeIps" />
            </div>

            <div class="flex-1 max-sm:basis-1/3">
                <StatCard label="Blocked" :value="blockedUsers" color="danger" />
            </div>
        </div>

        <!-- Two Column: DHCP Pools + Total Bandwidth Chart -->
        <div class="mb-10 grid grid-cols-1 gap-6 md:grid-cols-[3fr_2fr]">
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
    </div>
</template>
