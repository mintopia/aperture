<script setup>
import { computed, ref } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import ConfirmModal from '@/Components/UI/ConfirmModal.vue';
import FormField from '@/Components/UI/FormField.vue';
import { formatDate } from '@/utils/dates';
import { base64UrlToBuffer, bufferToBase64, getCsrfToken } from '@/utils/webauthn';

const props = defineProps({
    user: { type: Object, required: true },
    verified: { type: Boolean, default: false },
});

const page = usePage();
const layoutComponent = computed(() => (page.props.auth?.user?.is_admin ? AdminLayout : PortalLayout));

const needsVerification = (props.user.has_password || props.user.passkeys.length > 0) && !props.verified;

const verifyForm = useForm({ password: '' });
const passwordForm = useForm({ password: '', password_confirmation: '' });

const passkeyLoading = ref(false);
const passkeyError = ref('');

const showClearPasswordModal = ref(false);
const showDeletePasskeyModal = ref(false);
const pendingPasskeyId = ref(null);

async function parseJsonResponse(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

// ─── Password management ─────────────────────────────────────────────────────

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
    showClearPasswordModal.value = true;
}

function doClearPassword() {
    showClearPasswordModal.value = false;
    router.delete(route('account.password.clear'));
}

// ─── Passkey management ──────────────────────────────────────────────────────

async function registerPasskey() {
    passkeyError.value = '';
    passkeyLoading.value = true;

    try {
        // Step 1: Get creation options from server
        const optionsResponse = await fetch('/passkeys/register/options', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
        });

        const options = await parseJsonResponse(optionsResponse);

        if (!optionsResponse.ok) {
            throw new Error(options?.message || 'Failed to get passkey options.');
        }

        if (!options?.challenge || !options?.user?.id) {
            throw new Error('Unexpected response from server. Please try again.');
        }

        // Step 2: Convert base64 fields to ArrayBuffers
        options.challenge = base64UrlToBuffer(options.challenge);
        options.user.id = base64UrlToBuffer(options.user.id);
        if (options.excludeCredentials) {
            options.excludeCredentials = options.excludeCredentials.map((cred) => ({
                ...cred,
                id: base64UrlToBuffer(cred.id),
            }));
        }

        // Step 3: Call WebAuthn browser API
        const credential = await navigator.credentials.create({ publicKey: options });

        // Step 4: Send credential to server
        const registerResponse = await fetch('/passkeys/register', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                id: credential.id,
                rawId: bufferToBase64(credential.rawId),
                type: credential.type,
                response: {
                    attestationObject: bufferToBase64(credential.response.attestationObject),
                    clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                },
            }),
        });

        const result = await parseJsonResponse(registerResponse);

        if (!registerResponse.ok) {
            throw new Error(result?.message || 'Registration failed.');
        }

        if (result?.success) {
            router.reload();
        } else {
            throw new Error(result?.message || 'Unexpected response from server. Please try again.');
        }
    } catch (error) {
        if (error.name === 'NotAllowedError') {
            passkeyError.value = 'Passkey registration was cancelled or not allowed.';
        } else if (error.name === 'AbortError') {
            passkeyError.value = 'Passkey registration was cancelled.';
        } else {
            passkeyError.value = error.message || 'An unexpected error occurred.';
        }
    } finally {
        passkeyLoading.value = false;
    }
}

function deletePasskey(id) {
    pendingPasskeyId.value = id;
    showDeletePasskeyModal.value = true;
}

