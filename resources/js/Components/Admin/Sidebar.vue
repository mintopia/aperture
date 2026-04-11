<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const currentUrl = computed(() => usePage().url);
const isDesktop = ref(true);

const navItems = [
    { label: 'Dashboard', href: '/admin', icon: '📊' },
    { label: 'Users', href: '/admin/users', icon: '👥' },
    { label: 'IP Addresses', href: '/admin/ips', icon: '🌐' },
    { label: 'Ports', href: '/admin/ports', icon: '🔌' },
    { label: 'DHCP', href: '/admin/dhcp', icon: '📡' },
    { label: 'Stats', href: '/admin/stats', icon: '📈' },
    { label: 'Content', href: '/admin/content', icon: '📝' },
    { label: 'Settings', href: '/admin/settings/integrations', icon: '⚙️' },
];

function isActive(href) {
    if (href === '/admin') return currentUrl.value === '/admin';
    return currentUrl.value.startsWith(href);
}

function checkBreakpoint() {
    isDesktop.value = window.innerWidth > 1024;
}

onMounted(() => {
    checkBreakpoint();
    window.addEventListener('resize', checkBreakpoint);
});

onUnmounted(() => {
    window.removeEventListener('resize', checkBreakpoint);
});
</script>

<template>
    <!-- Desktop: 172px vertical sidebar -->
    <aside
        v-if="isDesktop"
        data-testid="admin-sidebar"
        class="flex w-[172px] shrink-0 flex-col border-r border-[var(--color-border)] bg-[var(--color-surface)]"
    >
        <div class="border-b border-[var(--color-border)] px-4 py-3">
            <span class="font-heading text-sm font-bold text-[var(--color-text)]">Admin</span>
        </div>
        <nav class="flex-1 overflow-y-auto p-2">
            <Link
                v-for="item in navItems"
                :key="item.href"
                :href="item.href"
                :data-testid="'nav-' + item.label.toLowerCase().replace(/ /g, '-')"
                :class="
                    isActive(item.href)
                        ? 'bg-[var(--color-primary)]/10 text-[var(--color-primary)]'
                        : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]'
                "
                class="mb-0.5 flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors"
            >
                <span class="text-xs">{{ item.icon }}</span>
                <span>{{ item.label }}</span>
            </Link>
        </nav>
    </aside>

    <!-- Tablet/mobile: horizontal scrollable nav -->
    <nav
        v-else
        data-testid="admin-nav-horizontal"
        class="flex items-center gap-1 overflow-x-auto border-b border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-2"
    >
        <Link
            v-for="item in navItems"
            :key="item.href"
            :href="item.href"
            :data-testid="'nav-' + item.label.toLowerCase().replace(/ /g, '-')"
            :class="
                isActive(item.href)
                    ? 'bg-[var(--color-primary)]/10 text-[var(--color-primary)]'
                    : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]'
            "
            class="flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm transition-colors"
        >
            <span class="text-xs">{{ item.icon }}</span>
            <span>{{ item.label }}</span>
        </Link>
    </nav>
</template>
