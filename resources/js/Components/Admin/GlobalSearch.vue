<script setup>
import { nextTick, ref, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const open = ref(false);
const query = ref('');
const results = ref({ users: [], ips: [] });
const loading = ref(false);
const error = ref('');
const input = ref(null);
const dialogRef = ref(null);
const overlayRef = ref(null);
const previousFocusedElement = ref(null);
const debounceTimer = ref(null);

function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        open.value = !open.value;
    }
    if (e.key === 'Escape') {
        open.value = false;
    }
}

function getFocusableElements() {
    if (!dialogRef.value) return [];
    return [
        ...dialogRef.value.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ];
}

function onOverlayKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        open.value = false;
        return;
    }

    if (event.key !== 'Tab') return;

    const focusable = getFocusableElements();
    if (focusable.length === 0) return;

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
}

async function search() {
    if (query.value.length < 2) {
        results.value = { users: [], ips: [] };
        return;
    }
    loading.value = true;
    try {
        error.value = '';
        const response = await fetch(route('admin.search') + '?q=' + encodeURIComponent(query.value));
        if (response.ok) {
            results.value = await response.json();
        }
    } catch (_e) {
        error.value = 'Search is temporarily unavailable.';
        results.value = { users: [], ips: [] };
    } finally {
        loading.value = false;
    }
}

watch(query, () => {
    clearTimeout(debounceTimer.value);
    debounceTimer.value = setTimeout(search, 300);
});

watch(open, async (isOpen) => {
    if (isOpen) {
        previousFocusedElement.value = document.activeElement;
        await nextTick();
        input.value?.focus();
    } else {
        const target = previousFocusedElement.value;
        if (target && typeof target.focus === 'function') {
            target.focus();
        }
    }
});

function navigateToUser(userId) {
    open.value = false;
    router.visit(route('admin.users.show', userId));
}

function navigateToIp(ipAddress) {
    open.value = false;
    router.visit(route('admin.ips.show', ipAddress));
}

onMounted(() => document.addEventListener('keydown', handleKeydown));
onUnmounted(() => document.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <div>
        <button
            data-testid="global-search-trigger"
            class="flex items-center gap-1 rounded border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-[7px] text-[12px] text-[var(--color-text-muted)]"
            @click="open = true"
        >
            Search...
            <kbd
                data-testid="global-search-shortcut"
                class="ml-2 inline-flex items-center gap-1 rounded border border-[var(--color-border-hover)] px-1.5 py-0.5 font-mono text-[10px] text-[var(--color-text-muted)]"
            >
                ⌘K
            </kbd>
        </button>

        <div
            v-if="open"
            ref="overlayRef"
            data-testid="global-search-overlay"
            class="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
            @click.self="open = false"
            @keydown="onOverlayKeydown"
        >
            <div class="fixed inset-0 bg-black/50" />
            <div
                ref="dialogRef"
                data-testid="global-search-dialog"
                role="dialog"
                aria-modal="true"
                aria-label="Search"
                class="relative w-full max-w-lg rounded border border-[var(--color-border)] bg-[var(--color-surface)] shadow-2xl"
            >
                <div class="relative">
                    <svg
                        class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-[var(--color-text-muted)]"
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            fill-rule="evenodd"
                            d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    <input
                        ref="input"
                        v-model="query"
                        data-testid="global-search-input"
                        aria-label="Search users and IP addresses"
                        autofocus
                        placeholder="Search users, IPs..."
                        class="w-full rounded-t border-b border-[var(--color-border-hover)] bg-[var(--color-surface)] py-[7px] pr-3 pl-8 text-[13px] text-[var(--color-text)] outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                    />
                </div>
                <div class="max-h-80 overflow-y-auto p-2">
                    <div v-if="loading" class="flex justify-center py-6" data-testid="search-loading">
                        <svg
                            class="h-5 w-5 animate-spin text-[var(--color-text-muted)]"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            />
                        </svg>
                    </div>
                    <p v-if="error" class="px-4 py-3 text-[13px] text-[var(--color-danger)]" data-testid="search-error">
                        {{ error }}
                    </p>
                    <div v-if="results.users?.length" class="mb-2">
                        <p
                            data-testid="global-search-section-users"
                            class="px-2 py-1 text-xs font-medium text-[var(--color-text-muted)] uppercase"
                        >
                            Users
                        </p>
                        <button
                            v-for="user in results.users"
                            :key="user.id"
                            :data-testid="`global-search-result-user-${user.id}`"
                            class="flex w-full items-center gap-3 rounded px-3 py-2 text-[13px] text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                            @click="navigateToUser(user.id)"
                        >
                            <span>{{ user.nickname }}</span>
                            <span class="text-[var(--color-text-muted)]">{{ user.email }}</span>
                        </button>
                    </div>
                    <div v-if="results.ips?.length">
                        <p
                            data-testid="global-search-section-ips"
                            class="px-2 py-1 text-xs font-medium text-[var(--color-text-muted)] uppercase"
                        >
                            IP Addresses
                        </p>
                        <button
                            v-for="ip in results.ips"
                            :key="ip.id"
                            :data-testid="`global-search-result-ip-${ip.id}`"
                            class="flex w-full items-center gap-3 rounded px-3 py-2 font-mono text-[13px] text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                            @click="navigateToIp(ip.address)"
                        >
                            {{ ip.address }}
                        </button>
                    </div>
                    <p
                        v-if="query.length >= 2 && !loading && !error && !results.users?.length && !results.ips?.length"
                        data-testid="global-search-empty"
                        class="px-3 py-4 text-center text-[13px] text-[var(--color-text-muted)]"
                    >
                        No results found.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
