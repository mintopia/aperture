<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    user: { type: Object, required: true },
});

const open = ref(false);
const menuRef = ref(null);

function handleClickOutside(e) {
    if (menuRef.value && !menuRef.value.contains(e.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div ref="menuRef" class="relative">
        <button
            data-testid="user-menu-trigger"
            class="flex items-center gap-2 rounded-lg px-2 py-1 transition-colors hover:bg-[var(--color-surface-hover)]"
            @click="open = !open"
        >
            <img
                v-if="user.avatar_url"
                :src="user.avatar_url"
                :alt="user.nickname"
                class="h-8 w-8 rounded-full object-cover"
            />
            <div
                v-else
                class="flex h-8 w-8 items-center justify-center rounded-full bg-[var(--color-primary)] text-xs font-bold text-white"
            >
                {{ user.nickname?.charAt(0)?.toUpperCase() }}
            </div>
            <span class="hidden text-sm text-[var(--color-text)] sm:inline">{{ user.nickname }}</span>
            <svg
                class="h-4 w-4 text-[var(--color-text-muted)] transition-transform"
                :class="{ 'rotate-180': open }"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div
            v-if="open"
            data-testid="user-menu-dropdown"
            class="absolute right-0 top-full z-50 mt-2 w-48 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] py-1 shadow-lg"
        >
            <Link
                :href="route('home')"
                data-testid="user-menu-dashboard"
                class="flex items-center gap-2 px-4 py-2 text-sm text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                @click="open = false"
            >
                <span>📊</span>
                <span>Dashboard</span>
            </Link>

            <Link
                v-if="user.is_admin"
                :href="route('admin.home')"
                data-testid="user-menu-admin"
                class="flex items-center gap-2 px-4 py-2 text-sm text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                @click="open = false"
            >
                <span>⚙️</span>
                <span>Admin</span>
            </Link>

            <Link
                v-if="user.is_admin"
                :href="route('account.settings')"
                data-testid="user-menu-settings"
                class="flex items-center gap-2 px-4 py-2 text-sm text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                @click="open = false"
            >
                <span>🔑</span>
                <span>Settings</span>
            </Link>

            <div class="my-1 border-t border-[var(--color-border)]" />

            <Link
                :href="route('logout')"
                data-testid="user-menu-logout"
                class="flex items-center gap-2 px-4 py-2 text-sm text-[var(--color-danger)] transition-colors hover:bg-[var(--color-surface-hover)]"
                @click="open = false"
            >
                <span>🚪</span>
                <span>Logout</span>
            </Link>
        </div>
    </div>
</template>
