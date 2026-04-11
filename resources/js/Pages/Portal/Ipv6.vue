<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });
</script>

<template>
    <div class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Register IPv6 Address
        </h1>

        <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5">
            <p class="mb-3 text-sm text-[var(--color-text-secondary)]">
                If your device has an IPv6 address, register it below to enable internet access for it.
            </p>

            <p class="text-sm text-[var(--color-text-muted)]">
                Your IPv6 address will be automatically detected and registered when your device connects. If detection
                fails, the address can be submitted via the API endpoint.
            </p>
        </div>
    </div>
</template>
