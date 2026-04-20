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
            class="sticky top-0 z-50 flex h-[52px] items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-surface)]/85 px-6 backdrop-blur-xl"
        >
            <Link :href="route('home')" class="flex items-center gap-2.5">
                <AppLogo />
            </Link>

            <div class="flex items-center gap-3">
                <ThemeToggle />

                <div v-if="page.props.auth.user" class="flex items-center">
                    <UserMenu :user="page.props.auth.user" />
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="mx-auto max-w-[1100px] px-6 pt-8 pb-16">
            <slot />
        </main>
    </div>
</template>
