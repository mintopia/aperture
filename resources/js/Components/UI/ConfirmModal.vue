<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

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
const dialogRef = ref(null);
const modalId = `confirm-modal-${Math.random().toString(36).slice(2, 10)}`;
const titleId = `${modalId}-title`;
const descriptionId = `${modalId}-description`;

const confirmButtonClass = computed(() => {
    const variants = {
        danger: 'border border-[var(--color-danger)]/40 text-[var(--color-danger)] hover:bg-[var(--color-danger)]/12',
        warning:
            'border border-[var(--color-warning)]/40 text-[var(--color-warning)] hover:bg-[var(--color-warning)]/12',
        primary: 'bg-[var(--color-primary)] text-white hover:bg-[var(--color-primary)]/80',
    };

    return variants[props.variant] ?? variants.danger;
});

watch(
    () => props.show,
    (show) => {
        if (!show) dialogRef.value?.close();
    },
    { flush: 'pre' },
);

function setDialog(el) {
    dialogRef.value = el;
    if (el && !el.open) el.showModal();
}

function onClose() {
    if (props.show && dialogRef.value && !dialogRef.value.open) dialogRef.value.showModal();
}

onBeforeUnmount(() => dialogRef.value?.close());
</script>

<template>
    <Transition name="modal">
        <dialog
            v-if="show"
            :ref="setDialog"
            data-testid="confirm-modal"
            :aria-labelledby="titleId"
            :aria-describedby="descriptionId"
            class="m-auto w-full max-w-md rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-0 text-[var(--color-text)] shadow-xl backdrop:bg-black/50 backdrop:backdrop-blur-[1px] focus:outline-none"
            @cancel.prevent="emit('cancel')"
            @close="onClose"
            @click.self="emit('cancel')"
        >
            <div class="p-6">
                <h2
                    :id="titleId"
                    data-testid="confirm-modal-title"
                    class="font-heading text-[14px] font-bold text-[var(--color-text)]"
                >
                    {{ title }}
                </h2>
                <p
                    :id="descriptionId"
                    data-testid="confirm-modal-message"
                    class="mt-2 text-[13px] text-[var(--color-text-secondary)]"
                >
                    {{ message }}
                </p>

                <slot />

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        data-testid="confirm-modal-cancel"
                        type="button"
                        class="rounded-md border border-[var(--color-border-hover)] px-4 py-2 text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="loading"
                        @click="emit('cancel')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        data-testid="confirm-modal-confirm"
                        type="button"
                        class="rounded-md px-4 py-2 text-[13px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="confirmButtonClass"
                        :disabled="loading"
                        @click="emit('confirm')"
                    >
                        {{ loading ? `${confirmLabel}…` : confirmLabel }}
                    </button>
                </div>
            </div>
        </dialog>
    </Transition>
</template>

<style scoped>
.modal-enter-active {
    transition:
        opacity 200ms ease-out,
        transform 200ms cubic-bezier(0.16, 1, 0.3, 1);
}
.modal-enter-from {
    opacity: 0;
    transform: scale(0.96);
}
</style>
