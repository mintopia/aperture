<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
});
</script>

<template>
    <div data-testid="empty-state" class="flex flex-col items-center justify-center px-6 py-12 text-center">
        <div class="mb-3 text-[var(--color-text-muted)]">
            <slot name="icon" />
        </div>
        <p class="font-heading text-base text-[var(--color-text-secondary)]">
            {{ title }}
        </p>
        <p v-if="description" class="mt-1 max-w-[260px] text-[11px] text-[var(--color-text-muted)]">
            {{ description }}
        </p>
        <div class="mt-4">
            <slot />
        </div>
    </div>
</template>
