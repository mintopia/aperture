<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const currentUrl = computed(() => usePage().url);

const navGroups = [
    {
        label: 'INTEGRATIONS',
        items: [
            { label: 'Services', href: route('admin.settings.integrations') },
            { label: 'Switches', href: route('admin.switches.index') },
            { label: 'IPv6 Detection', disabled: true },
            { label: 'DNS Detection', disabled: true },
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
    return currentUrl.value.startsWith(href);
}

function testId(label) {
    return `settings-nav-${label.toLowerCase().replace(/\s+/g, '-')}`;
}

function itemClass(item) {
    if (item.disabled) {
        return 'cursor-not-allowed opacity-40 text-[var(--color-text-muted)]';
    }

    return isActive(item.href)
        ? 'bg-[var(--color-primary)]/[0.14] text-[var(--color-primary)] font-semibold'
        : 'text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]';
}
</script>

<template>
    <div data-testid="settings-nav" class="flex flex-col lg:flex-row lg:gap-0">
        <nav class="shrink-0 border-b border-[var(--color-border)] p-2 lg:w-[172px] lg:border-r lg:border-b-0 lg:py-3">
            <div class="flex flex-wrap gap-3 lg:flex-col lg:gap-0">
                <div
                    v-for="(group, index) in navGroups"
                    :key="group.label"
                    :class="index > 0 ? 'mt-3' : ''"
                    class="flex min-w-[120px] flex-col gap-1"
                >
                    <p
                        class="font-body px-2 pb-1 text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                    >
                        {{ group.label }}
                    </p>

                    <template v-for="item in group.items" :key="item.label">
                        <span
                            v-if="item.disabled"
                            :data-testid="testId(item.label)"
                            :class="itemClass(item)"
                            class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[13px]"
                        >
                            {{ item.label }}
                            <span
                                class="rounded bg-[var(--color-surface-hover)] px-1 py-0.5 text-[9px] leading-none text-[var(--color-text-muted)]"
                                >Soon</span
                            >
                        </span>
                        <component
                            :is="Link"
                            v-else
                            :href="item.href"
                            :data-testid="testId(item.label)"
                            :class="itemClass(item)"
                            class="rounded-md px-2 py-1.5 text-[13px] transition-all duration-100"
                        >
                            {{ item.label }}
                        </component>
                    </template>
                </div>
            </div>
        </nav>
        <div class="min-w-0 flex-1 p-4 lg:p-6">
            <slot />
        </div>
    </div>
</template>
