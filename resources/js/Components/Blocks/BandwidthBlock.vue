<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { formatBytes } from '@/helpers.js';

const props = defineProps({
    stats: {
        type: Object,
        default: () => ({ stats: [], totalReceived: 0, totalSent: 0 }),
    },
});

const bandwidthData = ref(props.stats);
let pollInterval = null;

async function fetchBandwidth() {
    try {
        const response = await fetch(route('portal.stats.bandwidth'));
        if (response.ok) {
            bandwidthData.value = await response.json();
        }
    } catch (_e) {
        // Silently fail — data will refresh next interval
    }
}

onMounted(() => {
    fetchBandwidth();
    pollInterval = setInterval(fetchBandwidth, 30000);
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
        <h3 class="mb-3 text-lg font-semibold text-[var(--color-text)]">Your Bandwidth</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-xs text-[var(--color-text-muted)]">Downloaded</p>
                <p class="text-xl font-bold text-[var(--color-accent)]">
                    {{ formatBytes(bandwidthData.totalReceived) }}
                </p>
            </div>
            <div>
                <p class="text-xs text-[var(--color-text-muted)]">Uploaded</p>
                <p class="text-xl font-bold text-[var(--color-primary)]">
                    {{ formatBytes(bandwidthData.totalSent) }}
                </p>
            </div>
        </div>
    </div>
</template>
