<script setup>
import { computed } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, default: 'Confirm' },
    cancelLabel: { type: String, default: 'Cancel' },
    variant: {
        type: String,
        default: 'danger',
        validator: (value) => ['danger', 'warning', 'primary'].includes(value),
    },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['confirm', 'cancel']);

const confirmButtonClass = computed(() => {
    const variants = {
        danger: 'bg-[var(--color-danger)] hover:bg-[var(--color-danger)]/80',
        warning: 'bg-[var(--color-warning)] hover:bg-[var(--color-warning)]/80',
        primary: 'bg-[var(--color-primary)] hover:bg-[var(--color-primary)]/80',
    };

    return variants[props.variant] ?? variants.danger;
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            data-testid="confirm-modal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
        >
            <div class="w-full max-w-md rounded-lg bg-[var(--color-surface)] p-6 text-[var(--color-text)] shadow-xl">
                <h2 data-testid="confirm-modal-title" class="text-lg font-bold text-[var(--color-text)]">
                    {{ title }}
                </h2>
                <p data-testid="confirm-modal-message" class="mt-2 text-sm text-[var(--color-text-secondary)]">
                    {{ message }}
                </p>

                <slot />

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        data-testid="confirm-modal-cancel"
                        type="button"
                        class="rounded-lg px-3.5 py-2 text-sm font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="loading"
                        @click="emit('cancel')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        data-testid="confirm-modal-confirm"
                        type="button"
                        class="rounded-lg px-3.5 py-2 text-sm font-semibold text-white transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="confirmButtonClass"
                        :disabled="loading"
                        @click="emit('confirm')"
                    >
                        {{ loading ? `${confirmLabel}…` : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
