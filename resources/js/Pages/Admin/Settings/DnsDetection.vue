<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    settings: { type: Object, default: () => ({}) },
});
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] font-bold tracking-[-0.03em] leading-[1.1] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            DNS Detection
        </h1>
        <p class="text-sm text-[var(--color-text-muted)]">Settings placeholder</p>
    </SettingsNav>
</template>
