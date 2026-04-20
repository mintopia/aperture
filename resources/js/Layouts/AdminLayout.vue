<script setup>
import { usePage } from '@inertiajs/vue3';
import Breadcrumbs from '@/Components/Admin/Breadcrumbs.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Sidebar from '@/Components/Admin/Sidebar.vue';
import GlobalSearch from '@/Components/Admin/GlobalSearch.vue';
import UserMenu from '@/Components/UserMenu.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';

const page = usePage();
</script>

<template>
    <div data-testid="admin-layout" class="flex min-h-screen bg-[var(--color-bg)]">
        <Sidebar />

        <div class="flex min-w-0 flex-1 flex-col">
            <!-- Topbar: breadcrumbs left, controls right -->
            <header
                data-testid="admin-header"
                class="sticky top-0 z-50 flex h-12 items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-surface)] px-6"
            >
                <Breadcrumbs />

                <div class="flex items-center gap-4">
                    <GlobalSearch class="hidden sm:block" />
                    <ThemeToggle />
                    <div v-if="page.props.auth.user">
                        <UserMenu :user="page.props.auth.user" />
                    </div>
                </div>
            </header>

            <FlashMessages />

            <main class="mx-auto w-full max-w-[1400px] flex-1 px-10 pt-8 pb-16">
                <slot />
            </main>
        </div>
    </div>
</template>
