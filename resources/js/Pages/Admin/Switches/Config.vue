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
        <div class="mb-4 flex items-center justify-between">
            <h1 data-testid="page-title" class="font-heading text-xl font-bold text-[var(--color-text)] sm:text-2xl">
                Running Config: {{ switchConfig.name }}
            </h1>
            <Link
                :href="route('admin.switches.show', switchConfig.id)"
                data-testid="action-back"
                class="rounded-lg border border-[var(--color-border)] px-3.5 py-1.5 text-sm font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
            >
                Back to Switch
            </Link>
        </div>

        <SectionHeader title="Running Config" accent-line>
            <template v-if="config" #actions>
                <button
                    data-testid="action-download-config"
                    class="rounded-lg border border-[var(--color-border)] px-3 py-1.5 text-xs font-semibold text-[var(--color-text)] transition-colors hover:bg-[var(--color-surface-hover)]"
                    @click="downloadConfig"
                >
                    Download
                </button>
            </template>
        </SectionHeader>

        <ConfigBlock v-if="config" data-testid="config-content" :code="config" />
        <p
            v-else
            data-testid="config-empty"
            class="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] px-4 py-8 text-center text-sm text-[var(--color-text-muted)]"
        >
            No running config available. The switch may not be reachable.
        </p>
    </div>
</template>
