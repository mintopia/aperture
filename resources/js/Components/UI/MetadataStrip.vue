<script setup>
defineProps({
    items: {
        type: Array,
        required: true,
        /* Array<{ label: string, value: string|number, mono?: boolean, large?: boolean }> */
    },
});
</script>

<template>
    <div data-testid="metadata-strip" class="flex flex-wrap gap-x-7 gap-y-2 border-b-2 border-[var(--color-border)] pb-3.5 mb-3.5">
        <div v-for="item in items" :key="item.label" class="flex flex-col">
            <span class="text-[10px] font-bold uppercase tracking-wider text-[var(--color-text-muted)]">{{ item.label }}</span>
            <span :class="[item.mono ? 'font-mono' : '', item.large ? 'text-sm font-semibold' : 'text-[13px]']" class="text-[var(--color-text)]">
                <slot :name="item.label" :item="item">{{ item.value }}</slot>
            </span>
        </div>
    </div>
</template>
