<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import UserMenu from '@/Components/UserMenu.vue';

const page = usePage();
</script>

<template>
    <div data-testid="portal-layout" class="min-h-screen bg-[var(--color-bg)]">
        <!-- Header -->
        <header
            data-testid="portal-header"
            class="sticky top-0 z-40 border-b border-[var(--color-border)] bg-[var(--color-surface)]/80 backdrop-blur-sm"
        >
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div
                    class="flex h-14 flex-col justify-center gap-2 sm:h-14 sm:flex-row sm:items-center sm:justify-between"
                >
                    <Link :href="route('home')" class="flex items-center">
                        <AppLogo class="drop-shadow-[0_0_12px_var(--color-glow)]" />
                    </Link>

                    <div class="flex items-center gap-3">
                        <ThemeToggle />

                        <div v-if="page.props.auth.user" class="flex items-center gap-3">
                            <UserMenu :user="page.props.auth.user" />
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <slot />
        </main>
    </div>
</template>
