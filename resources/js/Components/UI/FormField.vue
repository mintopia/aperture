<script setup>
defineProps({
    label: { type: String, required: true },
    name: { type: String, required: true },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
});
</script>

<template>
    <div :data-testid="'form-field-' + name" class="space-y-1.5">
        <label :for="name" class="block text-sm font-semibold text-[var(--color-text)]">
            {{ label }}
            <span v-if="required" class="text-[var(--color-danger)]" aria-label="required">*</span>
            <span v-else class="ml-1 text-xs font-normal text-[var(--color-text-muted)]">(optional)</span>
        </label>
        <slot />
        <p v-if="error" class="text-xs text-[var(--color-danger)]">
            {{ error }}
        </p>
    </div>
</template>
