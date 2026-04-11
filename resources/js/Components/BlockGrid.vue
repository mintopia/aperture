<script setup>
import EventInfoBlock from './Blocks/EventInfoBlock.vue';
import ConnectionStatusBlock from './Blocks/ConnectionStatusBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import NetworkStatsBlock from './Blocks/NetworkStatsBlock.vue';
import PiHoleToggleBlock from './Blocks/PiHoleToggleBlock.vue';
import DnsWarningBlock from './Blocks/DnsWarningBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';

const blockComponents = {
    event_info: EventInfoBlock,
    connection_status: ConnectionStatusBlock,
    bandwidth: BandwidthBlock,
    network_stats: NetworkStatsBlock,
    pihole_toggle: PiHoleToggleBlock,
    dns_warning: DnsWarningBlock,
    custom_markdown: CustomMarkdownBlock,
};

defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    currentIp: String,
    ipAllowed: Boolean,
});
</script>

<template>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <template v-for="block in blocks" :key="block.id">
            <component
                :is="blockComponents[block.type]"
                v-if="blockComponents[block.type]"
                :title="block.title"
                :content="block.content"
                :settings="block.settings"
                :current-ip="currentIp"
                :ip-allowed="ipAllowed"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-4 transition-colors hover:border-[var(--color-border-hover)]"
                :data-testid="'block-' + block.type"
            />
        </template>
    </div>
</template>
