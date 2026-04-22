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
    if (loading.value) return;

    const previousState = enabled.value;
    enabled.value = !enabled.value;
    loading.value = true;
    try {
        const response = await fetch(route('portal.dns-filter.toggle'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
        });
        if (!response.ok) {
            enabled.value = previousState;
        }
    } catch (_e) {
        enabled.value = previousState;
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div data-testid="block-dns-filter">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold text-[var(--color-text)]">{{ displayTitle }}</div>
                <div class="mt-0.5 text-xs text-[var(--color-text-muted)]">{{ displayDescription }}</div>
            </div>
            <button
                data-testid="dns-filter-toggle"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none"
                :class="[
                    enabled ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-surface-alt)]',
                    loading ? 'opacity-60' : '',
                ]"
                @click="toggle"
            >
                <span
                    class="pointer-events-none inline-block size-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="enabled ? 'translate-x-[1.375rem]' : 'translate-x-1'"
                />
            </button>
        </div>
    </div>
</template>
