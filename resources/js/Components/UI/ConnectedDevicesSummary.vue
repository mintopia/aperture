<script setup>
defineProps({
    macs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div v-if="macs.length > 0" data-testid="connected-devices-summary" class="mt-3">
        <h2
            data-testid="connected-devices-title"
            class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
            :style="{ fontVariationSettings: '\'opsz\' 16' }"
        >
            {{ macs.length }} Connected Device{{ macs.length !== 1 ? 's' : '' }}
        </h2>
        <div class="overflow-x-auto">
            <table data-testid="connected-devices-table" class="w-full border-collapse text-[13px]">
                <thead>
                    <tr>
                        <th
                            class="border-b border-[var(--color-border-hover)] py-2 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                        >
                            Device
                        </th>
                        <th
                            class="border-b border-[var(--color-border-hover)] py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                        >
                            MAC Address
                        </th>
                        <th
                            class="border-b border-[var(--color-border-hover)] py-2 pl-6 text-left text-[11px] font-semibold tracking-[0.05em] text-[var(--color-text-muted)] uppercase"
                        >
                            IP Address
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(mac, i) in macs" :key="i" data-testid="connected-devices-row">
                        <td class="border-b border-[var(--color-border)] py-2.5 align-top">
                            <span
                                v-if="mac.resolved_ips?.some((r) => r.user)"
                                class="text-[13px] font-medium text-[var(--color-text)]"
                            >
                                {{ mac.resolved_ips.find((r) => r.user)?.user.nickname }}
                            </span>
                            <span v-else class="text-[13px] text-[var(--color-text-muted)]">&mdash;</span>
                        </td>
                        <td class="border-b border-[var(--color-border)] py-2.5 pl-6 align-top">
                            <span class="font-mono text-[13px] text-[var(--color-text-muted)]">{{
                                mac.mac_address
                            }}</span>
                        </td>
                        <td class="border-b border-[var(--color-border)] py-2.5 pl-6 align-top">
                            <span
                                v-if="mac.resolved_ips?.[0]?.ip"
                                class="font-mono text-[13px] text-[var(--color-primary)]"
                            >
                                {{ mac.resolved_ips[0].ip }}
                            </span>
                            <span v-else class="text-[13px] text-[var(--color-text-muted)]">&mdash;</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
