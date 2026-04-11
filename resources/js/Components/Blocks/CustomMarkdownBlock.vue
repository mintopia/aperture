<script setup>
defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
});
</script>

<template>
    <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
        <h3 class="mb-3 text-lg font-semibold text-[var(--color-text)]">
            {{ title }}
        </h3>
        <div class="text-sm whitespace-pre-wrap text-[var(--color-text-secondary)]">
            {{ content }}
        </div>
    </div>
</template>
