<script setup>
import { ref } from 'vue';

defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
});

const enabled = ref(false);
const loading = ref(false);

async function toggle() {
    loading.value = true;
    try {
        const response = await fetch(route('portal.pihole.toggle'), {
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
    <div data-testid="block-pihole-toggle">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            {{ title ?? 'Ad Blocking' }}
        </h3>
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm font-semibold text-[var(--color-text)]">Pi-hole</div>
                <div class="mt-0.5 text-xs text-[var(--color-text-muted)]">DNS-level ad blocking</div>
            </div>
            <button
                :disabled="loading"
                data-testid="pihole-toggle"
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
        <p
            v-if="content"
            class="mt-3 border-t border-[var(--color-border)] pt-3 text-xs text-[var(--color-text-secondary)]"
        >
            {{ content }}
        </p>
    </div>
</template>
