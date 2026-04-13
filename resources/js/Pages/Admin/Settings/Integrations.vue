<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    integrations: { type: Object, default: () => ({}) },
});

const form = useForm({
    opnsense: {
        endpoint: props.integrations?.opnsense?.endpoint ?? '',
        key: props.integrations?.opnsense?.key ?? '',
        secret: props.integrations?.opnsense?.secret ?? '',
        captive_portal_id: props.integrations?.opnsense?.captive_portal_id ?? '',
        verify_ssl: props.integrations?.opnsense?.verify_ssl ?? '1',
        zone_id: props.integrations?.opnsense?.zone_id ?? '',
        ratelimit_up_uuid: props.integrations?.opnsense?.ratelimit_up_uuid ?? '',
        ratelimit_down_uuid: props.integrations?.opnsense?.ratelimit_down_uuid ?? '',
    },
    librenms: {
        endpoint: props.integrations?.librenms?.endpoint ?? '',
        api_key: props.integrations?.librenms?.api_key ?? '',
        enabled: props.integrations?.librenms?.enabled ?? '0',
    },
    ntopng: {
        endpoint: props.integrations?.ntopng?.endpoint ?? '',
        username: props.integrations?.ntopng?.username ?? '',
        password: props.integrations?.ntopng?.password ?? '',
        interface: props.integrations?.ntopng?.interface ?? '',
        enabled: props.integrations?.ntopng?.enabled ?? '0',
    },
    pihole: {
        endpoint: props.integrations?.pihole?.endpoint ?? '',
        password: props.integrations?.pihole?.password ?? '',
        noblock_group_id: props.integrations?.pihole?.noblock_group_id ?? '',
        enabled: props.integrations?.pihole?.enabled ?? '0',
        verify_ssl: props.integrations?.pihole?.verify_ssl ?? '1',
    },
    dhcp: {
        enabled: props.integrations?.dhcp?.enabled ?? '0',
        endpoint: props.integrations?.dhcp?.endpoint ?? '',
        key: props.integrations?.dhcp?.key ?? '',
        secret: props.integrations?.dhcp?.secret ?? '',
        verify_ssl: props.integrations?.dhcp?.verify_ssl ?? '1',
        pool_size: props.integrations?.dhcp?.pool_size ?? '',
    },
    dns: {
        expected_server: props.integrations?.dns?.expected_server ?? '',
        probe_domain: props.integrations?.dns?.probe_domain ?? '',
    },
    auto_allow: {
        enabled: props.integrations?.auto_allow?.enabled ?? '0',
        oui_prefixes: props.integrations?.auto_allow?.oui_prefixes ?? '',
        scan_interval: props.integrations?.auto_allow?.scan_interval ?? '',
    },
    ipv6: {
        detection_enabled: props.integrations?.ipv6?.detection_enabled ?? '0',
        detection_endpoint: props.integrations?.ipv6?.detection_endpoint ?? '',
    },
});

function submit() {
    form.put(route('admin.settings.integrations.update'));
}
</script>

