<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import DashboardIcon from '@/Components/Icons/DashboardIcon.vue';
import UsersIcon from '@/Components/Icons/UsersIcon.vue';
import IpsIcon from '@/Components/Icons/IpsIcon.vue';
import SwitchesIcon from '@/Components/Icons/SwitchesIcon.vue';
import DhcpIcon from '@/Components/Icons/DhcpIcon.vue';
import AuditLogIcon from '@/Components/Icons/AuditLogIcon.vue';
import ContentIcon from '@/Components/Icons/ContentIcon.vue';
import MacsIcon from '@/Components/Icons/MacsIcon.vue';
import SettingsIcon from '@/Components/Icons/SettingsIcon.vue';

const page = usePage();
const currentUrl = computed(() => page.url);
const isDesktop = ref(true);
const drawerOpen = ref(false);

const navGroups = [
    {
        label: 'MANAGEMENT',
        items: [
            { label: 'Dashboard', href: route('admin.home'), icon: DashboardIcon },
            { label: 'Users', href: route('admin.users.index'), icon: UsersIcon },
            { label: 'IP Addresses', href: route('admin.ips.index'), icon: IpsIcon },
            { label: 'Switches', href: route('admin.switches.index'), icon: SwitchesIcon },
            { label: 'DHCP', href: route('admin.dhcp.index'), icon: DhcpIcon },
            { label: 'MAC Addresses', href: route('admin.macs.index'), icon: MacsIcon },
        ],
    },
    {
        label: 'SERVICES',
        items: [
            { label: 'Integrations', href: route('admin.settings.integrations'), icon: SettingsIcon },
            { label: 'IPv6 Detection', href: route('admin.settings.ipv6-detection'), icon: SettingsIcon },
            { label: 'DNS Detection', href: route('admin.settings.dns-detection'), icon: SettingsIcon },
            { label: 'Network', href: route('admin.settings.network'), icon: SettingsIcon },
            { label: 'Captive Portal API', href: route('admin.settings.captive-portal-api'), icon: SettingsIcon },
        ],
    },
    {
        label: 'CONTENT',
        items: [
            { label: 'Dashboard', href: route('admin.content.index'), icon: ContentIcon },
            { label: 'Pages', href: route('admin.content.pages.index'), icon: ContentIcon },
            { label: 'Settings', href: route('admin.content.settings'), icon: SettingsIcon },
        ],
    },
    {
        label: 'SYSTEM',
        items: [{ label: 'Audit Log', href: route('admin.audit-log.index'), icon: AuditLogIcon }],
    },
];

function isActive(href) {
    const adminHome = route('admin.home');
    const contentIndex = route('admin.content.index');
    if (href === adminHome || href === contentIndex) return currentUrl.value === href;
    return currentUrl.value.startsWith(href);
}

function testId(label) {
    return `nav-${label.toLowerCase().replace(/\s+/g, '-')}`;
}

function itemClass(href) {
    return isActive(href)
        ? 'bg-[var(--color-accent-dim)] text-[var(--color-primary)] font-semibold [&_svg]:opacity-100'
        : 'text-[var(--color-text-secondary)] hover:text-[var(--color-text)] hover:bg-[var(--color-surface-hover)] [&_svg]:opacity-60';
}

const mql = window.matchMedia('(min-width: 1025px)');

function onBreakpointChange(e) {
    isDesktop.value = e.matches;
    if (e.matches) drawerOpen.value = false;
}

function onKeydown(e) {
    if (e.key === 'Escape' && drawerOpen.value) {
        drawerOpen.value = false;
    }
}

onMounted(() => {
    isDesktop.value = mql.matches;
    mql.addEventListener('change', onBreakpointChange);
    document.addEventListener('keydown', onKeydown);
    router.on('navigate', () => {
        drawerOpen.value = false;
    });
});

onUnmounted(() => {
    mql.removeEventListener('change', onBreakpointChange);
    document.removeEventListener('keydown', onKeydown);
});

defineExpose({ drawerOpen });
</script>

