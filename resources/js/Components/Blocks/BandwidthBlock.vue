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
    <div data-testid="block-bandwidth">
        <h3 class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase">
            Bandwidth
        </h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">
                    Download
                </p>
                <p class="font-heading text-2xl font-bold tracking-tight text-[var(--color-success)]">
                    {{ formatBytes(bandwidthData.totalReceived) }}
                </p>
            </div>
            <div>
                <p class="text-[10px] font-semibold tracking-wider text-[var(--color-text-muted)] uppercase">Upload</p>
                <p class="font-heading text-2xl font-bold tracking-tight text-[var(--color-info)]">
                    {{ formatBytes(bandwidthData.totalSent) }}
                </p>
            </div>
        </div>
    </div>
</template>
