<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    title: { type: String, default: 'DNS Ad Blocking' },
    content: { type: String, default: 'Toggle DNS filtering for your connection.' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const displayTitle = computed(() => props.settings?.title || props.title);
const displayDescription = computed(() => props.settings?.description || props.content);

const enabled = ref(false);
const loading = ref(false);

async function toggle() {
    loading.value = true;
    try {
        const response = await fetch(route('portal.dns-filter.toggle'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
        });
        if (response.ok) {
            const data = await response.json();
            enabled.value = data.enabled;
        }
    } catch (_e) {
        // Silently fail
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div data-testid="block-dns-filter">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ displayTitle }}
        </h3>
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold text-[var(--color-text)]">DNS Filtering</div>
                <div class="mt-0.5 text-xs text-[var(--color-text-muted)]">{{ displayDescription }}</div>
            </div>
            <button
                :disabled="loading"
                data-testid="dns-filter-toggle"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors duration-200 ease-in-out focus:outline-none"
                :class="enabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-surface-alt)]'"
                @click="toggle"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="enabled ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </div>
    </div>
</template>
