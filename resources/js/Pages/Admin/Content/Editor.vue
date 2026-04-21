<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    blocks: { type: Array, default: () => [] },
    singletonTypes: { type: Array, default: () => [] },
    existingTypes: { type: Array, default: () => [] },
});
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
        >
            Grid Editor
        </h1>
    </div>
</template>
