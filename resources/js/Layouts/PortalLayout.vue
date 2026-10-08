<script setup>
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLogo from '@/Components/AppLogo.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import UserMenu from '@/Components/UserMenu.vue';
import { useGlassSheen } from '@/composables/useGlassSheen';

const page = usePage();
const layoutRef = ref(null);

useGlassSheen(layoutRef);

const termsUrl = computed(() => {
    const footer = page.props.footer;
    if (!footer?.terms_value) return null;
    return footer.terms_type === 'page' ? `/content/${footer.terms_value}` : footer.terms_value;
});

const privacyUrl = computed(() => {
    const footer = page.props.footer;
    if (!footer?.privacy_value) return null;
    return footer.privacy_type === 'page' ? `/content/${footer.privacy_value}` : footer.privacy_value;
});
</script>

<template>
    <div ref="layoutRef" data-testid="portal-layout" class="isolate min-h-screen bg-[var(--color-bg)]">
        <div class="ambient-field" data-variant="lens" aria-hidden="true"><span /><span /><span /></div>
        <div
            class="pointer-events-none fixed inset-x-0 top-0 z-40 h-5 bg-gradient-to-b from-[var(--color-bg)] to-transparent"
            aria-hidden="true"
        />

        <svg width="0" height="0" class="absolute" aria-hidden="true">
            <filter id="aperture-lens" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
                <feTurbulence type="fractalNoise" baseFrequency="0.005 0.008" numOctaves="2" seed="7" result="noise" />
                <feGaussianBlur in="noise" stdDeviation="2" result="soft" />
                <feDisplacementMap in="SourceGraphic" in2="soft" scale="22" xChannelSelector="R" yChannelSelector="G" />
            </filter>
        </svg>

        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[100] focus:rounded focus:bg-[var(--color-surface)] focus:px-4 focus:py-2 focus:text-[var(--color-text)] focus:shadow-lg"
            data-testid="skip-nav"
        >
            Skip to content
        </a>

        <header
            data-testid="portal-header"
            class="glass-lens glass-refract sticky top-3 z-50 mx-3 mt-3 flex h-[56px] items-center justify-between rounded-[22px] pr-3 pl-5 sm:mx-6"
        >
            <Link :href="route('home')" class="flex items-center gap-2.5">
                <AppLogo />
            </Link>

            <div class="flex items-center gap-2">
                <ThemeToggle />

                <div v-if="page.props.auth.user" class="flex items-center">
                    <UserMenu :user="page.props.auth.user" />
                </div>
            </div>
        </header>

        <main id="main-content" class="mx-auto max-w-[1100px] px-6 pt-8 pb-16">
            <slot />
        </main>

        <footer data-testid="portal-footer" class="px-6 py-8">
            <div
                class="mx-auto flex max-w-[1100px] flex-col items-center gap-3 text-[12px] text-[var(--color-text-muted)]"
            >
                <div v-if="termsUrl || privacyUrl" class="flex items-center gap-4">
                    <a
                        v-if="termsUrl"
                        :href="termsUrl"
                        data-testid="footer-terms"
                        class="no-underline transition-colors hover:text-[var(--color-text-secondary)]"
                    >
                        Terms &amp; Conditions
                    </a>
                    <a
                        v-if="privacyUrl"
                        :href="privacyUrl"
                        data-testid="footer-privacy"
                        class="no-underline transition-colors hover:text-[var(--color-text-secondary)]"
                    >
                        Privacy Policy
                    </a>
                </div>
                <p class="flex items-center gap-1">
                    Made with
                    <span
                        data-testid="footer-heart"
                        class="inline-block text-[var(--color-primary)] transition-transform duration-200 [&:hover]:scale-125"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="inline h-3.5 w-3.5 transition-all duration-200 [span:hover_&]:fill-current"
                        >
                            <path
                                d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"
                            />
                        </svg>
                    </span>
                    by
                    <a
                        href="https://mintopia.net"
                        target="_blank"
                        rel="noopener"
                        data-testid="footer-mintopia"
                        class="text-[var(--color-text-muted)] no-underline transition-colors hover:text-[var(--color-text-secondary)]"
                    >
                        Mintopia
                    </a>
                </p>
            </div>
        </footer>
    </div>
</template>
