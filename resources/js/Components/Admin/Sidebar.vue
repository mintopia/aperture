<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';

const page = usePage();
const currentUrl = computed(() => page.url);
const isDesktop = ref(true);
const drawerOpen = ref(false);

const icons = {
    dashboard: [
        'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
    ],
    users: [
        'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
    ],
    ips: [
        'M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418',
    ],
    switches: [
        'M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 0 0 2.25-2.25V6.75a2.25 2.25 0 0 0-2.25-2.25H6.75A2.25 2.25 0 0 0 4.5 6.75v10.5a2.25 2.25 0 0 0 2.25 2.25Zm.75-12h9v9h-9v-9Z',
    ],
    dhcp: [
        'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125m16.5 5.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125',
    ],
    content: [
        'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
    ],
    settings: [
        'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z',
        'M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    ],
    auditLog: [
        'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z',
        'M14 2v6h6',
        'M16 13H8',
        'M16 17H8',
        'M10 9H8',
    ],
    macs: [
        'M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z',
        'M6 10v4',
        'M10 10v4',
        'M14 10v4',
        'M18 10v4',
    ],
};

const navGroups = [
    {
        label: 'MANAGEMENT',
        items: [
            { label: 'Dashboard', href: route('admin.home'), icon: icons.dashboard },
            { label: 'Users', href: route('admin.users.index'), icon: icons.users },
            { label: 'IP Addresses', href: route('admin.ips.index'), icon: icons.ips },
            { label: 'Switches', href: route('admin.switches.index'), icon: icons.switches },
            { label: 'DHCP', href: route('admin.dhcp.index'), icon: icons.dhcp },
            { label: 'MAC Addresses', href: route('admin.macs.index'), icon: icons.macs },
        ],
    },
    {
        label: 'SERVICES',
        items: [
            { label: 'Integrations', href: route('admin.settings.integrations'), icon: icons.settings },
            { label: 'IPv6 Detection', href: route('admin.settings.ipv6-detection'), icon: icons.settings },
            { label: 'DNS Detection', href: route('admin.settings.dns-detection'), icon: icons.settings },
            { label: 'Network', href: route('admin.settings.network'), icon: icons.settings },
            { label: 'Captive Portal API', href: route('admin.settings.captive-portal-api'), icon: icons.settings },
        ],
    },
    {
        label: 'CONTENT',
        items: [
            { label: 'Dashboard', href: route('admin.content.index'), icon: icons.content },
            { label: 'Pages', href: route('admin.content.pages.index'), icon: icons.content },
            { label: 'Settings', href: route('admin.content.settings'), icon: icons.settings },
        ],
    },
    {
        label: 'SYSTEM',
        items: [{ label: 'Audit Log', href: route('admin.audit-log.index'), icon: icons.auditLog }],
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
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke-width="1.5"
                        stroke="currentColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-4 w-4 shrink-0"
                        aria-hidden="true"
                    >
                        <path v-for="d in item.icon" :key="d" :d="d" />
                    </svg>
                    <span>{{ item.label }}</span>
                </Link>
            </section>
        </nav>
    </aside>

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
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="h-4 w-4 shrink-0"
                                    aria-hidden="true"
                                >
                                    <path v-for="d in item.icon" :key="d" :d="d" />
                                </svg>
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
