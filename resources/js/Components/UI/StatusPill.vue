<script setup>
import { computed } from 'vue';

const props = defineProps({
    status: {
        type: String,
        required: true,
        validator: (v) => ['success', 'danger', 'warning', 'info', 'neutral'].includes(v),
    },
    label: { type: String, required: true },
});

const config = {
    success: {
        bg: 'bg-[var(--color-success)]/14',
        text: 'text-[var(--color-success)]',
        border: 'border-[var(--color-success)]/14',
        symbol: '\u2713',
    },
    danger: {
        bg: 'bg-[var(--color-danger)]/14',
        text: 'text-[var(--color-danger)]',
        border: 'border-[var(--color-danger)]/14',
        symbol: '\u2717',
    },
    warning: {
        bg: 'bg-[var(--color-warning)]/14',
        text: 'text-[var(--color-warning)]',
        border: 'border-[var(--color-warning)]/14',
        symbol: '\u25B2',
    },
    info: {
        bg: 'bg-[var(--color-info)]/14',
        text: 'text-[var(--color-info)]',
        border: 'border-[var(--color-info)]/14',
        symbol: '\u2713',
    },
    neutral: {
        bg: 'bg-[var(--color-text-muted)]/14',
        text: 'text-[var(--color-text-muted)]',
        border: 'border-[var(--color-text-muted)]/14',
        symbol: '',
    },
};

const c = computed(() => config[props.status]);
</script>

<template>
    <span
        :class="[c.bg, c.text, c.border]"
        data-testid="status-pill"
        class="inline-flex items-center gap-1 rounded border px-2.5 py-0.5 text-[11px] font-semibold"
    >
        <span v-if="c.symbol" aria-hidden="true" data-testid="status-symbol">{{ c.symbol }}</span>
        {{ label }}
    </span>
</template>
