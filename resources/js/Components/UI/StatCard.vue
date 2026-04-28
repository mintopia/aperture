<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    sub: { type: String, default: '' },
    color: { type: String, default: 'text' },
    labelDotColor: { type: String, default: '' },
});

const colorMap = {
    text: 'text-[var(--color-text)]',
    primary: 'text-[var(--color-primary)]',
    accent: 'text-[var(--color-accent)]',
    success: 'text-[var(--color-success)]',
    danger: 'text-[var(--color-danger)]',
    warning: 'text-[var(--color-warning)]',
};

const dotColorMap = {
    primary: 'bg-[var(--color-primary)]',
    accent: 'bg-[var(--color-accent)]',
    success: 'bg-[var(--color-success)]',
    danger: 'bg-[var(--color-danger)]',
    warning: 'bg-[var(--color-warning)]',
};
</script>

<template>
    <div data-testid="stat-card">
        <p
            class="mb-1 inline-flex items-center gap-2 text-[10px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
        >
            <span
                v-if="labelDotColor"
                :class="dotColorMap[labelDotColor] ?? dotColorMap.primary"
                data-testid="stat-label-dot"
                class="inline-block h-2 w-2 rounded-full"
            />
            <span>{{ label }}</span>
        </p>
        <p
            data-testid="stat-value"
            :class="[colorMap[color] ?? colorMap.text]"
            class="font-heading text-[28px] font-bold tracking-[-0.02em]"
            style="font-variation-settings: 'opsz' 36"
        >
            {{ value }}
        </p>
        <p v-if="sub" data-testid="stat-sub" class="mt-0.5 font-mono text-[11px] text-[var(--color-text-muted)]">
            {{ sub }}
        </p>
        <slot />
    </div>
</template>
