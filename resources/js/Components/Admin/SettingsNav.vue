<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const currentUrl = computed(() => usePage().url);

const navItems = [
    { label: 'Integrations', href: '/admin/settings/integrations' },
    { label: 'Theme', href: '/admin/settings/theme' },
    { label: 'Event', href: '/admin/settings/event' },
    { label: 'Portal', href: '/admin/settings/portal' },
];

function isActive(href) {
    return currentUrl.value.startsWith(href);
}
</script>

<template>
    <div data-testid="settings-nav" class="flex flex-col lg:flex-row lg:gap-0">
        <!-- Desktop: vertical sub-nav / Tablet+: horizontal -->
        <nav class="shrink-0 border-b border-[var(--color-border)] p-2 lg:w-[172px] lg:border-b-0 lg:border-r-2 lg:py-3">
            <p class="hidden px-2 pb-1 text-[8px] font-bold uppercase tracking-[1.5px] text-[var(--color-text-muted)] lg:block">Settings</p>
            <div class="flex flex-wrap gap-1 lg:flex-col">
                <Link v-for="item in navItems" :key="item.href" :href="item.href"
                    :data-testid="'settings-nav-' + item.label.toLowerCase()"
                    :class="isActive(item.href)
                        ? 'bg-[var(--color-primary)]/10 text-[var(--color-primary)] font-semibold'
                        : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]'"
                    class="rounded-md px-2.5 py-1.5 text-[11px] transition-colors">
                    {{ item.label }}
                </Link>
            </div>
        </nav>
        <!-- Content area -->
        <div class="min-w-0 flex-1 p-4 lg:p-6">
            <slot />
        </div>
    </div>
</template>
