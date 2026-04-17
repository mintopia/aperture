<script setup>
// Port errors are live-only data, not currently stored in DB.
// This is a placeholder until Prometheus integration provides cached error snapshots.
</script>

<template>
    <div
        data-testid="port-errors-card"
        class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5"
    >
        <h3
            data-testid="port-errors-title"
            class="mb-4 text-[11px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
        >
            Port Errors
        </h3>

        <p data-testid="port-errors-empty" class="text-[11px] text-[var(--color-text-muted)]">
            No error data available — requires Prometheus integration
        </p>
    </div>
</template>
