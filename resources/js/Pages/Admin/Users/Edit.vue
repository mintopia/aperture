<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: Object,
});

const form = useForm({
    nickname: props.user.nickname,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    clear_password: false,
});

function submit() {
    form.put(`/admin/users/${props.user.id}`, {
        onSuccess: () => {
            form.reset('password', 'password_confirmation');
        },
    });
}
</script>

<template>
    <Head :title="`Edit ${user.nickname}`" />

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 data-testid="edit-user-title" class="font-heading text-2xl font-bold text-[var(--color-text)]">
                Edit User: {{ user.nickname }}
            </h1>
        </div>

        <form data-testid="edit-user-form" class="space-y-6" @submit.prevent="submit">
            <!-- Basic Info -->
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">Basic Information</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="Nickname" name="nickname" :required="true" :error="form.errors.nickname">
                        <input
                            id="nickname"
                            v-model="form.nickname"
                            type="text"
                            data-testid="edit-user-nickname"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Email" name="email" :required="true" :error="form.errors.email">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            data-testid="edit-user-email"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </div>

            <!-- Password Section -->
            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                <h2 class="mb-4 text-lg font-semibold text-[var(--color-text)]">Password</h2>

                <div v-if="user.has_password" class="mb-4 rounded-lg bg-[var(--color-bg)] p-3">
                    <p data-testid="has-password-indicator" class="text-sm text-[var(--color-text-secondary)]">
                        This user has a password set. You can change it or clear it below.
                    </p>
                </div>
                <div v-else class="mb-4 rounded-lg bg-[var(--color-bg)] p-3">
                    <p data-testid="no-password-indicator" class="text-sm text-[var(--color-text-secondary)]">
                        This user does not have a password. Set one to allow email/password login.
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="New Password" name="password" :error="form.errors.password">
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            data-testid="edit-user-password"
                            placeholder="Leave blank to keep current"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField
                        label="Confirm Password"
                        name="password_confirmation"
                        :error="form.errors.password_confirmation"
                    >
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            data-testid="edit-user-password-confirm"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>

                <div v-if="user.has_password" class="mt-4">
                    <label class="flex items-center gap-2 text-sm text-[var(--color-text)]">
                        <input
                            v-model="form.clear_password"
                            type="checkbox"
                            data-testid="edit-user-clear-password"
                            class="rounded border-[var(--color-border)]"
                        />
                        Clear password (disable email/password login for this user)
                    </label>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3">
                <button
                    type="submit"
                    data-testid="edit-user-submit"
                    class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save Changes' }}
                </button>
                <a
                    :href="`/admin/users/${user.id}`"
                    data-testid="edit-user-cancel"
                    class="rounded-lg border border-[var(--color-border)] px-4 py-2 text-sm font-semibold text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)]"
                >
                    Cancel
                </a>
            </div>
        </form>
    </div>
</template>
