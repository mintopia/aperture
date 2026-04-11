<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    totalUsers: { type: Number, default: 0 },
    onlineUsers: { type: Number, default: 0 },
    totalIps: { type: Number, default: 0 },
    allowedIps: { type: Number, default: 0 },
});
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Admin Dashboard
        </h1>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Online Users" :value="onlineUsers ?? 0" hero color="success" />
            <StatCard label="Total Users" :value="totalUsers ?? 0" />
            <StatCard label="Total IPs" :value="totalIps ?? 0" />
            <StatCard label="Allowed IPs" :value="allowedIps ?? 0" color="accent" accent-border="accent" />
        </div>
    </div>
</template>
