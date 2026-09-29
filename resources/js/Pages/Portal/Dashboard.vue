<script setup>
import { reactive, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import BlockGrid from '@/Components/BlockGrid.vue';
import DnsWarningBlock from '@/Components/Blocks/DnsWarningBlock.vue';
import { useIpv6Detection } from '@/composables/useIpv6Detection.js';

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
    coverImage: { type: String, default: null },
    ipv6Detection: { type: Object, default: null },
});

const user = usePage().props.auth?.user;

const coverStyle = computed(() => {
    if (!props.coverImage) return {};
    const safe = props.coverImage.replace(/'/g, "\\'");
    return { backgroundImage: `url('${safe}')` };
});

const liveContext = reactive({ ...props.blockContext });

useIpv6Detection(props.ipv6Detection?.endpoint, {
    sessionBinding: props.ipv6Detection?.sessionBinding,
    onDetected(data) {
        liveContext.currentIpv6 = data.ip;
        liveContext.internetEnabled = data.internetEnabled;
    },
});
</script>

<template>
    <div>
        <div
            v-if="coverImage"
            data-testid="dashboard-cover"
            class="-mx-6 -mt-8 mb-6 h-32 bg-[var(--color-surface)] bg-cover bg-center md:h-48 lg:h-56"
            :style="coverStyle"
        />

        <div class="mb-3">
            <h1
                data-testid="page-title"
                class="font-heading text-[28px] font-bold tracking-tight text-[var(--color-text)]"
            >
                Welcome, {{ user?.nickname ?? 'Guest' }}
            </h1>
        </div>

        <div v-if="dnsDetection" class="mb-4">
            <DnsWarningBlock :check-url="dnsDetection.checkUrl" :warning-message="dnsDetection.warningMessage" />
        </div>

        <BlockGrid :blocks="blocks" :block-context="liveContext" />
    </div>
</template>
