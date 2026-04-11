<script setup>
import { usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import BlockGrid from '@/Components/BlockGrid.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    currentIp: String,
    ipAllowed: Boolean,
});

const user = usePage().props.auth?.user;
</script>

<template>
    <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-5 flex items-center justify-between">
            <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
                Welcome, {{ user?.nickname ?? 'Guest' }}
            </h1>
            <span
                v-if="ipAllowed"
                class="rounded-full bg-[var(--color-accent)]/10 px-2.5 py-0.5 text-[10px] font-bold tracking-wider text-[var(--color-accent)] uppercase shadow-[0_0_12px_var(--color-glow)]"
            >
                Live
            </span>
        </div>

        <BlockGrid :blocks="blocks" :current-ip="currentIp" :ip-allowed="ipAllowed" />
    </div>
</template>
