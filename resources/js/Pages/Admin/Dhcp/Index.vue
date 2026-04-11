<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';
import ProgressBar from '@/Components/UI/ProgressBar.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    pool: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            DHCP
        </h1>

        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Total" :value="pool?.total ?? '—'" />
            <StatCard label="Used" :value="pool?.used ?? '—'" color="accent" />
            <StatCard label="Available" :value="pool?.available ?? '—'" color="success" />
            <StatCard
                label="Utilisation"
                :value="pool?.utilisation ? (pool.utilisation * 100).toFixed(1) + '%' : '—'"
            />
        </div>

        <template v-if="pool?.used && pool?.total">
            <SectionHeader title="Pool Utilisation" accent-line />
            <ProgressBar
                label="DHCP Pool"
                :value="pool.used"
                :max="pool.total"
                :color="pool.utilisation > 0.9 ? 'danger' : pool.utilisation > 0.7 ? 'warning' : 'primary'"
                :display-value="`${pool.used}/${pool.total}`"
            />
        </template>

        <Link
            :href="route('admin.dhcp.leases')"
            class="mt-4 inline-block text-sm text-[var(--color-primary)] hover:underline"
        >
            View DHCP Leases →
        </Link>
    </div>
</template>