<template>
    <SettingsNav>
        <h1 data-testid="page-title" class="font-heading mb-5 text-xl font-bold text-[var(--color-text)] sm:text-2xl">
            Integration Settings
        </h1>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- OPNsense -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-opnsense"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">OPNsense</h2>
                <div class="space-y-4">
                    <FormField label="Endpoint" name="opnsense.endpoint" :error="form.errors['opnsense.endpoint']">
                        <input
                            v-model="form.opnsense.endpoint"
                            type="url"
                            data-testid="integration-opnsense-endpoint"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="API Key" name="opnsense.key" :error="form.errors['opnsense.key']">
                        <input
                            v-model="form.opnsense.key"
                            type="password"
                            data-testid="integration-opnsense-key"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="API Secret" name="opnsense.secret" :error="form.errors['opnsense.secret']">
                        <input
                            v-model="form.opnsense.secret"
                            type="password"
                            data-testid="integration-opnsense-secret"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField
                        label="Captive Portal ID"
                        name="opnsense.captive_portal_id"
                        :error="form.errors['opnsense.captive_portal_id']"
                    >
                        <input
                            v-model="form.opnsense.captive_portal_id"
                            type="text"
                            data-testid="integration-opnsense-captive-portal-id"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField
                        label="Verify SSL"
                        name="opnsense.verify_ssl"
                        :error="form.errors['opnsense.verify_ssl']"
                    >
                        <select
                            v-model="form.opnsense.verify_ssl"
                            data-testid="integration-opnsense-verify-ssl"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField label="Zone ID" name="opnsense.zone_id" :error="form.errors['opnsense.zone_id']">
                        <input
                            v-model="form.opnsense.zone_id"
                            type="text"
                            data-testid="integration-opnsense-zone-id"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField
                        label="Rate Limit Upload Rule UUID"
                        name="opnsense.ratelimit_up_uuid"
                        :error="form.errors['opnsense.ratelimit_up_uuid']"
                    >
                        <input
                            v-model="form.opnsense.ratelimit_up_uuid"
                            type="text"
                            data-testid="integration-opnsense-ratelimit-up-uuid"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField
                        label="Rate Limit Download Rule UUID"
                        name="opnsense.ratelimit_down_uuid"
                        :error="form.errors['opnsense.ratelimit_down_uuid']"
                    >
                        <input
                            v-model="form.opnsense.ratelimit_down_uuid"
                            type="text"
                            data-testid="integration-opnsense-ratelimit-down-uuid"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                </div>
            </section>

            <!-- LibreNMS -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-librenms"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">LibreNMS</h2>
                <div class="space-y-4">
                    <FormField label="Endpoint" name="librenms.endpoint" :error="form.errors['librenms.endpoint']">
                        <input
                            v-model="form.librenms.endpoint"
                            type="url"
                            data-testid="integration-librenms-endpoint"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="API Key" name="librenms.api_key" :error="form.errors['librenms.api_key']">
                        <input
                            v-model="form.librenms.api_key"
                            type="password"
                            data-testid="integration-librenms-api-key"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Enabled" name="librenms.enabled" :error="form.errors['librenms.enabled']">
                        <select
                            v-model="form.librenms.enabled"
                            data-testid="integration-librenms-enabled"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                </div>
            </section>

            <!-- ntopng -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-ntopng"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">ntopng</h2>
                <div class="space-y-4">
                    <FormField label="Enabled" name="ntopng.enabled" :error="form.errors['ntopng.enabled']">
                        <select
                            v-model="form.ntopng.enabled"
                            data-testid="integration-ntopng-enabled"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField label="Endpoint" name="ntopng.endpoint" :error="form.errors['ntopng.endpoint']">
                        <input
                            v-model="form.ntopng.endpoint"
                            type="url"
                            data-testid="integration-ntopng-endpoint"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Username" name="ntopng.username" :error="form.errors['ntopng.username']">
                        <input
                            v-model="form.ntopng.username"
                            type="text"
                            data-testid="integration-ntopng-username"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Password" name="ntopng.password" :error="form.errors['ntopng.password']">
                        <input
                            v-model="form.ntopng.password"
                            type="password"
                            data-testid="integration-ntopng-password"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Interface" name="ntopng.interface" :error="form.errors['ntopng.interface']">
                        <input
                            v-model="form.ntopng.interface"
                            type="text"
                            data-testid="integration-ntopng-interface"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                </div>
            </section>

            <!-- Pi-hole -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-pihole"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">Pi-hole</h2>
                <div class="space-y-4">
                    <FormField label="Endpoint" name="pihole.endpoint" :error="form.errors['pihole.endpoint']">
                        <input
                            v-model="form.pihole.endpoint"
                            type="url"
                            data-testid="integration-pihole-endpoint"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Password" name="pihole.password" :error="form.errors['pihole.password']">
                        <input
                            v-model="form.pihole.password"
                            type="password"
                            data-testid="integration-pihole-password"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField
                        label="No-Block Group ID"
                        name="pihole.noblock_group_id"
                        :error="form.errors['pihole.noblock_group_id']"
                    >
                        <input
                            v-model="form.pihole.noblock_group_id"
                            type="number"
                            data-testid="integration-pihole-group-id"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Enabled" name="pihole.enabled" :error="form.errors['pihole.enabled']">
                        <select
                            v-model="form.pihole.enabled"
                            data-testid="integration-pihole-enabled"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField label="Verify SSL" name="pihole.verify_ssl" :error="form.errors['pihole.verify_ssl']">
                        <select
                            v-model="form.pihole.verify_ssl"
                            data-testid="integration-pihole-verify-ssl"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                </div>
            </section>

            <!-- DHCP -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-dhcp"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">DHCP</h2>
                <div class="space-y-4">
                    <FormField label="Enabled" name="dhcp.enabled" :error="form.errors['dhcp.enabled']">
                        <select
                            v-model="form.dhcp.enabled"
                            data-testid="integration-dhcp-enabled"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField label="Endpoint" name="dhcp.endpoint" :error="form.errors['dhcp.endpoint']">
                        <input
                            v-model="form.dhcp.endpoint"
                            type="url"
                            data-testid="integration-dhcp-endpoint"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="API Key" name="dhcp.key" :error="form.errors['dhcp.key']">
                        <input
                            v-model="form.dhcp.key"
                            type="password"
                            data-testid="integration-dhcp-key"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="API Secret" name="dhcp.secret" :error="form.errors['dhcp.secret']">
                        <input
                            v-model="form.dhcp.secret"
                            type="password"
                            data-testid="integration-dhcp-secret"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Verify SSL" name="dhcp.verify_ssl" :error="form.errors['dhcp.verify_ssl']">
                        <select
                            v-model="form.dhcp.verify_ssl"
                            data-testid="integration-dhcp-verify-ssl"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField label="Pool Size" name="dhcp.pool_size" :error="form.errors['dhcp.pool_size']">
                        <input
                            v-model="form.dhcp.pool_size"
                            type="number"
                            data-testid="integration-dhcp-pool-size"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                </div>
            </section>

            <!-- DNS Probe -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-dns"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">DNS Probe</h2>
                <div class="space-y-4">
                    <FormField
                        label="Expected Server"
                        name="dns.expected_server"
                        :error="form.errors['dns.expected_server']"
                    >
                        <input
                            v-model="form.dns.expected_server"
                            type="text"
                            data-testid="integration-dns-expected-server"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                    <FormField label="Probe Domain" name="dns.probe_domain" :error="form.errors['dns.probe_domain']">
                        <input
                            v-model="form.dns.probe_domain"
                            type="text"
                            data-testid="integration-dns-probe-domain"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                </div>
            </section>

            <!-- Auto Allow -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-auto-allow"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">Auto Allow</h2>
                <div class="space-y-4">
                    <FormField label="Enabled" name="auto_allow.enabled" :error="form.errors['auto_allow.enabled']">
                        <select
                            v-model="form.auto_allow.enabled"
                            data-testid="integration-auto-allow-enabled"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField
                        label="OUI Prefixes"
                        name="auto_allow.oui_prefixes"
                        :error="form.errors['auto_allow.oui_prefixes']"
                    >
                        <textarea
                            v-model="form.auto_allow.oui_prefixes"
                            data-testid="integration-auto-allow-oui-prefixes"
                            rows="3"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                            placeholder="Comma-separated MAC prefixes, e.g. 98:5F:D3,7C:ED:8D"
                        />
                    </FormField>
                    <FormField
                        label="Scan Interval (minutes)"
                        name="auto_allow.scan_interval"
                        :error="form.errors['auto_allow.scan_interval']"
                    >
                        <input
                            v-model="form.auto_allow.scan_interval"
                            type="number"
                            data-testid="integration-auto-allow-scan-interval"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                </div>
            </section>

            <!-- IPv6 Detection -->
            <section
                class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
                data-testid="integration-ipv6"
            >
                <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">IPv6 Detection</h2>
                <div class="space-y-4">
                    <FormField
                        label="Enabled"
                        name="ipv6.detection_enabled"
                        :error="form.errors['ipv6.detection_enabled']"
                    >
                        <select
                            v-model="form.ipv6.detection_enabled"
                            data-testid="integration-ipv6-detection-enabled"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        >
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </FormField>
                    <FormField
                        label="Detection Endpoint"
                        name="ipv6.detection_endpoint"
                        :error="form.errors['ipv6.detection_endpoint']"
                    >
                        <input
                            v-model="form.ipv6.detection_endpoint"
                            type="url"
                            data-testid="integration-ipv6-detection-endpoint"
                            class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                        />
                    </FormField>
                </div>
            </section>

            <button
                type="submit"
                data-testid="action-save-integrations"
                :disabled="form.processing"
                class="rounded-lg bg-[var(--color-primary)] px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-hover)]"
            >
                Save Settings
            </button>
        </form>
    </SettingsNav>
</template>
