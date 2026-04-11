<script setup>
defineProps({
    stats: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
        <h3 class="mb-3 text-lg font-semibold text-[var(--color-text)]">Network Stats</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-xs text-[var(--color-text-muted)]">Connected Users</p>
                <p class="text-xl font-bold text-[var(--color-text)]">
                    {{ stats.connectedUsers ?? '—' }}
                </p>
            </div>
            <div>
                <p class="text-xs text-[var(--color-text-muted)]">Total Devices</p>
                <p class="text-xl font-bold text-[var(--color-text)]">
                    {{ stats.totalDevices ?? '—' }}
                </p>
            </div>
        </div>
    </div>
</template>
