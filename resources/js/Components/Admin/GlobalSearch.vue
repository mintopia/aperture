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
            class="flex items-center gap-2 rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-1.5 text-sm text-[var(--color-text-muted)]"
            @click="open = true"
        >
            Search... <kbd class="ml-2 rounded border border-[var(--color-border)] px-1.5 py-0.5 text-xs">⌘K</kbd>
        </button>

        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-start justify-center pt-[15vh]"
            @click.self="open = false"
        >
            <div class="fixed inset-0 bg-black/50" />
            <div
                class="relative w-full max-w-lg rounded-xl border border-[var(--color-border)] bg-[var(--color-bg)] shadow-2xl"
            >
                <input
                    ref="input"
                    v-model="query"
                    autofocus
                    placeholder="Search users, IPs..."
                    class="w-full rounded-t-xl border-b border-[var(--color-border)] bg-transparent px-4 py-3 text-sm text-[var(--color-text)] outline-none"
                />
                <div class="max-h-80 overflow-y-auto p-2">
                    <div v-if="results.users?.length" class="mb-2">
                        <p class="px-2 py-1 text-xs font-medium text-[var(--color-text-muted)] uppercase">Users</p>
                        <button
                            v-for="user in results.users"
                            :key="user.id"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                            @click="navigateToUser(user.id)"
                        >
                            <span>{{ user.nickname }}</span>
                            <span class="text-[var(--color-text-muted)]">{{ user.email }}</span>
                        </button>
                    </div>
                    <div v-if="results.ips?.length">
                        <p class="px-2 py-1 text-xs font-medium text-[var(--color-text-muted)] uppercase">
                            IP Addresses
                        </p>
                        <button
                            v-for="ip in results.ips"
                            :key="ip.id"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2 font-mono text-sm text-[var(--color-text)] hover:bg-[var(--color-surface-hover)]"
                            @click="navigateToIp(ip.address)"
                        >
                            {{ ip.address }}
                        </button>
                    </div>
                    <p
                        v-if="query.length >= 2 && !loading && !results.users?.length && !results.ips?.length"
                        class="px-3 py-4 text-center text-sm text-[var(--color-text-muted)]"
                    >
                        No results found.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
