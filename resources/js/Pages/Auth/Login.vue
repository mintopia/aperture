<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';

const form = useForm({
    email: '',
    password: '',
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Login" />
    <div class="flex min-h-screen items-center justify-center bg-[var(--color-bg)] px-4">
        <div class="w-full max-w-md space-y-6">
            <div class="text-center">
                <h1 data-testid="login-title" class="font-heading text-2xl font-bold text-[var(--color-text)]">
                    Sign In
                </h1>
                <p class="mt-2 text-sm text-[var(--color-text-secondary)]">Administrator login</p>
            </div>

            <div class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                <form data-testid="login-form" class="space-y-4" @submit.prevent="submit">
                    <FormField label="Email" name="email" :required="true" :error="form.errors.email">
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            data-testid="login-email"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                            placeholder="you@example.com"
                        />
                    </FormField>

                    <FormField label="Password" name="password" :required="true" :error="form.errors.password">
                        <input
                            id="password"
                            v-model="form.password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            data-testid="login-password"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                        />
                    </FormField>

                    <button
                        type="submit"
                        data-testid="login-submit"
                        class="w-full rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Signing in…' : 'Sign In' }}
                    </button>
                </form>

                <div data-testid="passkey-section" class="mt-4 border-t border-[var(--color-border)] pt-4">
                    <button
                        type="button"
                        data-testid="login-passkey"
                        class="w-full rounded-lg border border-[var(--color-border)] px-4 py-2.5 text-sm font-semibold text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)]"
                        disabled
                    >
                        🔑 Sign in with Passkey
                    </button>
                    <p class="mt-1 text-center text-xs text-[var(--color-text-muted)]">Passkey support coming soon</p>
                </div>
            </div>

            <p class="text-center text-xs text-[var(--color-text-muted)]">
                Regular users: connect via the
                <a href="/captive" data-testid="login-captive-link" class="text-[var(--color-primary)] hover:underline"
                    >captive portal</a
                >.
            </p>
        </div>
    </div>
</template>
