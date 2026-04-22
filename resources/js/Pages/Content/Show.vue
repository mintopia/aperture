<script setup>
import { computed } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import { marked } from 'marked';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    page: {
        type: Object,
        required: true,
    },
});

const renderedContent = computed(() => {
    return marked.parse(props.page.content || '', { breaks: true });
});
</script>

<template>
    <div class="mx-auto max-w-3xl px-6 py-8" data-testid="public-page">
        <h1 class="font-heading mb-6 text-[28px] font-bold text-[var(--color-text)]" data-testid="page-title">
            {{ page.title }}
        </h1>
        <div
            class="prose prose-sm max-w-none text-[var(--color-text-secondary)]"
            data-testid="page-content"
            v-html="renderedContent"
        />
    </div>
</template>
