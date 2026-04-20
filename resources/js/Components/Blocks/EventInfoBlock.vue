<script setup>
defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
});
</script>

<template>
    <div data-testid="block-event-info">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ title }}
        </h3>
        <div
            class="prose prose-sm [&_h3]:font-heading text-sm leading-relaxed text-[var(--color-text-secondary)] [&_h3]:mb-2 [&_h3]:text-base [&_h3]:font-bold [&_h3]:text-[var(--color-text)] [&_li]:mb-1 [&_p]:mb-2 [&_ul]:mb-2 [&_ul]:pl-5"
            v-html="content"
        />
    </div>
</template>
