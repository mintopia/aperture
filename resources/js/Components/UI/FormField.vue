<script setup>
const props = defineProps({
    label: { type: String, required: true },
    name: { type: String, required: true },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
});

const errorId = `${props.name}-error`;
const hasError = !!props.error;
</script>

<template>
    <div :data-testid="'form-field-' + name" class="space-y-1.5">
        <label
            :for="name"
            class="block text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
        >
            {{ label }}
            <span v-if="required" class="text-[var(--color-danger)]" aria-label="required">*</span>
            <span v-else class="ml-1 text-xs font-normal text-[var(--color-text-muted)]">(optional)</span>
        </label>
        <slot :error-id="errorId" :has-error="hasError" />
        <p
            v-if="error"
            :id="errorId"
            data-testid="form-field-error"
            class="text-xs text-[var(--color-danger)]"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
