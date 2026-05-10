<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import { useTheme } from '@/composables/useTheme.js';

useTheme();

const form = useForm({
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/setup', {
        onFinish: () => {
            form.reset('password');
            form.reset('password_confirmation');
        },
    });
}
</script>

<template>
    <Head title="Setup" />
    <div class="flex min-h-screen items-center justify-center bg-[var(--color-bg)] px-4">
        <div class="w-full max-w-md space-y-6">
            <div class="text-center">
                <h1 data-testid="setup-title" class="font-heading text-2xl font-bold text-[var(--color-text)]">
                    First-Time Setup
                </h1>
                <p class="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Create the administrator account to get started.
                </p>
            </div>

            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                <form data-testid="setup-form" class="space-y-4" @submit.prevent="submit">
                    <FormField label="Email" name="email" :required="true" :error="form.errors.email">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            data-testid="setup-email"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                            placeholder="admin@example.com"
                        />
                    </FormField>

                    <FormField label="Password" name="password" :required="true" :error="form.errors.password">
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            data-testid="setup-password"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <FormField
                        label="Confirm Password"
                        name="password_confirmation"
                        :required="true"
                        :error="form.errors.password_confirmation"
                    >
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            data-testid="setup-password-confirmation"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <button
                        type="submit"
                        data-testid="setup-submit"
                        class="w-full rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-[var(--color-accent-text)] transition hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Creating account…' : 'Create Admin Account' }}
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-[var(--color-text-muted)]">Minimum password length: 12 characters.</p>
        </div>
    </div>
</template>
