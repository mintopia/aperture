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
        dot: 'bg-[var(--color-success)]',
        glow: 'shadow-[0_0_6px_var(--color-success)]',
    },
    danger: {
        bg: 'bg-[var(--color-danger)]/14',
        text: 'text-[var(--color-danger)]',
        border: 'border-[var(--color-danger)]/14',
        dot: 'bg-[var(--color-danger)]',
        glow: 'shadow-[0_0_6px_var(--color-danger)]',
    },
    warning: {
        bg: 'bg-[var(--color-warning)]/14',
        text: 'text-[var(--color-warning)]',
        border: 'border-[var(--color-warning)]/14',
        dot: 'bg-[var(--color-warning)]',
        glow: 'shadow-[0_0_6px_var(--color-warning)]',
    },
    info: {
        bg: 'bg-[var(--color-info)]/14',
        text: 'text-[var(--color-info)]',
        border: 'border-[var(--color-info)]/14',
        dot: 'bg-[var(--color-info)]',
        glow: 'shadow-[0_0_6px_var(--color-info)]',
    },
    neutral: {
        bg: 'bg-[var(--color-text-muted)]/14',
        text: 'text-[var(--color-text-muted)]',
        border: 'border-[var(--color-text-muted)]/14',
        dot: 'bg-[var(--color-text-muted)]',
        glow: '',
    },
};

const c = computed(() => config[props.status]);
</script>

<template>
    <span
        :class="[c.bg, c.text, c.border]"
        data-testid="status-pill"
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold"
    >
        <span
            :class="[c.dot, c.glow]"
            class="inline-block h-[7px] w-[7px] shrink-0 rounded-full"
            aria-hidden="true"
        ></span>
        {{ label }}
    </span>
</template>
