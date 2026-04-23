<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    settings: { type: Object, default: () => ({}) },
});
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            Network Settings
        </h1>

        <p class="mb-6 text-[13px] text-[var(--color-text-muted)]">
            Configure managed network ranges and DNS filtering defaults.
        </p>
    </div>
</template>
