<script setup>
defineProps({
    currentIp: String,
    ipAllowed: Boolean,
});
</script>

<template>
    <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
        <h3 class="mb-3 text-lg font-semibold text-[var(--color-text)]">Connection Status</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--color-text-secondary)]">IP Address</span>
                <span class="font-mono text-sm text-[var(--color-text)]">{{ currentIp }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-sm text-[var(--color-text-secondary)]">Status</span>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="
                        ipAllowed
                            ? 'bg-[var(--color-success)]/10 text-[var(--color-success)]'
                            : 'bg-[var(--color-danger)]/10 text-[var(--color-danger)]'
                    "
                >
                    <span
                        class="h-1.5 w-1.5 rounded-full"
                        :class="ipAllowed ? 'bg-[var(--color-success)]' : 'bg-[var(--color-danger)]'"
                    />
                    {{ ipAllowed ? 'Connected' : 'Disconnected' }}
                </span>
            </div>
        </div>
    </div>
</template>
