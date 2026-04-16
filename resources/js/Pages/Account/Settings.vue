<script setup>
import { useForm, router } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    user: { type: Object, required: true },
    verified: { type: Boolean, default: false },
});

const needsVerification = (props.user.has_password || props.user.passkeys.length > 0) && !props.verified;

const verifyForm = useForm({ password: '' });
const passwordForm = useForm({ password: '', password_confirmation: '' });

function verify() {
    verifyForm.post(route('account.verify'), { preserveScroll: true });
}

function updatePassword() {
    passwordForm.put(route('account.password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function clearPassword() {
    if (confirm('Remove your password? You will need passkeys or Borealis to sign in.')) {
        router.delete(route('account.password.clear'));
    }
}

function deletePasskey(id) {
    if (confirm('Remove this passkey?')) {
        router.delete(route('passkeys.destroy', id));
    }
}
</script>

<template>
    <div data-testid="settings-page" class="mx-auto max-w-2xl space-y-8">
        <div>
            <h1 class="font-heading text-2xl font-bold text-[var(--color-text)]">Account Settings</h1>
            <p class="mt-1 text-sm text-[var(--color-text-secondary)]">Manage your password and passkeys.</p>
        </div>

        <!-- Re-verification gate -->
        <div
            v-if="needsVerification"
            data-testid="verify-form"
            class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
        >
            <h2 class="mb-1 font-heading text-lg font-semibold text-[var(--color-text)]">Verify your identity</h2>
            <p class="mb-4 text-sm text-[var(--color-text-secondary)]">
                Please confirm your password before making changes to your account security settings.
            </p>

            <form class="space-y-4" @submit.prevent="verify">
                <FormField
                    v-if="user.has_password"
                    label="Current Password"
                    name="verify-password"
                    :required="true"
                    :error="verifyForm.errors.password"
                >
                    <input
                        id="verify-password"
                        v-model="verifyForm.password"
                        type="password"
                        data-testid="verify-password"
                        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] outline-none transition focus:border-[var(--color-primary)]"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                    />
                </FormField>

                <button
                    type="submit"
                    data-testid="verify-submit"
                    class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                    :disabled="verifyForm.processing"
                >
                    {{ verifyForm.processing ? 'Verifying…' : 'Verify Identity' }}
                </button>
            </form>
        </div>

        <!-- Settings (shown when verified or no verification needed) -->
        <template v-else>
            <!-- Password Section -->
            <section
                data-testid="password-section"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <h2 class="mb-1 font-heading text-lg font-semibold text-[var(--color-text)]">Password</h2>
                <p class="mb-4 text-sm text-[var(--color-text-secondary)]">
                    {{ user.has_password ? 'Update or remove your password.' : 'Set a password to enable password-based login.' }}
                </p>

                <form class="space-y-4" @submit.prevent="updatePassword">
                    <FormField
                        label="New Password"
                        name="password-new"
                        :required="true"
                        :error="passwordForm.errors.password"
                    >
                        <input
                            id="password-new"
                            v-model="passwordForm.password"
                            type="password"
                            data-testid="password-new"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] outline-none transition focus:border-[var(--color-primary)]"
                            placeholder="Minimum 8 characters"
                            autocomplete="new-password"
                        />
                    </FormField>

                    <FormField
                        label="Confirm Password"
                        name="password-confirm"
                        :required="true"
                        :error="passwordForm.errors.password_confirmation"
                    >
                        <input
                            id="password-confirm"
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            data-testid="password-confirm"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] outline-none transition focus:border-[var(--color-primary)]"
                            placeholder="Repeat your password"
                            autocomplete="new-password"
                        />
                    </FormField>

                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            data-testid="password-save"
                            class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50"
                            :disabled="passwordForm.processing"
                        >
                            {{ passwordForm.processing ? 'Saving…' : user.has_password ? 'Update Password' : 'Set Password' }}
                        </button>

                        <button
                            v-if="user.has_password"
                            type="button"
                            data-testid="password-clear"
                            class="rounded-lg border border-[var(--color-danger)] px-4 py-2 text-sm font-semibold text-[var(--color-danger)] transition hover:bg-[var(--color-danger)]/10"
                            @click="clearPassword"
                        >
                            Remove Password
                        </button>
                    </div>
                </form>
            </section>

            <!-- Passkey Section -->
            <section
                data-testid="passkey-section"
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <h2 class="mb-1 font-heading text-lg font-semibold text-[var(--color-text)]">Passkeys</h2>
                <p class="mb-4 text-sm text-[var(--color-text-secondary)]">
                    Passkeys let you sign in securely without a password.
                </p>

                <div v-if="user.passkeys.length > 0" class="mb-4 space-y-2">
                    <div
                        v-for="passkey in user.passkeys"
                        :key="passkey.id"
                        class="flex items-center justify-between rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-4 py-3"
                    >
                        <div>
                            <p class="text-sm font-medium text-[var(--color-text)]">{{ passkey.name }}</p>
                            <p class="text-xs text-[var(--color-text-muted)]">Added {{ passkey.created_at }}</p>
                        </div>
                    </div>
                </div>

                <p v-else class="mb-4 text-sm text-[var(--color-text-muted)]">
                    No passkeys registered. You can register one from the login page.
                </p>
            </section>
        </template>
    </div>
</template>
