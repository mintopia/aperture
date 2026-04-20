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
        danger: 'bg-[var(--color-danger)] hover:bg-[var(--color-danger)]/80',
        warning: 'bg-[var(--color-warning)] hover:bg-[var(--color-warning)]/80',
        primary: 'bg-[var(--color-primary)] hover:bg-[var(--color-primary)]/80',
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
        <div
            v-if="show"
            ref="overlayRef"
            data-testid="confirm-modal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[oklch(12%_0.006_60_/_0.5)] p-4"
            @keydown="onOverlayKeydown"
        >
            <div
                ref="dialogRef"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                :aria-describedby="descriptionId"
                class="w-full max-w-md rounded-lg bg-[var(--color-surface)] p-6 text-[var(--color-text)] shadow-xl focus:outline-none"
            >
                <h2 :id="titleId" data-testid="confirm-modal-title" class="text-lg font-bold text-[var(--color-text)]">
                    {{ title }}
                </h2>
                <p
                    :id="descriptionId"
                    data-testid="confirm-modal-message"
                    class="mt-2 text-sm text-[var(--color-text-secondary)]"
                >
                    {{ message }}
                </p>

                <slot />

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button
                        ref="cancelButtonRef"
                        data-testid="confirm-modal-cancel"
                        type="button"
                        class="rounded-lg px-3.5 py-2 text-sm font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="loading"
                        @click="emit('cancel')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        ref="confirmButtonRef"
                        data-testid="confirm-modal-confirm"
                        type="button"
                        class="rounded-lg px-3.5 py-2 text-sm font-semibold text-[var(--color-bg)] transition-colors disabled:cursor-not-allowed disabled:opacity-50"
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
