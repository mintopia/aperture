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
        bg: 'bg-[var(--color-warning)]/5',
        border: 'border-[var(--color-warning)]/20',
    },
    info: {
        bg: 'bg-[var(--color-info)]/5',
        border: 'border-[var(--color-info)]/20',
    },
    danger: {
        bg: 'bg-[var(--color-danger)]/5',
        border: 'border-[var(--color-danger)]/20',
    },
    success: {
        bg: 'bg-[var(--color-success)]/5',
        border: 'border-[var(--color-success)]/20',
    },
};
</script>

<template>
    <div
        role="alert"
        data-testid="alert-banner"
        :class="[config[type].bg, config[type].border]"
        class="flex items-center justify-between rounded border p-4"
    >
        <slot />
    </div>
</template>
