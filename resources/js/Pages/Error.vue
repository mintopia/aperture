<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: Number,
        required: true,
    },
});

const errors = {
    403: {
        title: 'Forbidden',
        description:
            "You don't have permission to access this resource. If you believe this is a mistake, contact your network administrator.",
    },
    404: {
        title: 'Page Not Found',
        description:
            "The page you're looking for doesn't exist or has been moved. Check the URL or head back to the dashboard.",
    },
    419: {
        title: 'Session Expired',
        description: 'Your session has timed out for security. Please refresh the page and try again.',
    },
    429: {
        title: 'Too Many Requests',
        description: "You've sent too many requests in a short period. Wait a moment and try again.",
    },
    500: {
        title: 'Server Error',
        description: 'An internal error occurred. The issue has been logged and we are looking into it.',
    },
    503: {
        title: 'Service Unavailable',
        description: 'The system is temporarily offline for maintenance. Please check back shortly.',
    },
};

const fallback = {
    title: 'Something Went Wrong',
    description:
        'An unexpected error occurred. Please try again or contact your network administrator if the problem persists.',
};

const error = computed(() => errors[props.status] || fallback);
</script>

<template>
    <Head :title="`${status} — ${error.title}`" />
    <div
        data-testid="error-page"
        class="flex min-h-screen flex-col items-center justify-center bg-[var(--color-bg)] px-6"
    >
        <div class="w-full max-w-md text-center">
            <p
                data-testid="error-status"
                class="font-mono text-[clamp(48px,12vw,80px)] leading-none font-bold text-[var(--color-primary)]"
            >
                {{ status }}
            </p>

            <h1
                data-testid="error-title"
                class="font-heading mt-4 text-2xl font-bold tracking-tight text-[var(--color-text)]"
            >
                {{ error.title }}
            </h1>

            <p data-testid="error-description" class="mt-3 text-sm leading-relaxed text-[var(--color-text-secondary)]">
                {{ error.description }}
            </p>

            <a
                href="/"
                data-testid="error-home-link"
                class="mt-8 inline-flex items-center gap-2 rounded-md bg-[var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-[var(--color-accent-text)] transition-colors hover:bg-[var(--color-primary-hover)] focus-visible:ring-2 focus-visible:ring-[var(--color-primary)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--color-bg)] focus-visible:outline-none"
            >
                Go Home
            </a>
        </div>
    </div>
</template>
