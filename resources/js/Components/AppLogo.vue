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
        {{ appName }}
    </span>
</template>
