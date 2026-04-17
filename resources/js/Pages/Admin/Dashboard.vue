<script setup>
import { computed } from 'vue';
import { Deferred, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DhcpPoolsCard from '@/Components/Admin/DhcpPoolsCard.vue';
import PortErrorsCard from '@/Components/Admin/PortErrorsCard.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import StatusPill from '@/Components/UI/StatusPill.vue';
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
    { key: 'ips_count', label: 'IPs', class: 'w-[90px]' },
    { key: 'bandwidth', label: 'Bandwidth', class: 'w-[140px]' },
    { key: 'status', label: 'Status', class: 'w-[120px]' },
    { key: 'seen', label: 'Seen', class: 'w-[90px]' },
];

const recentUserRows = computed(() => props.recentUsers?.data ?? []);

function userStatus(user) {
    return user.blocked ? { status: 'danger', label: 'Blocked' } : { status: 'success', label: 'Active' };
}

function userHref(id) {
    return route('admin.users.show', id);
}
</script>

<template>
    <div class="space-y-6">
        <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Dashboard
        </h1>

        <div data-testid="dashboard-stats" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="ONLINE NOW" :value="onlineUsers" color="success" hero label-dot-color="success">
                <div class="mt-3 flex items-end justify-between gap-3">
                    <div class="text-[10px] tracking-[0.2em] text-[var(--color-success)]/80 uppercase">
                        Live sessions
                    </div>
                    <div class="text-right text-[11px]">
                        <p class="text-[var(--color-text-muted)]">of {{ totalUsers }}</p>
                        <p class="font-semibold text-[var(--color-success)]">{{ onlinePercentage }}%</p>
                    </div>
                </div>
            </StatCard>

            <StatCard label="TOTAL USERS" :value="totalUsers" />
            <StatCard label="IPS ACTIVE" :value="activeIps" />
            <StatCard label="BLOCKED" :value="blockedUsers" color="danger" />
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <Deferred data="dhcpPools">
                <template #fallback>
                    <div
                        data-testid="dhcp-pools-loading"
                        class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                    >
                        <h3 class="mb-4 text-[11px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
                            DHCP Pools
                        </h3>
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

            <PortErrorsCard />
        </div>

        <Deferred data="recentUsers">
            <template #fallback>
                <div
                    data-testid="recent-users-loading"
                    class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
                >
                    <SectionHeader title="TOP BANDWIDTH &amp; RECENT USERS" />
                    <div class="space-y-3">
                        <div
                            v-for="i in 5"
                            :key="i"
                            class="h-10 animate-pulse rounded-lg bg-[var(--color-surface-hover)]"
                        />
                    </div>
                </div>
            </template>

            <section
                data-testid="recent-users-section"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
            >
                <SectionHeader title="TOP BANDWIDTH &amp; RECENT USERS" />

                <div v-if="recentUserRows.length" class="space-y-4">
                    <DataTable :columns="recentUserColumns" :rows="recentUserRows">
                        <template #row="{ row }">
                            <td class="px-4 py-3 text-sm">
                                <Link
                                    :href="userHref(row.id)"
                                    class="font-medium text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
                                >
                                    {{ row.nickname }}
                                </Link>
                            </td>
                            <td class="px-4 py-3 text-sm text-[var(--color-text-secondary)]">
                                {{ row.email }}
                            </td>
                            <td class="px-4 py-3 text-sm text-[var(--color-text-secondary)]">
                                {{ row.ips_count ?? 0 }}
                            </td>
                            <td class="px-4 py-3 text-sm text-[var(--color-text-secondary)]">
                                {{ formatBytes(row.total_bandwidth ?? 0) }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusPill :status="userStatus(row).status" :label="userStatus(row).label" />
                            </td>
                            <td class="px-4 py-3 text-sm text-[var(--color-text-secondary)]">
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
