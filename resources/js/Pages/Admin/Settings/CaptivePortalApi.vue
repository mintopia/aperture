<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    apiUrl: { type: String, default: '' },
});

const form = useForm({
    user_portal_url: props.settings?.user_portal_url ?? '',
    venue_info_url: props.settings?.venue_info_url ?? '',
    can_extend_session: props.settings?.can_extend_session ?? false,
});

const copied = ref(null);

function copyToClipboard(text, label) {
    navigator.clipboard.writeText(text);
    copied.value = label;
    setTimeout(() => {
        copied.value = null;
    }, 2000);
}

function submit() {
    form.put(route('admin.settings.captive-portal-api.update'));
}
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            RFC 8908 Captive Portal API
        </h1>

        <p class="mb-8 text-[13px] leading-relaxed text-[var(--color-text-secondary)]">
            RFC 8908 defines a standard API that allows devices to programmatically determine their captive portal
            status. Clients discover the API endpoint via DHCP options or Router Advertisements and poll it to check
            whether they have internet access.
        </p>

        <!-- API Endpoint -->
        <h2
            data-testid="section-heading-endpoint"
            class="font-heading mt-8 mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
        >
            API Endpoint
        </h2>

        <div
            class="flex items-center gap-3 rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
            data-testid="api-url-display"
        >
            <code class="flex-1 font-mono text-[13px] text-[var(--color-text)]">{{ apiUrl }}</code>
            <button
                type="button"
                data-testid="copy-api-url"
                class="shrink-0 rounded border border-[var(--color-border-hover)] px-3 py-1.5 text-[12px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)] hover:text-[var(--color-text)]"
                @click="copyToClipboard(apiUrl, 'api-url')"
            >
                {{ copied === 'api-url' ? 'Copied' : 'Copy' }}
            </button>
        </div>

        <p class="mt-2 text-[12px] text-[var(--color-text-muted)]">
            This endpoint returns <code class="text-[var(--color-text-secondary)]">application/captive+json</code>
            responses. No authentication is required; the client is identified by source IP.
        </p>

        <!-- DHCP Configuration -->
        <h2
            data-testid="section-heading-dhcp"
            class="font-heading mt-10 mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
        >
            DHCP &amp; Router Advertisement Configuration
        </h2>

        <p class="mb-4 text-[13px] leading-relaxed text-[var(--color-text-secondary)]">
            To advertise the captive portal API to clients, configure the following options on your DHCP server or
            router. These options are defined in
            <span class="text-[var(--color-text)]">RFC 8910</span>.
        </p>

        <div class="space-y-3" data-testid="dhcp-options">
            <!-- DHCPv4 -->
            <div class="rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded bg-[var(--color-info)]/10 px-2 py-0.5 text-[11px] font-semibold text-[var(--color-info)]"
                                >DHCPv4</span
                            >
                            <span class="text-[13px] font-semibold text-[var(--color-text)]">Option 114</span>
                        </div>
                        <p class="mt-1.5 text-[12px] text-[var(--color-text-secondary)]">
                            Captive-Portal option. Set the value to the API URL as a UTF-8 string (not null-terminated).
                        </p>
                    </div>
                    <button
                        type="button"
                        data-testid="copy-dhcpv4"
                        class="shrink-0 rounded border border-[var(--color-border-hover)] px-2.5 py-1 text-[11px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                        @click="copyToClipboard(apiUrl, 'dhcpv4')"
                    >
                        {{ copied === 'dhcpv4' ? 'Copied' : 'Copy URL' }}
                    </button>
                </div>
                <div class="mt-3 rounded bg-[var(--color-bg)] px-3 py-2">
                    <code class="text-[12px] text-[var(--color-text-muted)]"
                        >option captive-portal code 114 = text;<br />option captive-portal "{{ apiUrl }}";</code
                    >
                </div>
            </div>

            <!-- DHCPv6 -->
            <div class="rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded bg-[var(--color-info)]/10 px-2 py-0.5 text-[11px] font-semibold text-[var(--color-info)]"
                                >DHCPv6</span
                            >
                            <span class="text-[13px] font-semibold text-[var(--color-text)]">Option 103</span>
                        </div>
                        <p class="mt-1.5 text-[12px] text-[var(--color-text-secondary)]">
                            DHCP Captive-Portal option for IPv6. Set the value to the API URL as a UTF-8 string (not
                            null-terminated).
                        </p>
                    </div>
                    <button
                        type="button"
                        data-testid="copy-dhcpv6"
                        class="shrink-0 rounded border border-[var(--color-border-hover)] px-2.5 py-1 text-[11px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                        @click="copyToClipboard(apiUrl, 'dhcpv6')"
                    >
                        {{ copied === 'dhcpv6' ? 'Copied' : 'Copy URL' }}
                    </button>
                </div>
                <div class="mt-3 rounded bg-[var(--color-bg)] px-3 py-2">
                    <code class="text-[12px] text-[var(--color-text-muted)]"
                        >option dhcp6.capport code 103 = text;<br />option dhcp6.capport "{{ apiUrl }}";</code
                    >
                </div>
            </div>

            <!-- IPv6 RA -->
            <div class="rounded border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded bg-[var(--color-info)]/10 px-2 py-0.5 text-[11px] font-semibold text-[var(--color-info)]"
                                >IPv6 RA</span
                            >
                            <span class="text-[13px] font-semibold text-[var(--color-text)]">Type 37</span>
                        </div>
                        <p class="mt-1.5 text-[12px] text-[var(--color-text-secondary)]">
                            Router Advertisement Captive-Portal option. The URL is padded with NUL bytes to make the
                            total option length a multiple of 8 bytes.
                        </p>
                    </div>
                    <button
                        type="button"
                        data-testid="copy-ra"
                        class="shrink-0 rounded border border-[var(--color-border-hover)] px-2.5 py-1 text-[11px] font-semibold text-[var(--color-text-secondary)] transition-colors hover:bg-[var(--color-surface-hover)]"
                        @click="copyToClipboard(apiUrl, 'ra')"
                    >
                        {{ copied === 'ra' ? 'Copied' : 'Copy URL' }}
                    </button>
                </div>
                <div class="mt-3 rounded bg-[var(--color-bg)] px-3 py-2">
                    <code class="text-[12px] text-[var(--color-text-muted)]">AdvCaptivePortalAPI "{{ apiUrl }}";</code>
                </div>
            </div>
        </div>

        <!-- Response Settings -->
        <h2
            data-testid="section-heading-response"
            class="font-heading mt-10 mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
        >
            Response Settings
        </h2>

        <p class="mb-4 text-[13px] leading-relaxed text-[var(--color-text-secondary)]">
            Configure optional fields included in the API response. The
            <code class="text-[var(--color-text)]">captive</code> field is always included and determined automatically
            based on the client's IP address and internet access status.
        </p>

        <form class="space-y-4" data-testid="captive-portal-api-form" @submit.prevent="submit">
            <FormField label="User Portal URL" name="user_portal_url" :error="form.errors.user_portal_url">
                <input
                    id="user_portal_url"
                    v-model="form.user_portal_url"
                    type="url"
                    data-testid="input-user-portal-url"
                    placeholder="https://portal.example.com/captive"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
                <p class="mt-1 text-[12px] text-[var(--color-text-muted)]">
                    URL for users to interact with to gain internet access. Typically your captive portal login page.
                </p>
            </FormField>

            <FormField label="Venue Info URL" name="venue_info_url" :error="form.errors.venue_info_url">
                <input
                    id="venue_info_url"
                    v-model="form.venue_info_url"
                    type="url"
                    data-testid="input-venue-info-url"
                    placeholder="https://example.com/about"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
                <p class="mt-1 text-[12px] text-[var(--color-text-muted)]">
                    URL with information about the venue or network operator.
                </p>
            </FormField>

            <FormField label="Can Extend Session" name="can_extend_session">
                <label class="flex items-center gap-2 pt-1 text-sm text-[var(--color-text)]">
                    <input
                        v-model="form.can_extend_session"
                        type="checkbox"
                        data-testid="toggle-can-extend-session"
                        class="peer sr-only"
                    />
                    <span
                        aria-hidden="true"
                        class="relative inline-flex h-[18px] w-8 flex-shrink-0 rounded-full bg-[var(--color-surface-hover)] transition-colors peer-checked:bg-[var(--color-success)]"
                    >
                        <span
                            class="absolute top-0.5 left-0.5 h-[14px] w-[14px] rounded-full bg-white shadow-sm transition-transform peer-checked:translate-x-[14px]"
                        />
                    </span>
                    Indicate that the portal supports session extension
                </label>
            </FormField>

            <button
                type="submit"
                data-testid="action-save"
                :disabled="form.processing"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-[var(--color-accent-text)] transition-colors hover:bg-[var(--color-primary-hover)]"
            >
                Save Settings
            </button>
        </form>

        <!-- Example Response -->
        <h2
            data-testid="section-heading-example"
            class="font-heading mt-10 mb-4 text-[14px] font-bold tracking-[0.04em] text-[var(--color-text-secondary)] uppercase"
        >
            Example Response
        </h2>

        <p class="mb-3 text-[13px] text-[var(--color-text-secondary)]">
            When a client with no internet access queries the API, the response will look like:
        </p>

        <div class="rounded border border-[var(--color-border)] bg-[var(--color-bg)] p-4">
            <pre
                class="font-mono text-[12px] leading-relaxed text-[var(--color-text-muted)]"
                data-testid="example-response"
                >{{
                    JSON.stringify(
                        {
                            captive: true,
                            ...(form.user_portal_url ? { 'user-portal-url': form.user_portal_url } : {}),
                            ...(form.venue_info_url ? { 'venue-info-url': form.venue_info_url } : {}),
                            ...(form.can_extend_session ? { 'can-extend-session': true } : {}),
                        },
                        null,
                        2,
                    )
                }}</pre
            >
        </div>
    </div>
</template>
