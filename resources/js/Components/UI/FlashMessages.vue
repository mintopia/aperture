<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

const messages = ref([]);
const timers = new Map();
let nextId = 0;

const typeConfig = {
    success: {
        icon: '✓',
        bg: 'bg-[var(--color-success)]/10',
        text: 'text-[var(--color-success)]',
        border: 'border-[var(--color-success)]/20',
    },
    error: {
        icon: '✕',
        bg: 'bg-[var(--color-danger)]/10',
        text: 'text-[var(--color-danger)]',
        border: 'border-[var(--color-danger)]/20',
    },
    warning: {
        icon: '▲',
        bg: 'bg-[var(--color-warning)]/10',
        text: 'text-[var(--color-warning)]',
        border: 'border-[var(--color-warning)]/20',
    },
    info: {
        icon: '●',
        bg: 'bg-[var(--color-primary)]/10',
        text: 'text-[var(--color-primary)]',
        border: 'border-[var(--color-primary)]/20',
    },
};

const autoDismissTypes = ['success', 'info'];
const AUTO_DISMISS_MS = 5000;

function addMessage(type, text) {
    const id = nextId++;
    messages.value.push({ id, type, text });

    if (autoDismissTypes.includes(type)) {
        const timer = setTimeout(() => dismiss(id), AUTO_DISMISS_MS);
        timers.set(id, timer);
    }
}

function dismiss(id) {
    const timer = timers.get(id);
    if (timer) {
        clearTimeout(timer);
        timers.delete(id);
    }
    messages.value = messages.value.filter((m) => m.id !== id);
}

watch(
    () => page.props.flash,
    (flash) => {
        if (!flash) return;
        for (const type of ['success', 'error', 'warning', 'info']) {
            if (flash[type]) {
                addMessage(type, flash[type]);
            }
        }
    },
    { immediate: true, deep: true },
);

onBeforeUnmount(() => {
    for (const timer of timers.values()) {
        clearTimeout(timer);
    }
    timers.clear();
});
</script>

<template>
    <div data-testid="flash-messages" class="fixed top-16 right-4 z-50 flex flex-col gap-3">
        <TransitionGroup
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="translate-x-full opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="translate-x-0 opacity-100"
            leave-to-class="translate-x-full opacity-0"
        >
            <div
                v-for="msg in messages"
                :key="msg.id"
                :data-testid="`flash-message-${msg.type}`"
                :class="[typeConfig[msg.type].bg, typeConfig[msg.type].text, typeConfig[msg.type].border]"
                class="flex w-80 items-start gap-3 rounded-lg border px-4 py-3 shadow-lg backdrop-blur-sm"
                role="alert"
            >
                <span class="mt-0.5 font-mono text-sm leading-none" aria-hidden="true">
                    {{ typeConfig[msg.type].icon }}
                </span>
                <p class="flex-1 text-sm leading-snug text-[var(--color-text)]">
                    {{ msg.text }}
                </p>
                <button
                    data-testid="flash-dismiss"
                    class="ml-auto shrink-0 rounded p-0.5 opacity-60 transition-opacity hover:opacity-100"
                    :aria-label="`Dismiss ${msg.type} message`"
                    @click="dismiss(msg.id)"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                        <path
                            fill-rule="evenodd"
                            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                            clip-rule="evenodd"
                        />
                    </svg>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
