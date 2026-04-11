<script setup>
defineProps({
    type: {
        type: String,
        default: 'warning',
        validator: (v) => ['warning', 'info', 'danger', 'success'].includes(v),
    },
});

const config = {
    warning: {
        bg: 'bg-[var(--color-warning)]/10',
        border: 'border-[var(--color-warning)]/15',
        borderLeft: 'border-l-[var(--color-warning)]',
    },
    info: {
        bg: 'bg-[var(--color-primary)]/10',
        border: 'border-[var(--color-primary)]/15',
        borderLeft: 'border-l-[var(--color-primary)]',
    },
    danger: {
        bg: 'bg-[var(--color-danger)]/10',
        border: 'border-[var(--color-danger)]/15',
        borderLeft: 'border-l-[var(--color-danger)]',
    },
    success: {
        bg: 'bg-[var(--color-success)]/10',
        border: 'border-[var(--color-success)]/15',
        borderLeft: 'border-l-[var(--color-success)]',
    },
};
</script>

<template>
    <div
        data-testid="alert-banner"
        :class="[config[type].bg, config[type].border, config[type].borderLeft]"
        class="flex items-center justify-between rounded-lg border border-l-[3px] px-3.5 py-2.5"
    >
        <slot />
    </div>
</template>
