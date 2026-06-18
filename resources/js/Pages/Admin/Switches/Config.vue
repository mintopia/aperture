<script setup>
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import ConfigBlock from '@/Components/UI/ConfigBlock.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    switchConfig: { type: Object, default: () => ({}) },
    config: { type: String, default: '' },
});

function downloadConfig() {
    const blob = new Blob([props.config], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${props.switchConfig.name ?? 'switch'}-running-config.txt`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-start justify-between gap-6">
            <h1
                data-testid="page-title"
                class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                :style="{ fontVariationSettings: '\'opsz\' 48' }"
            >
                Running Config
            </h1>
            <Link
                :href="route('admin.switches.show', switchConfig.id)"
                data-testid="action-back"
                class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
            >
                Back to Switch
            </Link>
        </div>
        <SectionHeader title="Running Config">
            <template v-if="config" #actions>
                <button
                    data-testid="action-download-config"
                    class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:border-[var(--color-text-muted)] hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
                    @click="downloadConfig"
                >
                    Download
                </button>
            </template>
        </SectionHeader>

        <ConfigBlock v-if="config" data-testid="config-content" :code="config" />
        <p v-else data-testid="config-empty" class="px-4 py-8 text-center text-sm text-[var(--color-text-muted)]">
            No running config available. The switch may not be reachable.
        </p>
    </div>
</template>
