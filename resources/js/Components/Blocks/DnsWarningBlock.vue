<script setup>
import { computed } from 'vue';

const props = defineProps({
    hasDnsIssue: Boolean,
    expectedDns: { type: String, default: '' },
    actualDns: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
});

const resolvedExpectedDns = computed(() => {
    return props.expectedDns || props.settings?.expectedDns || '';
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
        <div>
            <strong>Custom DNS detected.</strong> Your device is using
            <span class="font-mono">{{ actualDns }}</span> instead of
            <span class="font-mono font-bold">{{ resolvedExpectedDns }}</span
            >. Some network features (ad blocking, local domains) may not work correctly.
        </div>
    </div>
</template>
