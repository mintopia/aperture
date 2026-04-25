<script setup>
import { ref } from 'vue';
import { useForm, Head } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import { base64UrlToBuffer, bufferToBase64, getCsrfToken } from '@/utils/webauthn';
import { useTheme } from '@/composables/useTheme.js';

useTheme();

const form = useForm({
    email: '',
    password: '',
});

const passkeyLoading = ref(false);
const passkeyError = ref('');

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

async function loginWithPasskey() {
    passkeyError.value = '';
    passkeyLoading.value = true;

    try {
        // Step 1: Get assertion options from server
        const optionsResponse = await fetch('/passkeys/login/options', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ email: form.email }),
        });

        if (!optionsResponse.ok) {
            const errorData = await optionsResponse.json().catch(() => ({}));
            throw new Error(errorData.message || 'Failed to get passkey options.');
        }

        const options = await optionsResponse.json();

        // Step 2: Transform server options for WebAuthn API
        options.challenge = base64UrlToBuffer(options.challenge);

        if (options.allowCredentials) {
            options.allowCredentials = options.allowCredentials.map((cred) => ({
                ...cred,
                id: base64UrlToBuffer(cred.id),
            }));
        }

        // Step 3: Call WebAuthn browser API
        const credential = await navigator.credentials.get({ publicKey: options });

        // Step 4: Encode credential response for server
        const credentialData = {
            id: credential.id,
            rawId: bufferToBase64(credential.rawId),
            type: credential.type,
            authenticatorAttachment: credential.authenticatorAttachment,
            response: {
                clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                authenticatorData: bufferToBase64(credential.response.authenticatorData),
                signature: bufferToBase64(credential.response.signature),
            },
        };

        if (credential.response.userHandle) {
            credentialData.response.userHandle = bufferToBase64(credential.response.userHandle);
        }

        // Step 5: Send credential to server for verification
        const loginResponse = await fetch('/passkeys/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify(credentialData),
        });

        if (!loginResponse.ok) {
            const errorData = await loginResponse.json().catch(() => ({}));
            throw new Error(errorData.message || 'Passkey authentication failed.');
        }

        const result = await loginResponse.json();

        if (result.success) {
            window.location.href = result.redirect || '/';
        } else {
            throw new Error(result.message || 'Authentication failed.');
        }
    } catch (error) {
        if (error.name === 'NotAllowedError') {
            passkeyError.value = 'Passkey authentication was cancelled or not allowed.';
        } else if (error.name === 'AbortError') {
            passkeyError.value = 'Passkey authentication was cancelled.';
        } else {
            passkeyError.value = error.message || 'An unexpected error occurred.';
        }
    } finally {
        passkeyLoading.value = false;
    }
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
                        class="w-full rounded-lg bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-[var(--color-accent-text)] transition hover:opacity-90 disabled:opacity-50"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Signing in…' : 'Sign In' }}
                    </button>
                </form>

                <div data-testid="passkey-section" class="mt-4 border-t border-[var(--color-border)] pt-4">
                    <button
                        type="button"
                        data-testid="login-passkey"
                        class="flex w-full items-center justify-center gap-2 rounded-lg border border-[var(--color-border)] px-4 py-2.5 text-sm font-semibold text-[var(--color-text)] transition hover:bg-[var(--color-surface-hover)] disabled:opacity-50"
                        :disabled="passkeyLoading"
                        @click="loginWithPasskey"
                    >
                        <svg
                            v-if="!passkeyLoading"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="1.5"
                            stroke="currentColor"
                            class="h-4 w-4 shrink-0"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"
                            />
                        </svg>
                        <span>{{ passkeyLoading ? 'Authenticating…' : 'Sign in with Passkey' }}</span>
                    </button>
                    <p
                        v-if="passkeyError"
                        data-testid="passkey-error"
                        class="mt-1 text-center text-xs text-[var(--color-danger)]"
                    >
                        {{ passkeyError }}
                    </p>
                    <p v-else class="mt-1 text-center text-xs text-[var(--color-text-muted)]">
                        Use a passkey to sign in securely
                    </p>
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
