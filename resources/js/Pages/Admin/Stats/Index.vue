<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    aggregateStats: { type: Object, default: () => ({}) },
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
                Network Stats
            </h1>
        </div>

        <div class="mt-6">
            <SectionHeader title="Overview" />

            <div data-testid="stats-overview" class="mt-3 mb-7 flex gap-0 border-b border-[var(--color-border)] pb-5">
                <div class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8">
                    <StatCard label="Total Users" :value="aggregateStats?.totalUsers ?? '—'" />
                </div>

                <div class="mr-8 flex-1 border-r border-[var(--color-border)] pr-8">
                    <StatCard label="Total Devices" :value="aggregateStats?.totalDevices ?? '—'" />
                </div>

                <div class="flex-1">
                    <StatCard label="Total Bandwidth" :value="aggregateStats?.totalBandwidth ?? '—'" />
                </div>
            </div>
        </div>
    </div>
</template>
