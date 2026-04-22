<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const appName = computed(() => page.props.appName || 'Aperture');
</script>

<template>
    <span class="font-heading text-lg font-bold text-[var(--color-text)]" data-testid="app-logo">
        {{ appName }}
    </span>
</template>
