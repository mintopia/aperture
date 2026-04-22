<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';

defineProps({
    settings: {
        type: Object,
        required: true,
    },
    pages: {
        type: Array,
        required: true,
    },
    breadcrumbs: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <div>General Settings</div>
    </AdminLayout>
</template>
