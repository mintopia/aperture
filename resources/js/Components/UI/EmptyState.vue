<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div data-testid="empty-state" class="flex flex-col items-center justify-center py-12 text-center">
        <slot name="icon" />
        <p class="text-[13px] font-semibold text-[var(--color-text)]">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-[260px] text-[11px] text-[var(--color-text-muted)]">{{ description }}</p>
        <slot />
    </div>
</template>
