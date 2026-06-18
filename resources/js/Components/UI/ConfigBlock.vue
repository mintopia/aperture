<script setup>
defineProps({
    code: { type: String, required: true },
});
</script>

<template>
    <pre
        data-testid="config-block"
        class="overflow-x-auto rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4 font-mono text-[12px] leading-[1.7] whitespace-pre text-[var(--color-text-secondary)]"
    ><code>{{ code }}</code></pre>
</template>