<template>
    <!-- Desktop: 220px vertical sidebar -->
    <aside
        v-if="isDesktop"
        data-testid="admin-sidebar"
        class="flex w-[220px] shrink-0 flex-col border-r border-[var(--color-border)] bg-[var(--color-surface)]"
    >
        <div class="mb-2 px-5 pt-5 pb-4">
            <div class="flex items-center gap-3">
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-9 w-9 shrink-0 text-[var(--color-primary)]"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="12" r="10" />
                    <line x1="14.31" y1="8" x2="20.05" y2="17.94" />
                    <line x1="9.69" y1="8" x2="21.17" y2="8" />
                    <line x1="7.38" y1="12" x2="13.12" y2="2.06" />
                    <line x1="9.69" y1="16" x2="3.95" y2="6.06" />
                    <line x1="14.31" y1="16" x2="2.83" y2="16" />
                    <line x1="16.62" y1="12" x2="10.88" y2="21.94" />
                </svg>
                <span class="font-heading text-[22px] font-bold tracking-tight text-[var(--color-text)]">Aperture</span>
            </div>
        </div>
        <nav class="flex flex-1 flex-col gap-6 overflow-y-auto">
            <section v-for="group in navGroups" :key="group.label" class="flex flex-col gap-px">
                <p
                    class="mb-1 px-5 text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                >
                    {{ group.label }}
                </p>
                <Link
                    v-for="item in group.items"
                    :key="item.href"
                    :href="item.href"
                    :data-testid="testId(item.label)"
                    :class="itemClass(item.href)"
                    class="flex items-center gap-2.5 px-5 py-2 text-sm transition-colors"
                >
                    <component :is="item.icon" />
                    <span>{{ item.label }}</span>
                </Link>
            </section>
        </nav>
    </aside>

    <!-- Mobile/tablet: slide-out drawer -->
    <template v-else>
        <Teleport to="body">
            <Transition name="drawer">
                <div
                    v-if="drawerOpen"
                    data-testid="admin-drawer-overlay"
                    class="fixed inset-0 z-[60] bg-black/50"
                    @click="drawerOpen = false"
                />
            </Transition>

            <Transition name="drawer-panel">
                <aside
                    v-if="drawerOpen"
                    data-testid="admin-drawer"
                    class="fixed top-0 left-0 z-[70] flex h-full w-[260px] flex-col bg-[var(--color-surface)] shadow-xl"
                >
                    <div class="flex items-center justify-between px-5 pt-5 pb-4">
                        <div class="flex items-center gap-3">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-8 w-8 shrink-0 text-[var(--color-primary)]"
                                aria-hidden="true"
                            >
                                <circle cx="12" cy="12" r="10" />
                                <line x1="14.31" y1="8" x2="20.05" y2="17.94" />
                                <line x1="9.69" y1="8" x2="21.17" y2="8" />
                                <line x1="7.38" y1="12" x2="13.12" y2="2.06" />
                                <line x1="9.69" y1="16" x2="3.95" y2="6.06" />
                                <line x1="14.31" y1="16" x2="2.83" y2="16" />
                                <line x1="16.62" y1="12" x2="10.88" y2="21.94" />
                            </svg>
                            <span class="font-heading text-lg font-bold tracking-tight text-[var(--color-text)]"
                                >Aperture</span
                            >
                        </div>
                        <button
                            data-testid="admin-drawer-close"
                            class="rounded p-1 text-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
                            @click="drawerOpen = false"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                                class="h-5 w-5"
                            >
                                <path
                                    d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
                                />
                            </svg>
                        </button>
                    </div>
                    <nav class="flex flex-1 flex-col gap-6 overflow-y-auto pb-6">
                        <section v-for="group in navGroups" :key="group.label" class="flex flex-col gap-px">
                            <p
                                class="mb-1 px-5 text-[11px] font-semibold tracking-[0.08em] text-[var(--color-text-muted)] uppercase"
                            >
                                {{ group.label }}
                            </p>
                            <Link
                                v-for="item in group.items"
                                :key="item.href"
                                :href="item.href"
                                :data-testid="testId(item.label)"
                                :class="itemClass(item.href)"
                                class="flex items-center gap-2.5 px-5 py-2 text-sm transition-colors"
                            >
                                <component :is="item.icon" />
                                <span>{{ item.label }}</span>
                            </Link>
                        </section>
                    </nav>
                </aside>
            </Transition>
        </Teleport>
    </template>
</template>

<style scoped>
.drawer-enter-active,
.drawer-leave-active {
    transition: opacity 200ms ease;
}
.drawer-enter-from,
.drawer-leave-to {
    opacity: 0;
}

.drawer-panel-enter-active,
.drawer-panel-leave-active {
    transition: transform 200ms ease;
}
.drawer-panel-enter-from,
.drawer-panel-leave-to {
    transform: translateX(-100%);
}
</style>
