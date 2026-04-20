<script setup>
defineProps({
    code: { type: String, required: true },
});
</script>

<template>
    <pre
        data-testid="config-block"
        class="overflow-x-auto rounded-xl border border-[var(--color-border)] bg-[var(--color-bg)] p-5 font-mono text-xs leading-relaxed whitespace-pre text-[var(--color-text-secondary)]"
    ><code>{{ code }}</code></pre>
</template>
