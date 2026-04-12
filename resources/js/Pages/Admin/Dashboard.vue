<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import StatCard from '@/Components/UI/StatCard.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    totalUsers: { type: Number, default: 0 },
    onlineUsers: { type: Number, default: 0 },
    totalIps: { type: Number, default: 0 },
    allowedIps: { type: Number, default: 0 },
});

const showResetModal = ref(false);
const resetting = ref(false);

function confirmReset() {
    resetting.value = true;
    router.post(
        route('admin.reset'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resetting.value = false;
                showResetModal.value = false;
            },
        },
    );
}
</script>

<template>
    <div>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Admin Dashboard
        </h1>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Online Users" :value="onlineUsers ?? 0" hero color="success" />
            <StatCard label="Total Users" :value="totalUsers ?? 0" />
            <StatCard label="Total IPs" :value="totalIps ?? 0" />
            <StatCard label="Allowed IPs" :value="allowedIps ?? 0" color="accent" accent-border="accent" />
        </div>

        <div class="mt-8">
            <button
                data-testid="reset-button"
                class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                @click="showResetModal = true"
            >
                Reset Portal
            </button>
        </div>

        <teleport to="body">
            <div
                v-if="showResetModal"
                data-testid="reset-confirm-modal"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            >
                <div class="mx-4 w-full max-w-md rounded-lg bg-[var(--color-surface)] p-6 shadow-xl">
                    <h2 class="text-lg font-bold text-[var(--color-text)]">Reset Portal</h2>
                    <p class="mt-2 text-sm text-[var(--color-text-secondary)]">
                        This will remove all IP sessions and non-admin users. This action cannot be undone.
                    </p>
                    <div class="mt-4 flex justify-end gap-3">
                        <button
                            data-testid="reset-cancel-button"
                            class="rounded-md px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:bg-[var(--color-surface-raised)]"
                            @click="showResetModal = false"
                        >
                            Cancel
                        </button>
                        <button
                            data-testid="reset-confirm-button"
                            class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            :disabled="resetting"
                            @click="confirmReset"
                        >
                            {{ resetting ? 'Resetting...' : 'Confirm Reset' }}
                        </button>
                    </div>
                </div>
            </div>
        </teleport>
    </div>
</template>
