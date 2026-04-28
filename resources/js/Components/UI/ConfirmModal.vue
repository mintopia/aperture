<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

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
const overlayRef = ref(null);
const dialogRef = ref(null);
const cancelButtonRef = ref(null);
const confirmButtonRef = ref(null);
const previousFocusedElement = ref(null);
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

function getFocusableElements() {
    if (!dialogRef.value) return [];
    return [
        ...dialogRef.value.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ];
}

function onOverlayKeydown(event) {
    if (!props.show) return;

    if (event.key === 'Escape') {
        event.preventDefault();
        emit('cancel');
        return;
    }

    if (event.key !== 'Tab') return;

    const focusable = getFocusableElements();
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.show,
    async (show) => {
        if (show) {
            previousFocusedElement.value = document.activeElement;
            await nextTick();
            (cancelButtonRef.value ?? confirmButtonRef.value)?.focus();
            return;
        }

        const target = previousFocusedElement.value;
        if (target && typeof target.focus === 'function') {
            target.focus();
        }
    },
);

onBeforeUnmount(() => {
    const target = previousFocusedElement.value;
    if (target && typeof target.focus === 'function') {
        target.focus();
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="show"
                ref="overlayRef"
                data-testid="confirm-modal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-[1px]"
                @keydown="onOverlayKeydown"
                @click.self="emit('cancel')"
            >
                <div
                    ref="dialogRef"
                    role="dialog"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    :aria-describedby="descriptionId"
                    class="w-full max-w-md rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-6 text-[var(--color-text)] shadow-xl focus:outline-none"
                >
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
                            ref="cancelButtonRef"
                            data-testid="confirm-modal-cancel"
                            type="button"
                            class="rounded-md border border-[var(--color-border-hover)] px-4 py-2 text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="loading"
                            @click="emit('cancel')"
                        >
                            {{ cancelLabel }}
                        </button>
                        <button
                            ref="confirmButtonRef"
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
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active {
    transition: opacity 200ms ease-out;
}
.modal-leave-active {
    transition: opacity 150ms ease-in;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}

.modal-enter-active [role='dialog'] {
    transition: transform 200ms cubic-bezier(0.16, 1, 0.3, 1);
}
.modal-leave-active [role='dialog'] {
    transition: transform 150ms ease-in;
}
.modal-enter-from [role='dialog'] {
    transform: scale(0.96);
}
.modal-leave-to [role='dialog'] {
    transform: scale(0.98);
}
</style>
