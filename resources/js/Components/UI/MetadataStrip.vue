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
    <div
        data-testid="metadata-strip"
        class="mt-6 mb-7 flex flex-wrap gap-y-3 border-b border-[var(--color-border)] pb-5"
    >
        <div
            v-for="(item, index) in items"
            :key="item.label"
            data-testid="metadata-item"
            :class="[
                index > 0
                    ? 'ml-6 border-l border-[var(--color-border)] pl-6 max-sm:ml-0 max-sm:border-0 max-sm:pl-0'
                    : '',
            ]"
            class="flex flex-none flex-col"
        >
            <span
                class="mb-[3px] text-[11px] font-semibold tracking-[0.06em] text-[var(--color-text-muted)] uppercase"
                >{{ item.label }}</span
            >
            <span
                :class="[
                    item.mono ? 'font-mono text-[14px]' : '',
                    item.large ? 'text-sm font-semibold' : 'text-[15px]',
                ]"
                class="font-medium text-[var(--color-text)]"
            >
                <slot :name="item.label" :item="item">{{ item.value }}</slot>
            </span>
        </div>
    </div>
</template>
