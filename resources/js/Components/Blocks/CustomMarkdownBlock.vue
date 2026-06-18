<script setup>
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const props = defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
});

const renderedContent = computed(() => {
    if (!props.content) return '';
    const html = marked.parse(props.content, { async: false });
    return DOMPurify.sanitize(html);
});
</script>

<template>
    <div data-testid="block-custom-markdown">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ title }}
        </h3>
        <div
            data-testid="block-custom-markdown-content"
            class="prose prose-sm max-w-none text-[var(--color-text-secondary)]"
            v-html="renderedContent"
        />
    </div>
</template>
