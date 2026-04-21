<script setup>
import { usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import BlockGrid from '@/Components/BlockGrid.vue';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    blockContext: {
        type: Object,
        default: () => ({}),
    },
    dnsDetection: { type: Object, default: null },
});

const user = usePage().props.auth?.user;
</script>

<template>
    <div>
        <!-- Welcome heading -->
        <div class="mb-3">
            <h1
                data-testid="page-title"
                class="font-heading text-[28px] font-bold tracking-tight text-[var(--color-text)]"
            >
                Welcome, {{ user?.nickname ?? 'Guest' }}
            </h1>
        </div>

        <!-- DNS Warning (top of page, outside grid) -->
        <div v-if="dnsDetection" class="mb-4">
            <DnsWarningBlock :check-url="dnsDetection.checkUrl" :warning-message="dnsDetection.warningMessage" />
        </div>

        <!-- Block grid -->
        <BlockGrid :blocks="blocks" :block-context="blockContext" />
    </div>
</template>
