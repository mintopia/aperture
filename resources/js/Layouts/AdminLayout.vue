<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Breadcrumbs from '@/Components/Admin/Breadcrumbs.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import Sidebar from '@/Components/Admin/Sidebar.vue';
import GlobalSearch from '@/Components/Admin/GlobalSearch.vue';
import UserMenu from '@/Components/UserMenu.vue';
import FlashMessages from '@/Components/UI/FlashMessages.vue';

const page = usePage();
const sidebarRef = ref(null);

function toggleDrawer() {
    if (sidebarRef.value) {
        sidebarRef.value.drawerOpen = !sidebarRef.value.drawerOpen;
    }
}
</script>

<template>
    <div
        data-testid="admin-layout"
        class="isolate flex min-h-screen flex-col bg-[var(--color-bg)] min-[1025px]:flex-row"
    >
        <div class="ambient-field" aria-hidden="true"><span /><span /></div>
        <div
            class="pointer-events-none fixed inset-x-0 top-0 z-40 h-4 bg-gradient-to-b from-[var(--color-bg)] to-transparent min-[1025px]:left-[240px]"
            aria-hidden="true"
        />
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[100] focus:rounded focus:bg-[var(--color-surface)] focus:px-4 focus:py-2 focus:text-[var(--color-text)] focus:shadow-lg"
            data-testid="skip-nav"
        >
            Skip to content
        </a>

        <Sidebar ref="sidebarRef" />

        <div class="flex min-w-0 flex-1 flex-col">
            <header
                data-testid="admin-header"
                class="glass-frost sticky top-2.5 z-50 mx-2.5 mt-2.5 flex h-[52px] items-center justify-between rounded-2xl pr-3 pl-4 md:pl-5"
            >
                <div class="flex items-center gap-3">
                    <button
                        data-testid="admin-menu-toggle"
                        aria-label="Toggle navigation menu"
                        class="rounded-full p-1.5 text-[var(--color-text-muted)] hover:bg-[var(--glass-hover)] hover:text-[var(--color-text)] min-[1025px]:hidden"
                        @click="toggleDrawer"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-5 w-5">
                            <path
                                fill-rule="evenodd"
                                d="M2 4.75A.75.75 0 012.75 4h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 4.75zM2 10a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75A.75.75 0 012 10zm0 5.25a.75.75 0 01.75-.75h14.5a.75.75 0 010 1.5H2.75a.75.75 0 01-.75-.75z"
                                clip-rule="evenodd"
                            />
                        </svg>
                    </button>
                    <Breadcrumbs />
                </div>

                <div class="flex items-center gap-2">
                    <GlobalSearch class="hidden sm:block" />
                    <ThemeToggle />
                    <div v-if="page.props.auth.user">
                        <UserMenu :user="page.props.auth.user" />
                    </div>
                </div>
            </header>

            <FlashMessages />

            <main id="main-content" class="mx-auto w-full max-w-[1400px] flex-1 px-6 pt-8 pb-16 md:px-10">
                <slot />
            </main>
        </div>
    </div>
</template>
