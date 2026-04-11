<script setup>
const props = defineProps({
    status: {
        type: String,
        required: true,
        validator: (v) => ['success', 'danger', 'warning', 'info', 'neutral'].includes(v),
    },
    label: { type: String, required: true },
});

const config = {
    success: { icon: '✓', bg: 'bg-[var(--color-success)]/10', text: 'text-[var(--color-success)]', border: 'border-[var(--color-success)]/20' },
    danger:  { icon: '✕', bg: 'bg-[var(--color-danger)]/10',  text: 'text-[var(--color-danger)]',  border: 'border-[var(--color-danger)]/20' },
    warning: { icon: '▲', bg: 'bg-[var(--color-warning)]/10', text: 'text-[var(--color-warning)]', border: 'border-[var(--color-warning)]/20' },
    info:    { icon: '●', bg: 'bg-[var(--color-primary)]/10', text: 'text-[var(--color-primary)]', border: 'border-[var(--color-primary)]/20' },
    neutral: { icon: '—', bg: 'bg-[var(--color-text-muted)]/10', text: 'text-[var(--color-text-muted)]', border: 'border-[var(--color-text-muted)]/20' },
};

const c = config[props.status];
</script>

<template>
    <span :class="[c.bg, c.text, c.border]"
        data-testid="status-pill"
        class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-semibold">
        <span class="font-mono text-[10px] leading-none" aria-hidden="true">{{ c.icon }}</span>
        {{ label }}
    </span>
</template>
