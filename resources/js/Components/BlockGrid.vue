<script setup>
import { computed } from 'vue';
import ConnectionStripBlock from './Blocks/ConnectionStripBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import DnsFilterBlock from './Blocks/DnsFilterBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';
import MapBlock from './Blocks/MapBlock.vue';
import ImageBlock from './Blocks/ImageBlock.vue';
import LinkStripBlock from './Blocks/LinkStripBlock.vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const blockComponents = {
    connection_strip: ConnectionStripBlock,
    bandwidth: BandwidthBlock,
    dns_filter: DnsFilterBlock,
    custom_markdown: CustomMarkdownBlock,
    map: MapBlock,
    image: ImageBlock,
    link_strip: LinkStripBlock,
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

const maxRow = computed(() => {
    if (!props.blocks.length) {
        return 0;
    }
    return Math.max(...props.blocks.map((b) => Number(b.grid_row) + Number(b.row_span) - 1));
});

const orderedBlocks = computed(() =>
    [...props.blocks].sort((a, b) => a.grid_row - b.grid_row || a.grid_col - b.grid_col),
);

// Editor positions target the 3-column xl grid; narrower grids flow blocks in reading order.
const gridStyle = computed(() => {
    if (maxRow.value === 0) {
        return {};
    }
    return {
        '--block-grid-rows': `repeat(${maxRow.value}, minmax(80px, auto))`,
    };
});

function blockStyle(block) {
    return {
        '--block-md-span': Math.min(Number(block.col_span), 2),
        '--block-col': `${block.grid_col} / span ${block.col_span}`,
        '--block-row': `${block.grid_row} / span ${block.row_span}`,
    };
}

function templateContent(block) {
    if (block.type === 'custom_markdown') {
        return renderTemplate(block.content, props.blockContext);
    }
    return block.content;
}
</script>

<template>
    <div
        data-testid="block-grid"
        class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3 xl:[grid-template-rows:var(--block-grid-rows)]"
        :style="gridStyle"
    >
        <template v-for="block in orderedBlocks" :key="block.id">
            <div
                v-if="blockComponents[block.type]"
                class="glass-lens flex min-w-0 flex-col rounded-2xl p-5 md:[grid-column:span_var(--block-md-span)] xl:[grid-column:var(--block-col)] xl:[grid-row:var(--block-row)]"
                :data-testid="'block-' + block.type + '-wrapper'"
                :style="blockStyle(block)"
            >
                <component
                    :is="blockComponents[block.type]"
                    class="min-h-0 flex-1"
                    :title="block.title"
                    :content="templateContent(block)"
                    :settings="block.settings"
                    :block-context="blockContext"
                />
            </div>
        </template>
    </div>
</template>
