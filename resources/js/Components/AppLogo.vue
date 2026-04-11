<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const appName = computed(() => page.props.appName || 'Aperture');
</script>

<template>
    <div class="flex items-center gap-2">
        <svg
            xmlns="http://www.w3.org/2000/svg"
            class="h-8 w-8 text-[var(--color-primary)]"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <circle cx="12" cy="12" r="10" />
            <circle cx="12" cy="12" r="6" />
            <circle cx="12" cy="12" r="2" />
        </svg>
        <span class="text-lg font-bold text-[var(--color-text)]">{{ appName }}</span>
    </div>
</template>
