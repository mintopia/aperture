<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    checkUrl: { type: String, default: '' },
    warningMessage: { type: String, default: '' },
});

const hasDnsIssue = ref(false);
const checking = ref(false);
let retryTimer = null;

async function checkDns() {
    if (!props.checkUrl) return;
    checking.value = true;

    try {
        const url = props.checkUrl.replace('{uuid}', crypto.randomUUID());
        const response = await fetch(url);
        const data = await response.json();

        if (data.server === 'event') {
            hasDnsIssue.value = false;
            stopRetry();
        } else {
            hasDnsIssue.value = true;
            startRetry();
        }
    } catch {
        hasDnsIssue.value = false;
        stopRetry();
    } finally {
        checking.value = false;
    }
}

function startRetry() {
    stopRetry();
    retryTimer = setInterval(checkDns, 60000);
}

function stopRetry() {
    if (retryTimer) {
        clearInterval(retryTimer);
        retryTimer = null;
    }
}

onMounted(() => {
    if (props.checkUrl) {
        checkDns();
    }
});

onUnmounted(() => {
    stopRetry();
});
</script>

<template>
    <div
        v-if="hasDnsIssue"
        data-testid="block-dns-warning"
        class="flex items-start gap-2.5 rounded border border-[var(--color-warning)]/20 bg-[var(--color-warning)]/8 px-4 py-3.5 text-[13px] text-[var(--color-warning)]"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="18"
            height="18"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="1.5"
            stroke="currentColor"
            class="mt-px shrink-0"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"
            />
        </svg>
        <div class="flex-1">{{ warningMessage }}</div>
        <button
            data-testid="dns-refresh"
            class="mt-px shrink-0 transition-colors hover:text-[var(--color-text)]"
            :class="{ 'animate-spin': checking }"
            title="Re-check DNS"
            @click="checkDns"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                width="16"
                height="16"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182M20.016 4.356v4.992"
                />
            </svg>
        </button>
    </div>
</template>
