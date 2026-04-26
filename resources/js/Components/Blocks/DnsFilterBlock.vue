<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    title: { type: String, default: 'DNS Ad Blocking' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const toggleLabel = computed(() => props.settings?.label || 'Enable DNS Filtering');

const enabled = ref(props.blockContext?.dnsFilteringEnabled ?? false);
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

/**
 * Update DNS filter state from an Echo event.
 * Called by the parent Dashboard via template ref.
 */
function updateDnsFilter(isEnabled) {
    enabled.value = isEnabled;
}

defineExpose({ updateDnsFilter });
</script>

<template>
    <div data-testid="block-dns-filter">
        <h3
            data-testid="dns-filter-title"
            class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
        >
            {{ title }}
        </h3>
        <div v-if="content" data-testid="dns-filter-content" class="mb-3 text-sm text-[var(--color-text-secondary)]">
            {{ content }}
        </div>
        <div class="flex items-center justify-between">
            <span data-testid="dns-filter-label" class="text-sm text-[var(--color-text)]">
                {{ toggleLabel }}
            </span>
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
