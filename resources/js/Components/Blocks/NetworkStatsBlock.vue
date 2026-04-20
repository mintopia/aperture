<script setup>
defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <div data-testid="block-network-stats">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            Network Stats
        </h3>
        <div class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between">
                <span class="text-[13px] text-[var(--color-text-secondary)]">Connected Users</span>
                <span class="font-mono text-sm font-semibold text-[var(--color-text)]">
                    {{ stats.connectedUsers ?? '—' }}
                </span>
            </div>
            <div class="border-t border-[var(--color-border)]"></div>
            <div class="flex items-baseline justify-between">
                <span class="text-[13px] text-[var(--color-text-secondary)]">Total Devices</span>
                <span class="font-mono text-sm font-semibold text-[var(--color-text)]">
                    {{ stats.totalDevices ?? '—' }}
                </span>
            </div>
        </div>
    </div>
</template>
