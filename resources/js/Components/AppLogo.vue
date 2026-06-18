<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const appName = computed(() => page.props.appName || 'Aperture');
const logoUrl = computed(() => page.props.theme?.site_logo_url || null);
</script>

<template>
    <span
        class="font-heading flex items-center gap-2 text-lg font-bold text-[var(--color-text)]"
        data-testid="app-logo"
    >
        <img v-if="logoUrl" :src="logoUrl" :alt="appName" class="h-6 w-6 rounded" data-testid="app-logo-image" />
        <svg
            v-else
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-6 w-6 text-[var(--color-primary)]"
            aria-hidden="true"
            data-testid="app-logo-icon"
        >
            <circle cx="12" cy="12" r="10" />
            <line x1="14.31" y1="8" x2="20.05" y2="17.94" />
            <line x1="9.69" y1="8" x2="21.17" y2="8" />
            <line x1="7.38" y1="12" x2="13.12" y2="2.06" />
            <line x1="9.69" y1="16" x2="3.95" y2="6.06" />
            <line x1="14.31" y1="16" x2="2.83" y2="16" />
            <line x1="16.62" y1="12" x2="10.88" y2="21.94" />
        </svg>
        {{ appName }}
    </span>
</template>

<style scoped>
[data-testid='app-logo-icon'] {
    transition: transform 300ms cubic-bezier(0.16, 1, 0.3, 1);
}
[data-testid='app-logo']:hover [data-testid='app-logo-icon'] {
    transform: rotate(30deg);
}
</style>
