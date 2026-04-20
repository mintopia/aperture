<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: Number, required: true },
    max: { type: Number, default: 100 },
    color: { type: String, default: 'primary' },
    displayValue: { type: String, default: '' },
});

const colorMap = {
    primary: 'bg-[var(--color-primary)]',
    success: 'bg-[var(--color-success)]',
    warning: 'bg-[var(--color-warning)]',
    danger: 'bg-[var(--color-danger)]',
    accent: 'bg-[var(--color-accent)]',
};
</script>

<template>
    <div data-testid="progress-bar" class="flex items-center gap-1.5 text-[11px]">
        <span class="w-[90px] text-[var(--color-text-secondary)]">{{ label }}</span>
        <div class="mt-[6px] h-[6px] flex-1 overflow-hidden rounded-[3px] bg-[var(--color-surface-hover)]">
            <div
                data-testid="progress-bar-fill"
                :class="colorMap[color]"
                :style="{ width: `${Math.min((value / max) * 100, 100)}%` }"
                class="h-full rounded-[3px] transition-[width] duration-300"
            />
        </div>
        <span class="min-w-[50px] text-right font-mono text-[10px]">{{ displayValue || `${value}/${max}` }}</span>
    </div>
</template>
