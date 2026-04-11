<script setup>
defineProps({
    title: String,
    content: String,
});
</script>

<template>
    <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
        <h3 class="mb-3 text-lg font-semibold text-[var(--color-text)]">
            {{ title }}
        </h3>
        <div class="prose prose-sm text-[var(--color-text-secondary)]" v-html="content" />
    </div>
</template>
