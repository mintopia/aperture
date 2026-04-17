<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    color: { type: String, default: 'text' },
    hero: { type: Boolean, default: false },
    accentBorder: { type: String, default: '' },
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
    <div
        data-testid="stat-card"
        :class="[
            hero ? 'border-[var(--color-success)]/15 bg-[var(--color-success)]/5' : 'bg-[var(--color-surface)]',
            accentBorder ? 'border-l-[3px]' : '',
        ]"
        :style="accentBorder ? `border-left-color: var(--color-${accentBorder})` : ''"
        class="rounded-xl border border-[var(--color-border)] p-5 transition-colors hover:border-[var(--color-border-hover)]"
    >
        <p
            class="inline-flex items-center gap-2 text-[11px] font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
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
            :class="[colorMap[color] ?? colorMap.text, hero ? 'text-[40px] leading-none tracking-tight' : 'text-2xl']"
            class="font-heading mt-1 font-bold"
        >
            {{ value }}
        </p>
        <slot />
    </div>
</template>