async function doDeletePasskey() {
    showDeletePasskeyModal.value = false;
    const id = pendingPasskeyId.value;
    pendingPasskeyId.value = null;

    passkeyError.value = '';

    try {
        const response = await fetch(`/passkeys/${encodeURIComponent(id)}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
            },
        });

        const result = await parseJsonResponse(response);

        if (response.ok && result?.success) {
            router.reload();
        } else {
            passkeyError.value = result?.message || 'Failed to remove passkey. Please try again.';
        }
    } catch {
        passkeyError.value = 'Failed to remove passkey. Please try again.';
    }
}
</script>

<template>
    <component :is="layoutComponent">
        <div data-testid="settings-page" class="mx-auto max-w-2xl space-y-8">
            <div>
                <h1
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                >
                    Account Settings
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">Manage your password and passkeys.</p>
            </div>

            <!-- ── Re-verification gate (password section only) ─────────────────── -->
            <div
                v-if="needsVerification"
                data-testid="verify-form"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <h2
                    class="font-heading mb-1 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                >
                    Verify your identity
                </h2>
                <p class="mb-4 text-[13px] text-[var(--color-text-secondary)]">
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
                            class="w-full rounded-md border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                        />
                    </FormField>

                    <button
                        type="submit"
                        data-testid="verify-submit"
                        class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition hover:opacity-90 disabled:opacity-50"
                        :disabled="verifyForm.processing"
                    >
                        {{ verifyForm.processing ? 'Verifying…' : 'Verify Identity' }}
                    </button>
                </form>
            </div>

            <!-- ── Password section (shown once verified) ──────────────────────── -->
            <section
                v-else
                data-testid="password-section"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <h2
                    class="font-heading mb-1 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                >
                    Password
                </h2>
                <p class="mb-4 text-[13px] text-[var(--color-text-secondary)]">
                    {{
                        user.has_password
                            ? 'Update or remove your password.'
                            : 'Set a password to enable password-based login.'
                    }}
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
                            class="w-full rounded-md border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
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
                            class="w-full rounded-md border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                            placeholder="Repeat your password"
                            autocomplete="new-password"
                        />
                    </FormField>

                    <div class="flex items-center gap-3">
                        <button
                            type="submit"
                            data-testid="password-save"
                            class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition hover:opacity-90 disabled:opacity-50"
                            :disabled="passwordForm.processing"
                        >
                            {{
                                passwordForm.processing
                                    ? 'Saving…'
                                    : user.has_password
                                      ? 'Update Password'
                                      : 'Set Password'
                            }}
                        </button>

                        <button
                            v-if="user.has_password"
                            type="button"
                            data-testid="password-clear"
                            class="rounded-md border border-[var(--color-danger)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-danger)] transition hover:bg-[var(--color-danger)]/10"
                            @click="clearPassword"
                        >
                            Remove Password
                        </button>
                    </div>
                </form>
            </section>

            <!-- ── Passkey section ──────────────────────────────────────────────── -->
            <section
                v-if="!needsVerification"
                data-testid="passkey-section"
                class="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
            >
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h2
                            class="font-heading text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
                        >
                            Passkeys
                        </h2>
                        <p class="mt-0.5 text-[13px] text-[var(--color-text-secondary)]">
                            Passkeys let you sign in securely without a password.
                        </p>
                    </div>
                    <button
                        type="button"
                        data-testid="passkey-register"
                        class="flex items-center gap-2 rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-bg)] transition hover:opacity-90 disabled:opacity-50"
                        :disabled="passkeyLoading"
                        @click="registerPasskey"
                    >
                        <span>{{ passkeyLoading ? 'Registering…' : '+ Add Passkey' }}</span>
                    </button>
                </div>

                <p
                    v-if="passkeyError"
                    data-testid="passkey-error"
                    class="mb-3 rounded-md border border-[var(--color-danger)]/30 bg-[var(--color-danger)]/10 px-4 py-2 text-[13px] text-[var(--color-danger)]"
                >
                    {{ passkeyError }}
                </p>

                <div v-if="user.passkeys.length > 0" data-testid="passkey-list" class="space-y-2">
                    <div
                        v-for="passkey in user.passkeys"
                        :key="passkey.id"
                        :data-testid="'passkey-item-' + passkey.id"
                        class="flex items-center justify-between rounded-md border border-[var(--color-border)] bg-[var(--color-bg)] px-4 py-3"
                    >
                        <div>
                            <p class="text-[13px] font-medium text-[var(--color-text)]">{{ passkey.name }}</p>
                            <p class="text-[11px] text-[var(--color-text-muted)]">
                                Added {{ formatDate(passkey.created_at) }}
                            </p>
                        </div>
                        <button
                            type="button"
                            :data-testid="'passkey-delete-' + passkey.id"
                            class="ml-4 rounded-md border border-[var(--color-danger)]/40 px-3 py-[5px] text-[11px] font-semibold text-[var(--color-danger)] transition hover:bg-[var(--color-danger)]/10"
                            @click="deletePasskey(passkey.id)"
                        >
                            Remove
                        </button>
                    </div>
                </div>

                <p v-else class="text-[13px] text-[var(--color-text-muted)]">
                    No passkeys registered yet. Click <strong>+ Add Passkey</strong> to register one.
                </p>
            </section>
        </div>

        <ConfirmModal
            :show="showClearPasswordModal"
            title="Remove Password?"
            message="You will need passkeys or Borealis to sign in."
            confirm-label="Remove Password"
            variant="danger"
            @cancel="showClearPasswordModal = false"
            @confirm="doClearPassword"
        />

        <ConfirmModal
            :show="showDeletePasskeyModal"
            title="Remove Passkey?"
            message="Remove this passkey? This cannot be undone."
            confirm-label="Remove Passkey"
            variant="danger"
            @cancel="showDeletePasskeyModal = false"
            @confirm="doDeletePasskey"
        />
    </component>
</template>
