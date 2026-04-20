<script setup>
defineProps({
    currentIp: { type: String, default: '' },
    ipAllowed: Boolean,
});
</script>

<template>
    <div data-testid="block-connection-status">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            Connection Status
        </h3>
        <div class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between">
                <span class="text-[13px] text-[var(--color-text-secondary)]">IP Address</span>
                <span class="font-mono text-sm text-[var(--color-text)]">{{ currentIp }}</span>
            </div>
            <div class="border-t border-[var(--color-border)]"></div>
            <div class="flex items-baseline justify-between">
                <span class="text-[13px] text-[var(--color-text-secondary)]">Status</span>
                <span class="inline-flex items-center gap-1.5 text-sm font-semibold">
                    <span
                        class="h-[7px] w-[7px] rounded-full"
                        :class="
                            ipAllowed
                                ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                : 'bg-[var(--color-danger)]'
                        "
                    />
                    <span :class="ipAllowed ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'">
                        {{ ipAllowed ? 'Online' : 'Offline' }}
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>
