<script setup>
import { reactive, onMounted, onUnmounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import BlockGrid from '@/Components/BlockGrid.vue';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';
import { useUserChannel } from '@/composables/useUserChannel.js';

defineOptions({ layout: PortalLayout });

const props = defineProps({
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

const liveContext = reactive({ ...props.blockContext });

let channelCleanup = null;

onMounted(() => {
    if (!user?.id) {
        return;
    }

    const { cleanup } = useUserChannel(user.id, {
        onInternetAccessChanged(data) {
            liveContext.internetEnabled = data.enabled;
        },
        onRateLimitChanged() {
            router.reload({ only: ['blockContext'], preserveScroll: true });
        },
        onDnsFilterChanged(data) {
            liveContext.dnsFilteringEnabled = data.enabled;
        },
        onUserBlocked() {
            liveContext.internetBlocked = true;
            router.reload({ only: ['blockContext'], preserveScroll: true });
        },
    });

    channelCleanup = cleanup;
});

onUnmounted(() => {
    if (channelCleanup) {
        channelCleanup();
    }
});
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
        <BlockGrid :blocks="blocks" :block-context="liveContext" />
    </div>
</template>
