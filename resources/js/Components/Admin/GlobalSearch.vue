<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';

const open = ref(false);
const query = ref('');
const results = ref({ users: [], ips: [] });
const loading = ref(false);
let debounceTimer = null;

function handleKeydown(e) {
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
        e.preventDefault();
        open.value = !open.value;
    }
    if (e.key === 'Escape') {
        open.value = false;
    }
}

async function search() {
    if (query.value.length < 2) {
        results.value = { users: [], ips: [] };
        return;
    }
    loading.value = true;
    try {
        const response = await fetch(route('admin.search') + '?q=' + encodeURIComponent(query.value));
        if (response.ok) {
            results.value = await response.json();
        }
    } catch (_e) {
        // Silently fail
    } finally {
        loading.value = false;
    }
}

watch(query, () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(search, 300);
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
            data-testid="global-search-overlay"
            class="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
            @click.self="open = false"
        >
            <div class="fixed inset-0 bg-black/50" />
            <div
                data-testid="global-search-dialog"
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
                        autofocus
                        placeholder="Search users, IPs..."
                        class="w-full rounded-t border-b border-[var(--color-border-hover)] bg-[var(--color-surface)] py-[7px] pr-3 pl-8 text-[13px] text-[var(--color-text)] outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                    />
                </div>
                <div class="max-h-80 overflow-y-auto p-2">
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
                        v-if="query.length >= 2 && !loading && !results.users?.length && !results.ips?.length"
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
