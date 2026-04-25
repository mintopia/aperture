<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    user: { type: Object, default: () => null },
    availableRoles: { type: Array, default: () => [] },
});

const form = useForm({
    nickname: props.user.nickname,
    email: props.user.email,
    password: '',
    password_confirmation: '',
    clear_password: false,
    roles: [...(props.user.roles ?? [])],
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
        <!-- Page Header -->
        <header class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="edit-user-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Edit User: {{ user.nickname }}
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                    Update account details and password settings.
                </p>
            </div>
        </header>

        <form data-testid="edit-user-form" class="space-y-6" @submit.prevent="submit">
            <!-- Basic Info -->
            <section>
                <h2
                    class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    style="font-variation-settings: 'opsz' 16"
                >
                    Basic Information
                </h2>
                <div class="grid gap-4 md:grid-cols-2">
                    <FormField label="Nickname" name="nickname" :required="true" :error="form.errors.nickname">
                        <input
                            id="nickname"
                            v-model="form.nickname"
                            type="text"
                            data-testid="edit-user-nickname"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField label="Email" name="email" :required="true" :error="form.errors.email">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            data-testid="edit-user-email"
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>
            </section>

            <!-- Password Section -->
            <section>
                <h2
                    class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    style="font-variation-settings: 'opsz' 16"
                >
                    Password
                </h2>

                <div v-if="user.has_password" class="mb-4">
                    <p data-testid="has-password-indicator" class="text-[13px] text-[var(--color-text-secondary)]">
                        This user has a password set. You can change it or clear it below.
                    </p>
                </div>
                <div v-else class="mb-4">
                    <p data-testid="no-password-indicator" class="text-[13px] text-[var(--color-text-secondary)]">
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
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
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
                            class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>
                </div>

                <div v-if="user.has_password" class="mt-4">
                    <label class="flex items-center gap-2 text-[13px] text-[var(--color-text)]">
                        <input
                            v-model="form.clear_password"
                            type="checkbox"
                            data-testid="edit-user-clear-password"
                            class="rounded border-[var(--color-border-hover)]"
                        />
                        Clear password (disable email/password login for this user)
                    </label>
                </div>
            </section>

            <!-- Roles -->
            <section v-if="availableRoles.length > 0">
                <h2
                    class="font-heading mb-3 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                    style="font-variation-settings: 'opsz' 16"
                >
                    Roles
                </h2>
                <div class="space-y-2">
                    <label
                        v-for="role in availableRoles"
                        :key="role.code"
                        class="flex items-center gap-2 text-[13px] text-[var(--color-text)]"
                    >
                        <input
                            v-model="form.roles"
                            type="checkbox"
                            :value="role.code"
                            :data-testid="`role-${role.code}`"
                            class="rounded border-[var(--color-border-hover)]"
                        />
                        {{ role.name }}
                    </label>
                </div>
                <p v-if="form.errors.roles" class="mt-1 text-[12px] text-[var(--color-danger)]">
                    {{ form.errors.roles }}
                </p>
            </section>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-1">
                <button
                    type="submit"
                    data-testid="edit-user-submit"
                    class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition hover:opacity-90 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save Changes' }}
                </button>
                <Link
                    :href="route('admin.users.show', user.id)"
                    data-testid="edit-user-cancel"
                    class="rounded-md border border-[var(--color-border-hover)] bg-transparent px-4 py-[7px] text-[13px] font-semibold text-[var(--color-text-secondary)] transition hover:bg-[var(--color-surface-hover)]"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
