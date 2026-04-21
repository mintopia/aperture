<script setup>
defineProps({
    title: { type: String, default: 'Connection Status' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div data-testid="block-connection-strip">
        <div class="flex items-center">
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] pr-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >IPv4</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">{{
                    blockContext.currentIp || '—'
                }}</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >IPv6</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">—</span>
            </div>
            <div
                data-testid="connection-strip-mac"
                class="flex flex-1 flex-col gap-0.5 border-r border-[var(--color-border)] px-5"
            >
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >MAC Address</span
                >
                <span class="font-mono text-[13px] font-medium text-[var(--color-text)]">{{
                    blockContext.macAddress || '—'
                }}</span>
            </div>
            <div class="flex flex-1 flex-col gap-0.5 pl-5">
                <span class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase"
                    >Status</span
                >
                <span class="inline-flex items-center gap-1.5 font-mono text-[13px] font-semibold">
                    <span
                        class="h-[7px] w-[7px] rounded-full"
                        :class="
                            blockContext.ipAllowed
                                ? 'bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]'
                                : 'bg-[var(--color-danger)]'
                        "
                    />
                    <span
                        :class="blockContext.ipAllowed ? 'text-[var(--color-success)]' : 'text-[var(--color-danger)]'"
                    >
                        {{ blockContext.ipAllowed ? 'Online' : 'Offline' }}
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>
