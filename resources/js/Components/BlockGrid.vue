<script setup>
import EventInfoBlock from './Blocks/EventInfoBlock.vue';
import ConnectionStatusBlock from './Blocks/ConnectionStatusBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import NetworkStatsBlock from './Blocks/NetworkStatsBlock.vue';
import DnsFilterBlock from './Blocks/DnsFilterBlock.vue';
import DnsWarningBlock from './Blocks/DnsWarningBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';

const blockComponents = {
    event_info: EventInfoBlock,
    connection_status: ConnectionStatusBlock,
    bandwidth: BandwidthBlock,
    network_stats: NetworkStatsBlock,
    dns_filter: DnsFilterBlock,
    dns_warning: DnsWarningBlock,
    custom_markdown: CustomMarkdownBlock,
};

defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    currentIp: { type: String, default: '' },
    ipAllowed: Boolean,
});
</script>

<template>
    <div data-testid="block-grid" class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <template v-for="block in blocks" :key="block.id">
            <div
                v-if="blockComponents[block.type]"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-5 transition-colors hover:border-[var(--color-border-hover)]"
                :data-testid="'block-' + block.type + '-wrapper'"
            >
                <component
                    :is="blockComponents[block.type]"
                    :title="block.title"
                    :content="block.content"
                    :settings="block.settings"
                    :current-ip="currentIp"
                    :ip-allowed="ipAllowed"
                />
            </div>
        </template>
    </div>
</template>
