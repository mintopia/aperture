<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue';
import { Link } from '@inertiajs/vue3';

defineProps({
    user: { type: Object, required: true },
});

const open = ref(false);
const menuRef = ref(null);
const triggerRef = ref(null);
const focusedIndex = ref(-1);

function getMenuItems() {
    if (!menuRef.value) {
        return [];
    }

    return Array.from(menuRef.value.querySelectorAll('[role="menuitem"]'));
}

function handleClickOutside(e) {
    if (menuRef.value && !menuRef.value.contains(e.target)) {
        open.value = false;
    }
}

function openMenu() {
    open.value = true;
    focusedIndex.value = -1;
}

function closeMenu() {
    open.value = false;
    focusedIndex.value = -1;

    nextTick(() => {
        if (triggerRef.value) {
            triggerRef.value.focus();
        }
    });
}

function focusItem(index) {
    const items = getMenuItems();

    if (items.length === 0) {
        return;
    }

    const clamped = Math.max(0, Math.min(index, items.length - 1));
    focusedIndex.value = clamped;
    items[clamped].focus();
}

function handleTriggerKeydown(e) {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        openMenu();
        nextTick(() => {
            focusItem(e.key === 'ArrowDown' ? 0 : getMenuItems().length - 1);
        });
    } else if (e.key === 'Escape') {
        closeMenu();
    }
}

function handleMenuKeydown(e) {
    const items = getMenuItems();
    const count = items.length;

    if (e.key === 'Escape') {
        e.preventDefault();
        closeMenu();
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusItem(focusedIndex.value < count - 1 ? focusedIndex.value + 1 : 0);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusItem(focusedIndex.value > 0 ? focusedIndex.value - 1 : count - 1);
    } else if (e.key === 'Enter' || e.key === ' ') {
        if (focusedIndex.value >= 0 && items[focusedIndex.value]) {
            items[focusedIndex.value].click();
        }
    } else if (e.key === 'Tab') {
        closeMenu();
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside));
onUnmounted(() => document.removeEventListener('click', handleClickOutside));
</script>

<template>
    <div ref="menuRef" class="relative">
        <button
            ref="triggerRef"
            data-testid="user-menu-trigger"
            aria-haspopup="menu"
            :aria-expanded="open"
            class="flex items-center gap-2 rounded-lg px-2 py-1 transition-colors hover:bg-[var(--color-surface-hover)]"
            @click="open = !open"
            @keydown="handleTriggerKeydown"
        >
            <img
                v-if="user.avatar_url"
                :src="user.avatar_url"
                :alt="user.nickname"
                class="h-[26px] w-[26px] rounded-full object-cover"
            />
            <div
                v-else
                class="font-heading flex h-[26px] w-[26px] items-center justify-center rounded-full bg-[var(--color-accent-dim)] text-[11px] font-bold text-[var(--color-primary)]"
            >
                {{ user.nickname?.charAt(0)?.toUpperCase() }}
            </div>
            <span class="hidden text-[12px] font-medium text-[var(--color-text-secondary)] sm:inline">{{
                user.nickname
            }}</span>
            <svg
                class="h-4 w-4 shrink-0 text-[var(--color-text-muted)] transition-transform"
                :class="{ 'rotate-180': open }"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke-width="1.5"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="open"
                data-testid="user-menu-dropdown"
                role="menu"
                class="absolute top-full right-0 z-50 mt-2 w-52 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] py-1 shadow-lg"
                @keydown="handleMenuKeydown"
            >
                <!-- Dashboard -->
                <a
                    :href="route('portal.dashboard')"
                    data-testid="user-menu-dashboard"
                    role="menuitem"
                    tabindex="-1"
                    class="flex items-center gap-2.5 px-4 py-2 text-sm text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                    @click="open = false"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="h-4 w-4 shrink-0"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"
                        />
                    </svg>
                    <span>Dashboard</span>
                </a>

                <!-- Admin (admin only) -->
                <Link
                    v-if="user.is_admin"
                    :href="route('admin.home')"
                    data-testid="user-menu-admin"
                    role="menuitem"
                    tabindex="-1"
                    class="flex items-center gap-2.5 px-4 py-2 text-sm text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                    @click="open = false"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="h-4 w-4 shrink-0"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z"
                        />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    <span>Admin</span>
                </Link>

                <!-- Settings (admin only) -->
                <a
                    v-if="user.is_admin"
                    :href="route('account.settings')"
                    data-testid="user-menu-settings"
                    role="menuitem"
                    tabindex="-1"
                    class="flex items-center gap-2.5 px-4 py-2 text-sm text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                    @click="open = false"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="h-4 w-4 shrink-0"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"
                        />
                    </svg>
                    <span>Settings</span>
                </a>

                <div class="my-1 border-t border-[var(--color-border)]" />

                <!-- Logout -->
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    data-testid="user-menu-logout"
                    role="menuitem"
                    tabindex="-1"
                    class="flex w-full items-center gap-2.5 px-4 py-2 text-sm text-[var(--color-danger)] transition-colors hover:bg-[var(--color-surface-hover)]"
                    @click="open = false"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        class="h-4 w-4 shrink-0"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9"
                        />
                    </svg>
                    <span>Logout</span>
                </Link>
            </div>
        </Transition>
    </div>
</template>
