<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import BlockGrid from '@/Components/BlockGrid.vue';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';
import BandwidthBlock from '@/Components/Blocks/BandwidthBlock.vue';
import PiHoleToggleBlock from '@/Components/Blocks/PiHoleToggleBlock.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    currentIp: { type: String, default: '' },
    ipAllowed: Boolean,
    dnsDetection: { type: Object, default: null },
});

const user = usePage().props.auth?.user;

/* Extract hero blocks (bandwidth + pihole, shown in hero cols) */
const bandwidthBlock = computed(() => props.blocks.find((b) => b.type === 'bandwidth'));
const piholeBlock = computed(() => props.blocks.find((b) => b.type === 'pihole_toggle'));

/* Remaining blocks for the standard grid (exclude hero blocks) */
const heroTypes = ['bandwidth', 'pihole_toggle'];
const gridBlocks = computed(() => props.blocks.filter((b) => !heroTypes.includes(b.type)));
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

        <!-- DNS Warning (top of page) -->
        <div v-if="dnsDetection" class="mb-4">
            <DnsWarningBlock :check-url="dnsDetection.checkUrl" :warning-message="dnsDetection.warningMessage" />
        </div>

        <!-- Connection strip -->
        <div
            data-testid="connection-strip"
            class="mb-5 flex items-center rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] py-2.5"
        >
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >IPv4</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">{{ currentIp || '—' }}</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >IPv6</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">—</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >MAC Address</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">—</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 px-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >Status</span
                >
                <span class="inline-flex items-center gap-1.5 font-mono text-[13px] font-semibold">
                    <span
                        class="h-[7px] w-[7px] rounded-full"
                        :class="
                            ipAllowed
                                ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                : 'bg-[var(--color-danger)]'
                        "
                    />
                    <span :class="ipAllowed ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'">
                        {{ ipAllowed ? 'Online' : 'Offline' }}
                    </span>
                </span>
            </div>
        </div>

        <!-- Hero columns: Bandwidth (left) + Ad Blocking (right) -->
        <div class="mb-4 grid grid-cols-1 gap-4 xl:grid-cols-[1.2fr_1fr]">
            <div
                v-if="bandwidthBlock"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-5 transition-colors hover:border-[var(--color-border-hover)]"
            >
                <BandwidthBlock :stats="bandwidthBlock.settings" />
            </div>
            <div
                v-if="piholeBlock"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-5 transition-colors hover:border-[var(--color-border-hover)]"
            >
                <PiHoleToggleBlock :title="piholeBlock.title" :content="piholeBlock.content" />
            </div>
        </div>

        <!-- Block grid for remaining blocks -->
        <BlockGrid :blocks="gridBlocks" :current-ip="currentIp" :ip-allowed="ipAllowed" />
    </div>
</template>
