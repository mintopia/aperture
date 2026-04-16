<script setup>
import { computed } from 'vue';

const props = defineProps({
    name: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
        default: false,
    },
});

const formattedName = computed(() =>
    props.name
        .split('-')
        .filter(Boolean)
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' '),
);

const stateClasses = computed(() =>
    props.active
        ? ['bg-[var(--color-primary)]/15', 'text-[var(--color-primary)]']
        : ['bg-[var(--color-text-muted)]/10', 'text-[var(--color-text-muted)]', 'opacity-40'],
);
</script>

<template>
    <span
        :class="stateClasses"
        :data-testid="`capability-tag-${name}`"
        class="rounded-full px-2 py-0.5 text-[10px] font-medium"
    >
        {{ formattedName }}
    </span>
</template>
