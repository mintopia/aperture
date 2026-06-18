<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const url = computed(() => props.settings?.url || '');
const alt = computed(() => props.settings?.alt || props.title || 'Image');
</script>

<template>
    <div data-testid="block-image" class="-m-5 h-[calc(100%+2.5rem)] min-h-32 overflow-hidden rounded-md">
        <img v-if="url" :src="url" :alt="alt" data-testid="image-element" class="h-full w-full object-cover" />
        <div v-else class="flex h-32 items-center justify-center text-sm text-[var(--color-text-muted)]">
            No image configured
        </div>
    </div>
</template>
