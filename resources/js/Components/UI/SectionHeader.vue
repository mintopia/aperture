<script setup>
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div data-testid="section-header" class="mb-3">
        <div class="flex items-center justify-between">
            <h2
                class="font-heading text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                :style="{ fontVariationSettings: '\'opsz\' 16' }"
            >
                {{ title }}
            </h2>
            <slot name="actions" />
        </div>
    </div>
</template>
