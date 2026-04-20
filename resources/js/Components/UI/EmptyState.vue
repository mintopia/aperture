<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div
        data-testid="empty-state"
        class="flex flex-col items-center justify-center rounded-xl border border-dashed border-[var(--color-border)] bg-[var(--color-surface)] px-6 py-12 text-center"
    >
        <div class="mb-3 text-[var(--color-text-muted)]">
            <slot name="icon" />
        </div>
        <p class="text-[13px] font-semibold text-[var(--color-text)]">
            {{ title }}
        </p>
        <p v-if="description" class="mt-1 max-w-[320px] text-[11px] text-[var(--color-text-muted)]">
            {{ description }}
        </p>
        <div class="mt-4">
            <slot />
        </div>
    </div>
</template>
