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
    <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
        <h3 class="mb-3 text-lg font-semibold text-[var(--color-text)]">
            {{ title ?? 'Ad Blocking' }}
        </h3>
        <p v-if="content" class="mb-4 text-sm text-[var(--color-text-secondary)]">
            {{ content }}
        </p>
        <div class="flex items-center justify-between">
            <span class="text-sm text-[var(--color-text-secondary)]">
                {{ enabled ? 'Enabled' : 'Disabled' }}
            </span>
            <button
                :disabled="loading"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none"
                :class="enabled ? 'bg-[var(--color-success)]' : 'bg-[var(--color-border)]'"
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
