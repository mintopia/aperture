<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const currentUrl = computed(() => usePage().url);

const navGroups = [
    {
        label: 'INTEGRATIONS',
        items: [
            { label: 'Services', href: route('admin.settings.integrations') },
            { label: 'Switches', href: route('admin.settings.switches') },
        ],
    },
    {
        label: 'FEATURES',
        items: [
            { label: 'Auto-Allow', href: null, disabled: true },
            { label: 'IPv6 Detection', href: null, disabled: true },
            { label: 'DNS Warning', href: null, disabled: true },
        ],
    },
    {
        label: 'APPEARANCE',
        items: [{ label: 'Theme', href: route('admin.settings.theme') }],
    },
    {
        label: 'GENERAL',
        items: [
            { label: 'Event', href: route('admin.settings.event') },
            { label: 'Portal', href: route('admin.settings.portal') },
        ],
    },
];

function isActive(href) {
    return href ? currentUrl.value.startsWith(href) : false;
}

function testId(label) {
    return `settings-nav-${label.toLowerCase().replace(/\s+/g, '-')}`;
}

function itemClass(item) {
    if (item.disabled) {
        return 'cursor-not-allowed text-[var(--color-text-secondary)] opacity-40';
    }

    return isActive(item.href)
        ? 'bg-[var(--color-primary)]/10 font-semibold text-[var(--color-primary)]'
        : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]';
}
</script>

<template>
    <div data-testid="settings-nav" class="flex flex-col lg:flex-row lg:gap-0">
        <nav
            class="shrink-0 border-b border-[var(--color-border)] p-2 lg:w-[172px] lg:border-r-2 lg:border-b-0 lg:py-3"
        >
            <div class="flex flex-wrap gap-3 lg:flex-col lg:gap-0">
                <div
                    v-for="(group, index) in navGroups"
                    :key="group.label"
                    :class="index > 0 ? 'mt-3' : ''"
                    class="flex min-w-[120px] flex-col gap-1"
                >
                    <p class="px-2 pb-1 text-[8px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase">
                        {{ group.label }}
                    </p>

                    <component
                        :is="item.disabled ? 'span' : Link"
                        v-for="item in group.items"
                        :key="item.label"
                        :href="item.disabled ? undefined : item.href"
                        :data-testid="testId(item.label)"
                        :class="itemClass(item)"
                        class="rounded-md px-2.5 py-1.5 text-[11px] transition-colors"
                    >
                        {{ item.label }}
                    </component>
                </div>
            </div>
        </nav>
        <div class="min-w-0 flex-1 p-4 lg:p-6">
            <slot />
        </div>
    </div>
</template>
