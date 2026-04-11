<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    aggregateStats: Object,
});
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Network Stats
        </h1>

        <SectionHeader title="Overview" accent-line />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatCard label="Total Users" :value="aggregateStats?.totalUsers ?? '—'" />
            <StatCard label="Total Devices" :value="aggregateStats?.totalDevices ?? '—'" />
            <StatCard label="Total Bandwidth" :value="aggregateStats?.totalBandwidth ?? '—'" />
        </div>
    </div>
</template>
