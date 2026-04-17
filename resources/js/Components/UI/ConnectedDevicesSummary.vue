<script setup>
defineProps({
    macs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div
        v-if="macs.length > 0"
        data-testid="connected-devices-summary"
        class="mt-3 rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] p-3"
    >
        <p class="mb-2 text-xs font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ macs.length }} Connected Device{{ macs.length !== 1 ? 's' : '' }}
        </p>
        <div class="space-y-1">
            <div v-for="(mac, i) in macs" :key="i" class="flex items-center gap-2 text-xs">
                <span v-if="mac.resolved_ips?.some((r) => r.user)" class="font-medium text-[var(--color-text)]">
                    {{ mac.resolved_ips.find((r) => r.user)?.user.nickname }}
                </span>
                <span v-else class="font-mono text-[var(--color-text-muted)]">{{ mac.mac_address }}</span>
                <span v-if="mac.resolved_ips?.[0]?.ip" class="font-mono text-[var(--color-text-muted)]">
                    {{ mac.resolved_ips[0].ip }}
                </span>
            </div>
        </div>
    </div>
</template>
