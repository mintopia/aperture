<script setup>
import EventInfoBlock from './Blocks/EventInfoBlock.vue';
import ConnectionStatusBlock from './Blocks/ConnectionStatusBlock.vue';
import ConnectionStripBlock from './Blocks/ConnectionStripBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import NetworkStatsBlock from './Blocks/NetworkStatsBlock.vue';
import DnsFilterBlock from './Blocks/DnsFilterBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const blockComponents = {
    event_info: EventInfoBlock,
    connection_status: ConnectionStatusBlock,
    connection_strip: ConnectionStripBlock,
    bandwidth: BandwidthBlock,
    network_stats: NetworkStatsBlock,
    dns_filter: DnsFilterBlock,
    custom_markdown: CustomMarkdownBlock,
};

const props = defineProps({
    blocks: {
        type: Array,
        default: () => [],
    },
    blockContext: {
        type: Object,
        default: () => ({}),
    },
});

function blockStyle(block) {
    return {
        gridColumn: `${block.grid_col} / span ${block.col_span}`,
        gridRow: `${block.grid_row} / span ${block.row_span}`,
    };
}

function templateContent(block) {
    if (['event_info', 'custom_markdown'].includes(block.type)) {
        return renderTemplate(block.content, props.blockContext);
    }
    return block.content;
}
</script>

<template>
    <div data-testid="block-grid" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        <template v-for="block in blocks" :key="block.id">
            <div
                v-if="blockComponents[block.type]"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-5 transition-colors hover:border-[var(--color-border-hover)]"
                :data-testid="'block-' + block.type + '-wrapper'"
                :style="blockStyle(block)"
            >
                <component
                    :is="blockComponents[block.type]"
                    :title="block.title"
                    :content="templateContent(block)"
                    :settings="block.settings"
                    :block-context="blockContext"
                />
            </div>
        </template>
    </div>
</template>
