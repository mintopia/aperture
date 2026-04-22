<script setup>
import { computed } from 'vue';
import ConnectionStripBlock from './Blocks/ConnectionStripBlock.vue';
import BandwidthBlock from './Blocks/BandwidthBlock.vue';
import DnsFilterBlock from './Blocks/DnsFilterBlock.vue';
import CustomMarkdownBlock from './Blocks/CustomMarkdownBlock.vue';
import { renderTemplate } from '@/utils/contentTemplating.js';

const blockComponents = {
    connection_strip: ConnectionStripBlock,
    bandwidth: BandwidthBlock,
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

const maxRow = computed(() => {
    if (!props.blocks.length) {
        return 0;
    }
    return Math.max(...props.blocks.map((b) => b.grid_row + b.row_span - 1));
});

const gridStyle = computed(() => {
    if (maxRow.value === 0) {
        return {};
    }
    return {
        gridTemplateRows: `repeat(${maxRow.value}, minmax(0, auto))`,
    };
});

function blockStyle(block) {
    return {
        gridColumn: `${block.grid_col} / span ${block.col_span}`,
        gridRow: `${block.grid_row} / span ${block.row_span}`,
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
    <div data-testid="block-grid" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" :style="gridStyle">
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
