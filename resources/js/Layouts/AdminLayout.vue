<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Sidebar from '@/Components/Admin/Sidebar.vue';
import GlobalSearch from '@/Components/Admin/GlobalSearch.vue';

const page = usePage();
</script>

<template>
    <div data-testid="admin-layout" class="flex min-h-screen flex-col bg-[var(--color-bg)]">
        <!-- Top bar -->
        <header
            data-testid="admin-header"
            class="sticky top-0 z-40 flex h-14 items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-surface)]/80 px-6 backdrop-blur-sm"
        >
            <div class="flex items-center gap-4">
                <AppLogo />
                <slot name="breadcrumbs" />
            </div>

            <div class="flex items-center gap-3">
                <GlobalSearch class="hidden sm:block" />
                <ThemeToggle />

                <div v-if="page.props.auth.user" class="flex items-center gap-3">
                    <span class="hidden text-sm text-[var(--color-text-secondary)] sm:inline">
                        {{ page.props.auth.user.nickname }}
                    </span>
                    <Link
                        :href="route('logout')"
                        data-testid="logout-link"
                        class="text-sm text-[var(--color-text-muted)] transition-colors hover:text-[var(--color-text)]"
                    >
                        Logout
                    </Link>
                </div>
            </div>
        </header>

        <!-- Sidebar + Content -->
        <div class="flex flex-1">
            <Sidebar />
            <main class="min-w-0 flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
