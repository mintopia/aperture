<script setup>
defineProps({
    title: { type: String, required: true },
    accentLine: { type: Boolean, default: false },
});
</script>

<template>
    <div class="mb-3">
        <div v-if="accentLine" class="mb-2.5 h-[3px] w-10 rounded-sm bg-[var(--color-primary)]" />
        <div class="flex items-center justify-between">
            <h2 class="font-heading text-base font-bold text-[var(--color-text)]">{{ title }}</h2>
            <slot name="actions" />
        </div>
    </div>
</template>
