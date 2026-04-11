<script setup>
defineProps({
    hasDnsIssue: Boolean,
    expectedDns: { type: String, default: '' },
    actualDns: { type: String, default: '' },
});
</script>

<template>
    <div v-if="hasDnsIssue" class="rounded-xl border border-[var(--color-warning)] bg-[var(--color-warning)]/10 p-6">
        <h3 class="mb-2 text-lg font-semibold text-[var(--color-warning)]">DNS Misconfigured</h3>
        <p class="text-sm text-[var(--color-text-secondary)]">
            Your DNS is set to <span class="font-mono">{{ actualDns }}</span> but should be
            <span class="font-mono font-bold">{{ expectedDns }}</span
            >. Update your network settings for the best experience.
        </p>
    </div>
</template>
