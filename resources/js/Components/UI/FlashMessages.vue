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
        bg: 'bg-[var(--color-info)]/10',
        text: 'text-[var(--color-info)]',
        border: 'border-[var(--color-info)]/20',
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
    <div
        data-testid="flash-messages"
        class="fixed top-16 left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-3"
    >
        <TransitionGroup
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="-translate-y-4 opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="-translate-y-4 opacity-0"
        >
            <div
                v-for="msg in messages"
                :key="msg.id"
                :data-testid="`flash-message-${msg.type}`"
                :class="[typeConfig[msg.type].bg, typeConfig[msg.type].text, typeConfig[msg.type].border]"
                class="relative flex w-80 items-start gap-3 rounded border p-4 shadow-lg backdrop-blur-sm"
                role="alert"
            >
                <svg
                    v-if="msg.type === 'success'"
                    data-testid="flash-checkmark"
                    class="mt-0.5 h-3.5 w-3.5 shrink-0 text-[var(--color-success)]"
                    viewBox="0 0 16 16"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path class="flash-checkmark-path" pathLength="1" d="M3.5 8.5L6.5 11.5L12.5 4.5" />
                </svg>
                <span v-else class="mt-0.5 font-mono text-sm leading-none" aria-hidden="true">
                    {{ typeConfig[msg.type].icon }}
                </span>
                <p class="flex-1 text-[13px] leading-snug text-[var(--color-text)]">
                    {{ msg.text }}
                </p>
                <div
                    v-if="autoDismissTypes.includes(msg.type)"
                    data-testid="flash-timer"
                    class="absolute inset-x-0 bottom-0 h-[2px] overflow-hidden rounded-b"
                >
                    <div class="flash-timer-bar h-full bg-current opacity-30" :class="typeConfig[msg.type].text" />
                </div>
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

<style scoped>
.flash-checkmark-path {
    stroke-dasharray: 1;
    stroke-dashoffset: 1;
    animation: flash-checkmark-draw 400ms ease-out 100ms forwards;
}

@keyframes flash-checkmark-draw {
    to {
        stroke-dashoffset: 0;
    }
}

.flash-timer-bar {
    transform-origin: left;
    animation: flash-timer-shrink 5000ms linear forwards;
}

@keyframes flash-timer-shrink {
    from {
        transform: scaleX(1);
    }
    to {
        transform: scaleX(0);
    }
}
</style>
