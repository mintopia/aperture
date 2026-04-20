<script setup>
defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
});
</script>

<template>
    <div data-testid="block-custom-markdown">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ title }}
        </h3>
        <div class="text-sm leading-relaxed whitespace-pre-wrap text-[var(--color-text-secondary)]">
            {{ content }}
        </div>
    </div>
</template>
