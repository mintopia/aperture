<script setup>
import { computed } from 'vue';

const props = defineProps({
    title: { type: String, default: '' },
    content: { type: String, default: '' },
    settings: { type: Object, default: () => ({}) },
    blockContext: { type: Object, default: () => ({}) },
});

const lat = computed(() => Math.max(-90, Math.min(90, props.settings?.lat ?? 51.5074)));
const lng = computed(() => Math.max(-180, Math.min(180, props.settings?.lng ?? -0.1278)));
const zoom = computed(() => Math.max(1, Math.min(19, props.settings?.zoom ?? 13)));

const showTitle = computed(() => props.settings?.showTitle !== false);

const bboxSpread = computed(() => 0.5 / Math.pow(2, zoom.value - 10));

const embedUrl = computed(
    () =>
        `https://www.openstreetmap.org/export/embed.html?bbox=${lng.value - bboxSpread.value},${lat.value - bboxSpread.value / 2},${lng.value + bboxSpread.value},${lat.value + bboxSpread.value / 2}&layer=mapnik&marker=${lat.value},${lng.value}`,
);
</script>

<template>
    <div data-testid="block-map">
        <h3
            v-if="showTitle"
            data-testid="map-title"
            class="font-heading mb-3 text-xs font-bold tracking-wider text-[var(--color-text-muted)] uppercase"
        >
            {{ title }}
        </h3>
        <div data-testid="map-container" class="-mx-5 -mb-5 overflow-hidden rounded-b-md">
            <iframe
                :src="embedUrl"
                class="h-48 w-full border-0"
                sandbox="allow-scripts allow-same-origin"
                loading="lazy"
                referrerpolicy="no-referrer"
                :title="title || `Map at ${lat}, ${lng}`"
            />
        </div>
    </div>
</template>
