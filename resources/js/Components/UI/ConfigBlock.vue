<script setup>
defineProps({
    code: { type: String, required: true },
});
</script>

<template>
    <pre data-testid="config-block" class="overflow-x-auto whitespace-pre rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] p-3 font-mono text-[10px] leading-relaxed text-[var(--color-text-secondary)]"><code>{{ code }}</code></pre>
</template>
