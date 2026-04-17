<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const breadcrumbs = computed(() => page.props.breadcrumbs || []);
</script>

<template>
    <nav v-if="breadcrumbs.length" aria-label="Breadcrumb" data-testid="breadcrumbs">
        <ol class="flex items-center gap-1 text-sm">
            <li v-for="(crumb, index) in breadcrumbs" :key="index" class="flex items-center gap-1">
                <span v-if="index > 0" class="text-[var(--color-text-muted)]">/</span>
                <Link
                    v-if="crumb.href"
                    :href="crumb.href"
                    data-testid="breadcrumb-link"
                    class="text-[var(--color-text-secondary)] transition-colors hover:text-[var(--color-text)]"
                >
                    {{ crumb.label }}
                </Link>
                <span v-else data-testid="breadcrumb-current" class="font-medium text-[var(--color-text)]">
                    {{ crumb.label }}
                </span>
            </li>
        </ol>
    </nav>
</template>
