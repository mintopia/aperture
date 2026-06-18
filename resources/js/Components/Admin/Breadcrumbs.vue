<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const breadcrumbs = computed(() => page.props.breadcrumbs || []);
</script>

<template>
    <nav v-if="breadcrumbs.length" aria-label="Breadcrumb" data-testid="breadcrumbs">
        <ol class="flex items-center gap-1 font-mono text-[13px] font-normal text-[var(--color-text-muted)]">
            <li v-for="(crumb, index) in breadcrumbs" :key="index" class="flex items-center gap-1">
                <span v-if="index > 0" class="text-[var(--color-text-muted)]">/</span>
                <Link
                    v-if="crumb.href"
                    :href="crumb.href"
                    data-testid="breadcrumb-link"
                    class="text-[var(--color-text-secondary)] no-underline transition-colors hover:text-[var(--color-text)]"
                >
                    {{ crumb.label }}
                </Link>
                <span v-else data-testid="breadcrumb-current" class="text-[var(--color-text-muted)]">
                    {{ crumb.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
