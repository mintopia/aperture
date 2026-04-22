<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });

defineProps({
    page: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading text-[28px] font-bold tracking-tight text-[var(--color-text)]"
        >
            {{ page.title }}
        </h1>
        <div
            data-testid="page-content"
            class="prose mt-4"
        >
            {{ page.content }}
        </div>
    </div>
</template>
