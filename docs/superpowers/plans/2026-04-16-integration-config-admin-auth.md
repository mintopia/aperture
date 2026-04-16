# Integration Config & Admin Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make all integration configs editable with proper field types, move Borealis to DB config, and add email/password + passkey login for admins.

**Architecture:** Integration config fields are defined in `config/integrations.php` with type metadata. Borealis moves from env vars to `IntegrationConfig` DB table like other integrations. Admin login uses Laravel's built-in `Auth::attempt()` for email/password and `laragear/webauthn` for passkeys. The login page at `/login` replaces the current redirect-to-captive-portal stub.

**Tech Stack:** Laravel 13, Inertia.js/Vue 3, laragear/webauthn, PHPUnit, Vitest

---

## File Map

### Config Changes
- **Modify:** `config/integrations.php` — Add `fields` array with type/label/placeholder/help per integration; add `borealis` entry
- **Modify:** `config/aperture.php` — Remove `borealis` block
- **Modify:** `config/auth.php` — Ensure Eloquent provider uses User model (should already be correct)

### Models
- **Modify:** `app/Models/User.php` — Add `password` to hidden/casts, add `WebAuthnAuthenticatable` trait
- **Create:** `database/migrations/2026_04_16_200000_add_password_to_users_table.php`

### Controllers
- **Create:** `app/Http/Controllers/LoginController.php` — showLoginForm, authenticate, passkey endpoints
- **Modify:** `app/Http/Controllers/Admin/IntegrationController.php` — Pass field definitions to view
- **Modify:** `app/Http/Controllers/Admin/SettingsController.php` — Include borealis in integrations list (remove read-only)
- **Modify:** `app/Http/Controllers/Admin/TestConnectionController.php` — Add `testBorealis()`
- **Modify:** `app/Http/Controllers/Admin/UserController.php` — Add password set/clear

### Service Providers
- **Modify:** `app/Providers/AppServiceProvider.php` — Read BorealisService config from IntegrationConfig DB instead of env

### Frontend
- **Modify:** `resources/js/Pages/Admin/Settings/IntegrationShow.vue` — Render proper field types from field definitions
- **Create:** `resources/js/Pages/Auth/Login.vue` — Login page with email/password + passkey
- **Modify:** `resources/js/Pages/Admin/Users/Edit.vue` — Add password management section (if exists, else modify Show)

### Routes
- **Modify:** `routes/web.php` — Replace `/login` redirect with LoginController; add passkey routes; add borealis test route

### Tests
- **Create:** `tests/Feature/Admin/IntegrationFieldDefinitionsTest.php`
- **Create:** `tests/Feature/LoginControllerTest.php`
- **Create:** `tests/Feature/Admin/UserPasswordManagementTest.php`
- **Create:** `tests/Feature/PasskeyAuthenticationTest.php`
- **Create:** `tests/js/Pages/Auth/Login.spec.js`
- **Modify:** `tests/js/Pages/Admin/Settings/IntegrationShow.spec.js`
- **Modify:** `tests/Feature/Admin/IntegrationControllerTest.php`
- **Modify:** `tests/Unit/Providers/AppServiceProviderTest.php`

---

## Task 1: Integration Config Field Definitions

**Files:**
- Modify: `config/integrations.php`
- Modify: `app/Http/Controllers/Admin/IntegrationController.php`
- Modify: `resources/js/Pages/Admin/Settings/IntegrationShow.vue`
- Create: `tests/Feature/Admin/IntegrationFieldDefinitionsTest.php`
- Modify: `tests/js/Pages/Admin/Settings/IntegrationShow.spec.js`

### Context

Currently `config/integrations.php` only has `validation` rules. The frontend renders all fields as plain text inputs. We need field metadata so the frontend can render password fields for secrets, toggles for booleans, and helpful labels/placeholders.

### Field Schema

Each field in the `fields` array uses this shape:

```php
'endpoint' => [
    'type' => 'url',        // text, url, password, toggle, number, select
    'label' => 'API Endpoint',
    'placeholder' => 'https://opnsense.local/api',
    'help' => 'The base URL of your OPNsense API.',
    'required' => false,
],
```

- [ ] **Step 1: Write failing PHP test for field definitions**

Create `tests/Feature/Admin/IntegrationFieldDefinitionsTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationFieldDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = \App\Models\Role::factory()->create(['code' => 'admin']);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_integration_config_has_fields_for_all_integrations(): void
    {
        $integrations = config('integrations');
        foreach ($integrations as $key => $integration) {
            $this->assertArrayHasKey('fields', $integration, "Integration '{$key}' is missing 'fields' array.");
            $this->assertNotEmpty($integration['fields'], "Integration '{$key}' has empty 'fields' array.");
        }
    }

    public function test_each_field_has_required_attributes(): void
    {
        $integrations = config('integrations');
        foreach ($integrations as $service => $integration) {
            foreach ($integration['fields'] as $fieldKey => $field) {
                $this->assertArrayHasKey('type', $field, "Field '{$fieldKey}' in '{$service}' missing 'type'.");
                $this->assertArrayHasKey('label', $field, "Field '{$fieldKey}' in '{$service}' missing 'label'.");
                $this->assertContains($field['type'], ['text', 'url', 'password', 'toggle', 'number', 'select'], "Field '{$fieldKey}' in '{$service}' has invalid type '{$field['type']}'.");
            }
        }
    }

    public function test_show_page_includes_field_definitions(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/opnsense');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/IntegrationShow')
            ->has('service.fields')
            ->where('service.fields.endpoint.type', 'url')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IntegrationFieldDefinitionsTest`
Expected: FAIL — `fields` key missing from config and Inertia response.

- [ ] **Step 3: Add field definitions to `config/integrations.php`**

Replace the entire file content:

```php
<?php

return [
    'opnsense' => [
        'name' => 'OPNsense',
        'description' => 'Network firewall providing captive portal, rate limiting, and DHCP services.',
        'capabilities' => ['captive-portal', 'firewall', 'rate-limiting', 'dhcp'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://opnsense.local/api',
                'help' => 'Base URL of your OPNsense installation.',
            ],
            'key' => [
                'type' => 'password',
                'label' => 'API Key',
                'placeholder' => '',
                'help' => 'OPNsense API key for authentication.',
            ],
            'secret' => [
                'type' => 'password',
                'label' => 'API Secret',
                'placeholder' => '',
                'help' => 'OPNsense API secret for authentication.',
            ],
            'captive_portal_id' => [
                'type' => 'text',
                'label' => 'Captive Portal Zone ID',
                'placeholder' => '0',
                'help' => 'The numeric zone ID for the captive portal.',
            ],
            'zone_id' => [
                'type' => 'text',
                'label' => 'Firewall Zone ID',
                'placeholder' => '0',
                'help' => 'The numeric firewall zone ID.',
            ],
            'verify_ssl' => [
                'type' => 'toggle',
                'label' => 'Verify SSL',
                'help' => 'Verify the SSL certificate when connecting.',
            ],
            'ratelimit_up_uuid' => [
                'type' => 'text',
                'label' => 'Upload Rate Limit Rule UUID',
                'placeholder' => '',
                'help' => 'UUID of the traffic shaper pipe for upload limiting.',
            ],
            'ratelimit_down_uuid' => [
                'type' => 'text',
                'label' => 'Download Rate Limit Rule UUID',
                'placeholder' => '',
                'help' => 'UUID of the traffic shaper pipe for download limiting.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'key' => 'nullable|string|max:500',
            'secret' => 'nullable|string|max:500',
            'captive_portal_id' => 'nullable|string|max:100',
            'verify_ssl' => 'nullable|string|in:0,1',
            'zone_id' => 'nullable|string|max:100',
            'ratelimit_up_uuid' => 'nullable|string|max:500',
            'ratelimit_down_uuid' => 'nullable|string|max:500',
        ],
    ],
    'librenms' => [
        'name' => 'LibreNMS',
        'description' => 'Network monitoring for IP/MAC resolution, port mapping, and bandwidth data.',
        'capabilities' => ['ip-to-mac', 'mac-to-port', 'port-bandwidth', 'device-list'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://librenms.local/api/v0',
                'help' => 'Base URL of the LibreNMS API (v0).',
            ],
            'api_key' => [
                'type' => 'password',
                'label' => 'API Key',
                'placeholder' => '',
                'help' => 'LibreNMS API token for authentication.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'api_key' => 'nullable|string|max:500',
            'enabled' => 'nullable|string|in:0,1',
        ],
    ],
    'ntopng' => [
        'name' => 'ntopng',
        'description' => 'Traffic analysis providing per-user bandwidth metrics and top talker data.',
        'capabilities' => ['user-bandwidth', 'top-talkers', 'aggregate-stats'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://ntopng.local:3000',
                'help' => 'Base URL of your ntopng instance.',
            ],
            'username' => [
                'type' => 'text',
                'label' => 'Username',
                'placeholder' => 'admin',
                'help' => 'Username for ntopng authentication.',
            ],
            'password' => [
                'type' => 'password',
                'label' => 'Password',
                'placeholder' => '',
                'help' => 'Password for ntopng authentication.',
            ],
            'interface' => [
                'type' => 'text',
                'label' => 'Interface',
                'placeholder' => '0',
                'help' => 'Network interface index to monitor.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:500',
            'interface' => 'nullable|string|max:100',
            'enabled' => 'nullable|string|in:0,1',
        ],
    ],
    'pihole' => [
        'name' => 'Pi-hole',
        'description' => 'DNS filtering and optional DHCP/IP-to-MAC resolution.',
        'capabilities' => ['dns-filtering', 'dhcp', 'ip-to-mac'],
        'fields' => [
            'endpoint' => [
                'type' => 'url',
                'label' => 'API Endpoint',
                'placeholder' => 'https://pihole.local',
                'help' => 'Base URL of your Pi-hole admin interface.',
            ],
            'password' => [
                'type' => 'password',
                'label' => 'API Password',
                'placeholder' => '',
                'help' => 'Pi-hole admin password or app password.',
            ],
            'noblock_group_id' => [
                'type' => 'number',
                'label' => 'No-Block Group ID',
                'placeholder' => '1',
                'help' => 'Pi-hole group ID for clients that should bypass blocking.',
            ],
            'verify_ssl' => [
                'type' => 'toggle',
                'label' => 'Verify SSL',
                'help' => 'Verify the SSL certificate when connecting.',
            ],
            'enabled' => [
                'type' => 'toggle',
                'label' => 'Enabled',
                'help' => 'Enable or disable this integration.',
            ],
        ],
        'validation' => [
            'endpoint' => 'nullable|url|max:500',
            'password' => 'nullable|string|max:500',
            'noblock_group_id' => 'nullable|integer|min:1',
            'enabled' => 'nullable|string|in:0,1',
            'verify_ssl' => 'nullable|string|in:0,1',
        ],
    ],
];
```

- [ ] **Step 4: Pass field definitions from IntegrationController to view**

In `app/Http/Controllers/Admin/IntegrationController.php`, modify the `show()` method to include field definitions in the Inertia response. Add this to the `'service'` array returned to Inertia:

```php
'fields' => collect($meta['fields'] ?? [])->map(fn (array $field, string $key): array => [
    'key' => $key,
    'type' => $field['type'],
    'label' => $field['label'],
    'placeholder' => $field['placeholder'] ?? '',
    'help' => $field['help'] ?? '',
    'required' => $field['required'] ?? false,
])->values()->all(),
```

Also update `'config'` to include all defined field keys even if they don't have a DB value yet:

```php
'config' => collect($meta['fields'] ?? [])->mapWithKeys(fn (array $field, string $key): array => [
    $key => $config[$key] ?? '',
])->all(),
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=IntegrationFieldDefinitionsTest`
Expected: PASS

- [ ] **Step 6: Update IntegrationShow.vue for proper field types**

Replace the config form section in `resources/js/Pages/Admin/Settings/IntegrationShow.vue`. The form initialization should use `service.fields` to ensure all fields are present:

```javascript
// Initialize form with all defined fields, using config values or defaults
const initialConfig = {};
props.service.fields.forEach((field) => {
    initialConfig[field.key] = props.service.config[field.key] ?? '';
});

const form = useForm({
    config: initialConfig,
});
```

Replace the `<div class="grid gap-4 md:grid-cols-2">` block with field-type-aware rendering:

```html
<div class="grid gap-4 md:grid-cols-2">
    <FormField
        v-for="field in service.fields"
        :key="field.key"
        :label="field.label"
        :name="field.key"
        :required="field.required"
        :error="form.errors[`config.${field.key}`]"
    >
        <template v-if="field.type === 'toggle'">
            <button
                :id="field.key"
                type="button"
                :data-testid="`field-toggle-${field.key}`"
                role="switch"
                :aria-checked="form.config[field.key] === '1' || form.config[field.key] === true"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:ring-2 focus:ring-[var(--color-primary)]/20 focus:outline-none"
                :class="form.config[field.key] === '1' || form.config[field.key] === true ? 'bg-[var(--color-primary)]' : 'bg-[var(--color-border)]'"
                @click="form.config[field.key] = form.config[field.key] === '1' || form.config[field.key] === true ? '0' : '1'"
            >
                <span
                    class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                    :class="form.config[field.key] === '1' || form.config[field.key] === true ? 'translate-x-5' : 'translate-x-0'"
                />
            </button>
        </template>
        <template v-else>
            <input
                :id="field.key"
                v-model="form.config[field.key]"
                :name="field.key"
                :type="field.type === 'url' ? 'url' : field.type === 'number' ? 'number' : field.type === 'password' ? 'password' : 'text'"
                :placeholder="field.placeholder"
                :data-testid="`field-input-${field.key}`"
                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-bg)] px-3 py-2 text-sm text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
            />
        </template>
        <p v-if="field.help" class="text-xs text-[var(--color-text-muted)]">{{ field.help }}</p>
    </FormField>
</div>

<div v-if="service.fields.length === 0" class="text-sm text-[var(--color-text-muted)]">
    No configuration options available.
</div>
```

Remove the old `fieldLabel()` function since labels now come from field definitions.

- [ ] **Step 7: Update JS tests for IntegrationShow**

Update `tests/js/Pages/Admin/Settings/IntegrationShow.spec.js` to include field definitions in the test props:

```javascript
const defaultService = {
    id: 'opnsense',
    name: 'OPNsense',
    description: 'Test integration',
    config: { endpoint: 'https://fw.local', key: '' },
    fields: [
        { key: 'endpoint', type: 'url', label: 'API Endpoint', placeholder: 'https://opnsense.local/api', help: 'Base URL', required: false },
        { key: 'key', type: 'password', label: 'API Key', placeholder: '', help: 'API key', required: false },
    ],
    capabilities: [{ name: 'captive-portal', active: true }],
    health: true,
    logs: [],
};
```

Add tests:
- `renders url input for url-type fields`
- `renders password input for password-type fields`
- `renders toggle button for toggle-type fields`
- `displays field help text`

- [ ] **Step 8: Run all tests**

Run: `php artisan test --compact --filter=IntegrationFieldDefinitionsTest && php artisan test --compact --filter=IntegrationControllerTest && npx vitest run tests/js/Pages/Admin/Settings/IntegrationShow.spec.js`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/integrations.php app/Http/Controllers/Admin/IntegrationController.php resources/js/Pages/Admin/Settings/IntegrationShow.vue tests/Feature/Admin/IntegrationFieldDefinitionsTest.php tests/js/Pages/Admin/Settings/IntegrationShow.spec.js
git commit -m "feat: add typed field definitions to integration config pages

Add fields array to config/integrations.php with type, label,
placeholder, and help text per field. IntegrationShow.vue now renders
url/password/toggle/number/text inputs based on field type.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 2: Borealis as Integration

**Files:**
- Modify: `config/integrations.php` — Add borealis entry
- Modify: `config/aperture.php` — Remove borealis block
- Modify: `app/Providers/AppServiceProvider.php` — Read BorealisService from IntegrationConfig
- Modify: `app/Http/Controllers/Admin/SettingsController.php` — Remove borealis read-only exception
- Modify: `database/factories/IntegrationConfigFactory.php` — Add borealis to random element list
- Modify: `tests/Unit/Providers/AppServiceProviderTest.php`
- Modify: `tests/Feature/Admin/SettingsControllerIntegrationExpansionTest.php`

### Context

Borealis currently uses env vars via `config/aperture.php`. We're moving it entirely to the `IntegrationConfig` DB table. The `AppServiceProvider` creates a `BorealisService` singleton reading from env — it needs to read from DB instead.

- [ ] **Step 1: Write failing test for Borealis DB config**

Add to `tests/Unit/Providers/AppServiceProviderTest.php`:

```php
public function test_boot_registers_borealis_service_singleton_from_db_config(): void
{
    IntegrationConfig::setValue('borealis', 'endpoint', 'https://auth.test.local');
    IntegrationConfig::setValue('borealis', 'client_id', 'test-client-id');
    IntegrationConfig::setValue('borealis', 'client_secret', 'test-secret', true);

    $service = $this->app->make(BorealisService::class);

    $this->assertInstanceOf(BorealisService::class, $service);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_boot_registers_borealis_service_singleton_from_db_config`
Expected: FAIL — BorealisService still reads from env config.

- [ ] **Step 3: Add borealis to `config/integrations.php`**

Add this entry to the config array (after `pihole`):

```php
'borealis' => [
    'name' => 'Borealis',
    'description' => 'OAuth2 authentication provider for user login via device code flow.',
    'capabilities' => ['authentication', 'sso', 'user-info'],
    'fields' => [
        'endpoint' => [
            'type' => 'url',
            'label' => 'OAuth2 Endpoint',
            'placeholder' => 'https://auth.borealis.example.com',
            'help' => 'Base URL of the Borealis OAuth2 server.',
        ],
        'client_id' => [
            'type' => 'text',
            'label' => 'Client ID',
            'placeholder' => '',
            'help' => 'OAuth2 client ID for this application.',
        ],
        'client_secret' => [
            'type' => 'password',
            'label' => 'Client Secret',
            'placeholder' => '',
            'help' => 'OAuth2 client secret for this application.',
        ],
        'scope' => [
            'type' => 'text',
            'label' => 'Scope',
            'placeholder' => 'discord',
            'help' => 'OAuth2 scope to request during device flow.',
        ],
    ],
    'validation' => [
        'endpoint' => 'nullable|url|max:500',
        'client_id' => 'nullable|string|max:500',
        'client_secret' => 'nullable|string|max:500',
        'scope' => 'nullable|string|max:255',
    ],
],
```

- [ ] **Step 4: Remove borealis from `config/aperture.php`**

Remove the entire `'borealis'` key from the config array. The file should only have `cisco`, `session`, and `ssh_proxy` remaining.

- [ ] **Step 5: Update AppServiceProvider to read BorealisService from DB**

In `app/Providers/AppServiceProvider.php`, replace the BorealisService singleton in `boot()`:

```php
$this->app->singleton(function (Application $application): BorealisService {
    $dbConfig = $this->getIntegrationDbConfig('borealis');

    return new BorealisService(
        clientId: (string) ($dbConfig['client_id'] ?? ''),
        clientSecret: (string) ($dbConfig['client_secret'] ?? ''),
        endpoint: (string) ($dbConfig['endpoint'] ?? ''),
    );
});
```

- [ ] **Step 6: Remove borealis read-only handling from SettingsController**

In `app/Http/Controllers/Admin/SettingsController.php`, the `integrations()` method currently adds borealis as a special read-only service from `config('aperture.borealis')`. Remove that logic entirely. Borealis is now a normal entry in `config('integrations')` and will appear automatically via the standard loop.

Find and remove the borealis-specific code block that adds it with `'readonly' => true`. The standard integrations loop from `config('integrations')` will now include borealis automatically.

- [ ] **Step 7: Update IntegrationConfigFactory**

In `database/factories/IntegrationConfigFactory.php`, add `'borealis'` to the `randomElement` array:

```php
'integration' => fake()->randomElement(['opnsense', 'librenms', 'pihole', 'ntopng', 'borealis']),
```

- [ ] **Step 8: Update existing AppServiceProvider tests**

The existing `test_boot_registers_borealis_service_singleton` test reads from env config — update it to use IntegrationConfig DB values instead. Remove any `config(['aperture.borealis...'])` calls and use `IntegrationConfig::setValue('borealis', ...)` instead.

- [ ] **Step 9: Update SettingsController integration test**

In `tests/Feature/Admin/SettingsControllerIntegrationExpansionTest.php`, the first test (`test_integrations_page_returns_services_table_data`) should now assert borealis appears in services without a `readonly` flag. Update assertions to verify borealis is in the services list with its proper name.

- [ ] **Step 10: Run all tests**

Run: `php artisan test --compact --filter="AppServiceProviderTest|IntegrationControllerTest|SettingsControllerIntegrationExpansionTest|IntegrationFieldDefinitionsTest"`
Expected: PASS

- [ ] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/integrations.php config/aperture.php app/Providers/AppServiceProvider.php app/Http/Controllers/Admin/SettingsController.php database/factories/IntegrationConfigFactory.php tests/
git commit -m "feat: move Borealis config from env vars to IntegrationConfig DB

Borealis is now a regular integration in config/integrations.php with
editable fields. AppServiceProvider reads BorealisService config from
IntegrationConfig DB instead of env vars. Removed borealis block from
config/aperture.php and removed read-only special-casing.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 3: Borealis Test Connection

**Files:**
- Modify: `app/Http/Controllers/Admin/TestConnectionController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Admin/TestConnectionControllerTest.php`

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/Admin/TestConnectionControllerTest.php`:

```php
public function test_admin_can_test_borealis_connection_success(): void
{
    $admin = $this->createAdminUser();
    $mock = Mockery::mock(BorealisService::class);
    $mock->shouldReceive('getDeviceCodeRaw')
        ->once()
        ->with('test')
        ->andReturn((object) [
            'device_code' => 'test-code',
            'user_code' => 'TEST-CODE',
            'verification_uri' => 'https://auth.test/verify',
            'expires_in' => 300,
            'interval' => 5,
        ]);
    $this->app->instance(BorealisService::class, $mock);

    $response = $this->actingAs($admin)->postJson('/admin/settings/test/borealis');

    $response->assertOk();
    $response->assertJson(['success' => true]);
}

public function test_admin_can_test_borealis_connection_failure(): void
{
    $admin = $this->createAdminUser();
    $mock = Mockery::mock(BorealisService::class);
    $mock->shouldReceive('getDeviceCodeRaw')
        ->once()
        ->andThrow(new \Exception('Connection refused'));
    $this->app->instance(BorealisService::class, $mock);

    $response = $this->actingAs($admin)->postJson('/admin/settings/test/borealis');

    $response->assertOk();
    $response->assertJson(['success' => false]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_admin_can_test_borealis_connection`
Expected: FAIL — Route not found.

- [ ] **Step 3: Add testBorealis method to TestConnectionController**

```php
public function testBorealis(BorealisService $borealis): JsonResponse
{
    try {
        $borealis->getDeviceCodeRaw('test');
        ConnectionTestLog::record('borealis', true, 'Borealis OAuth2 endpoint is reachable.');

        return response()->json(['success' => true, 'message' => 'Borealis OAuth2 endpoint is reachable.']);
    } catch (Throwable $e) {
        ConnectionTestLog::record('borealis', false, $e->getMessage());

        return response()->json(['success' => false, 'message' => $e->getMessage()]);
    }
}
```

- [ ] **Step 4: Add route**

In `routes/web.php`, add alongside the other test routes:

```php
Route::post('/settings/test/borealis', [TestConnectionController::class, 'testBorealis'])->name('settings.test.borealis');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=test_admin_can_test_borealis_connection`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/TestConnectionController.php routes/web.php tests/Feature/Admin/TestConnectionControllerTest.php
git commit -m "feat: add Borealis connection test endpoint

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 4: User Password Support

**Files:**
- Create: `database/migrations/2026_04_16_200000_add_password_to_users_table.php`
- Modify: `app/Models/User.php`
- Modify: `database/factories/UserFactory.php`
- Create: `tests/Feature/UserPasswordTest.php`

- [ ] **Step 1: Write failing test**

Create `tests/Feature/UserPasswordTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_password_set(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->password = Hash::make('secret123');
        $user->save();

        $user->refresh();
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_password_is_nullable_by_default(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->assertNull($user->password);
    }

    public function test_password_is_hidden_from_serialization(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $user->password = Hash::make('secret');
        $user->save();

        $array = $user->toArray();
        $this->assertArrayNotHasKey('password', $array);
    }

    public function test_factory_with_password_state(): void
    {
        Queue::fake();
        $user = User::factory()->withPassword('testpass123')->create();

        $this->assertTrue(Hash::check('testpass123', $user->password));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=UserPasswordTest`
Expected: FAIL — password column does not exist.

- [ ] **Step 3: Create migration**

Run: `php artisan make:migration add_password_to_users_table --table=users --no-interaction`

Set the `up()` method:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('password')->nullable()->after('email');
    });
}
```

Set the `down()` method:

```php
public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('password');
    });
}
```

- [ ] **Step 4: Update User model**

In `app/Models/User.php`:

1. Add `'password'` to the `$hidden` array (alongside `access_token`, `refresh_token`).
2. Add `'password' => 'hashed'` to the `casts()` method return array.

- [ ] **Step 5: Add factory state**

In `database/factories/UserFactory.php`, add:

```php
public function withPassword(string $password = 'password'): static
{
    return $this->state(fn (): array => [
        'password' => Hash::make($password),
    ]);
}
```

Add `use Illuminate\Support\Facades\Hash;` to the imports.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact --filter=UserPasswordTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/*add_password_to_users_table* app/Models/User.php database/factories/UserFactory.php tests/Feature/UserPasswordTest.php
git commit -m "feat: add nullable password column to users table

Supports email/password login for admins. Password is nullable (most
users authenticate via Borealis device flow). Adds hashed cast and
factory withPassword() state.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 5: Login Page (Email/Password)

**Files:**
- Create: `app/Http/Controllers/LoginController.php`
- Create: `resources/js/Pages/Auth/Login.vue`
- Modify: `routes/web.php`
- Modify: `app/Http/Middleware/Authenticate.php`
- Create: `tests/Feature/LoginControllerTest.php`
- Create: `tests/js/Pages/Auth/Login.spec.js`

### Context

Currently `/login` redirects to the captive portal. We replace it with a real login page. The captive portal remains the primary auth flow for regular users. The login page is an alternative for admins with passwords.

- [ ] **Step 1: Write failing PHP test**

Create `tests/Feature/LoginControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LoginControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_page_renders(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    public function test_authenticated_user_is_redirected_from_login(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect('/');
    }

    public function test_user_can_login_with_email_and_password(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'secret123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'admin@test.com',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_fails_for_user_without_password(): void
    {
        User::factory()->create([
            'email' => 'user@test.com',
            'password' => null,
        ]);

        $response = $this->post('/login', [
            'email' => 'user@test.com',
            'password' => 'anything',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_fails_for_nonexistent_email(): void
    {
        $response = $this->post('/login', [
            'email' => 'nobody@test.com',
            'password' => 'anything',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->post('/login', []);

        $response->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'admin@test.com',
            'password' => Hash::make('secret'),
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'email' => 'admin@test.com',
                'password' => 'wrong',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'admin@test.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=LoginControllerTest`
Expected: FAIL — Login page redirects to captive portal.

- [ ] **Step 3: Create LoginController**

Create `app/Http/Controllers/LoginController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $key = 'login-attempt:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials)) {
            RateLimiter::clear($key);
            $request->session()->regenerate();

            return redirect()->intended('/');
        }

        RateLimiter::hit($key, 60);

        throw ValidationException::withMessages([
            'email' => __('The provided credentials do not match our records.'),
        ]);
    }
}
```

- [ ] **Step 4: Update routes**

In `routes/web.php`, replace the existing guest `/login` block:

```php
Route::middleware(['guest'])->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'authenticate']);
});
```

Add the import at the top:
```php
use App\Http\Controllers\LoginController;
```

- [ ] **Step 5: Create Login.vue**

Create `resources/js/Pages/Auth/Login.vue`:

```vue
<script setup>
import { useForm, Head } from '@inertiajs/vue3';
import FormField from '@/Components/UI/FormField.vue';
import { ref } from 'vue';

const form = useForm({
    email: '',
    password: '',
});

const showPasskeyOption = ref(false);

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}

async function loginWithPasskey() {
    // Passkey authentication will be implemented in Task 7
}
</script>

<template>
    <Head title="Login" />
    <div class="flex min-h-screen items-center justify-center bg-[var(--color-bg)] px-4">
        <div class="w-full max-w-md space-y-6">
            <div class="text-center">
                <h1
                    data-testid="login-title"
                    class="font-heading text-2xl font-bold text-[var(--color-text)]"
                >
                    Sign In
                </h1>
                <p class="mt-2 text-sm text-[var(--color-text-secondary)]">
                    Administrator login
                </p>
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
                        @click="loginWithPasskey"
                    >
                        🔑 Sign in with Passkey
                    </button>
                </div>
            </div>

            <p class="text-center text-xs text-[var(--color-text-muted)]">
                Regular users: connect via the
                <a href="/captive" class="text-[var(--color-primary)] hover:underline">captive portal</a>.
            </p>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Write JS tests**

Create `tests/js/Pages/Auth/Login.spec.js`:

```javascript
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Login from '@/Pages/Auth/Login.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn(() => ({
        email: '',
        password: '',
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
    })),
    Head: { template: '<div />' },
}));

describe('Login', () => {
    function mountLogin() {
        return mount(Login, { shallow: true });
    }

    it('renders login title', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-title"]').text()).toBe('Sign In');
    });

    it('renders email and password fields', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-email"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="login-password"]').exists()).toBe(true);
    });

    it('renders submit button', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-submit"]').text()).toBe('Sign In');
    });

    it('renders passkey button', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-passkey"]').exists()).toBe(true);
    });

    it('renders captive portal link', () => {
        const wrapper = mountLogin();
        const link = wrapper.find('a[href="/captive"]');
        expect(link.exists()).toBe(true);
    });

    it('has form action for login', () => {
        const wrapper = mountLogin();
        expect(wrapper.find('[data-testid="login-form"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 7: Run all tests**

Run: `php artisan test --compact --filter=LoginControllerTest && npx vitest run tests/js/Pages/Auth/Login.spec.js`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/LoginController.php resources/js/Pages/Auth/Login.vue routes/web.php tests/Feature/LoginControllerTest.php tests/js/Pages/Auth/Login.spec.js
git commit -m "feat: add email/password login page for administrators

Replace /login captive portal redirect with a real login form.
LoginController handles authentication with rate limiting. Login.vue
renders email/password form with passkey button placeholder.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 6: Admin Password Management

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Modify: `resources/js/Pages/Admin/Users/Edit.vue` (or equivalent user edit page)
- Create: `tests/Feature/Admin/UserPasswordManagementTest.php`

### Context

Admins need to be able to set/clear passwords for users via the admin panel. Check the existing `UserController@update` method and user edit page to understand the current UI. Add a password section to the user edit form.

- [ ] **Step 1: Explore existing UserController and user edit page**

Read `app/Http/Controllers/Admin/UserController.php` and the user edit Vue page to understand the current form structure. The existing `update()` method handles user updates — we add password handling to it.

- [ ] **Step 2: Write failing test**

Create `tests/Feature/Admin/UserPasswordManagementTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserPasswordManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create(['code' => 'admin']);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_admin_can_set_user_password(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertTrue(Hash::check('newpassword123', $target->password));
    }

    public function test_admin_can_clear_user_password(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->withPassword('oldpass')->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'clear_password' => true,
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertNull($target->password);
    }

    public function test_password_must_be_confirmed(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'different',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_password_must_be_at_least_8_characters(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'nickname' => $target->nickname,
            'email' => $target->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_user_edit_page_shows_password_section(): void
    {
        $admin = $this->createAdminUser();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->get("/admin/users/{$target->id}/edit");

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('user')
            ->where('user.has_password', false)
        );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --compact --filter=UserPasswordManagementTest`
Expected: FAIL

- [ ] **Step 4: Update UserController**

In `UserController@update`, add password handling:

```php
// Add to validation rules (conditionally):
$rules = [
    // ... existing rules
    'password' => 'nullable|string|min:8|confirmed',
    'clear_password' => 'nullable|boolean',
];

// After existing update logic:
if ($request->filled('password')) {
    $user->password = Hash::make($request->validated('password'));
    $user->save();
}

if ($request->boolean('clear_password')) {
    $user->password = null;
    $user->save();
}
```

In `UserController@edit`, add `has_password` to the user data sent to the view:

```php
'has_password' => $user->password !== null,
```

- [ ] **Step 5: Update user edit Vue page**

Add a "Password" section to the user edit form. This section should have:
- A password input field
- A password confirmation field
- A "Clear Password" button (if user has a password)

Use the existing form patterns from the page. Check what form fields already exist and follow the same pattern.

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter=UserPasswordManagementTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Admin/UserController.php resources/js/Pages/Admin/Users/ tests/Feature/Admin/UserPasswordManagementTest.php
git commit -m "feat: admin password management for users

Admins can set and clear user passwords via the user edit page.
Password requires confirmation and minimum 8 characters.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 7: Passkey Support (WebAuthn)

**Files:**
- New package: `laragear/webauthn` (via composer)
- Modify: `app/Models/User.php` — Add WebAuthnAuthenticatable trait
- Create: `app/Http/Controllers/PasskeyController.php`
- Modify: `resources/js/Pages/Auth/Login.vue` — Implement passkey authentication
- Modify: `routes/web.php` — Add passkey routes
- Create: `tests/Feature/PasskeyAuthenticationTest.php`

### Context

We use `laragear/webauthn` for passkey support. This adds a `webauthn_credentials` table and provides traits for the User model. The passkey flow has two parts: registration (admin panel) and authentication (login page).

- [ ] **Step 1: Install laragear/webauthn**

```bash
composer require laragear/webauthn --no-interaction
php artisan vendor:publish --provider="Laragear\WebAuthn\WebAuthnServiceProvider" --no-interaction
php artisan migrate --no-interaction
```

This creates the `webauthn_credentials` table and publishes `config/webauthn.php`.

- [ ] **Step 2: Add WebAuthnAuthenticatable trait to User model**

In `app/Models/User.php`, add:

```php
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable as WebAuthnAuthenticatableContract;
use Laragear\WebAuthn\WebAuthnAuthentication;
```

Update the class declaration:

```php
class User extends Authenticatable implements WebAuthnAuthenticatableContract
```

Add the trait inside the class:

```php
use WebAuthnAuthentication;
```

- [ ] **Step 3: Write failing tests**

Create `tests/Feature/PasskeyAuthenticationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->withPassword()->create();
        $role = Role::factory()->create(['code' => 'admin']);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_passkey_registration_options_endpoint_exists(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->postJson('/passkeys/register/options');

        $response->assertOk();
        $response->assertJsonStructure(['challenge']);
    }

    public function test_passkey_registration_requires_authentication(): void
    {
        $response = $this->postJson('/passkeys/register/options');

        $response->assertUnauthorized();
    }

    public function test_passkey_authentication_options_endpoint_exists(): void
    {
        $response = $this->postJson('/passkeys/login/options', [
            'email' => 'admin@test.com',
        ]);

        $response->assertOk();
    }

    public function test_login_page_renders_with_passkey_support(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
        );
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test --compact --filter=PasskeyAuthenticationTest`
Expected: FAIL — Routes don't exist.

- [ ] **Step 5: Create PasskeyController**

Create `app/Http/Controllers/PasskeyController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

class PasskeyController extends Controller
{
    public function registerOptions(AttestationRequest $request): JsonResponse
    {
        return response()->json(
            $request->toCreate()
        );
    }

    public function register(AttestedRequest $request): JsonResponse
    {
        $request->save();

        return response()->json(['success' => true]);
    }

    public function loginOptions(AssertionRequest $request): JsonResponse
    {
        return response()->json(
            $request->toVerify()
        );
    }

    public function login(AssertedRequest $request): JsonResponse
    {
        $user = $request->login();

        if ($user) {
            $request->session()->regenerate();

            return response()->json(['success' => true, 'redirect' => '/']);
        }

        return response()->json(['success' => false, 'message' => 'Authentication failed.'], 422);
    }
}
```

- [ ] **Step 6: Add routes**

In `routes/web.php`, add passkey routes:

```php
// Passkey registration (requires auth)
Route::middleware(['auth'])->prefix('passkeys')->group(function () {
    Route::post('/register/options', [PasskeyController::class, 'registerOptions'])->name('passkeys.register.options');
    Route::post('/register', [PasskeyController::class, 'register'])->name('passkeys.register');
});

// Passkey authentication (guest)
Route::middleware(['guest'])->prefix('passkeys')->group(function () {
    Route::post('/login/options', [PasskeyController::class, 'loginOptions'])->name('passkeys.login.options');
    Route::post('/login', [PasskeyController::class, 'login'])->name('passkeys.login');
});
```

Add the import:
```php
use App\Http\Controllers\PasskeyController;
```

- [ ] **Step 7: Implement passkey authentication in Login.vue**

Update the `loginWithPasskey()` function in `resources/js/Pages/Auth/Login.vue`:

```javascript
async function loginWithPasskey() {
    try {
        // Get authentication options from server
        const optionsResponse = await fetch('/passkeys/login/options', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify({ email: form.email }),
        });

        if (!optionsResponse.ok) {
            passkeyError.value = 'Failed to start passkey authentication.';
            return;
        }

        const options = await optionsResponse.json();

        // Convert base64 strings to ArrayBuffers for WebAuthn API
        options.challenge = base64ToBuffer(options.challenge);
        if (options.allowCredentials) {
            options.allowCredentials = options.allowCredentials.map((cred) => ({
                ...cred,
                id: base64ToBuffer(cred.id),
            }));
        }

        // Call WebAuthn browser API
        const credential = await navigator.credentials.get({ publicKey: options });

        // Send credential to server
        const loginResponse = await fetch('/passkeys/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
            },
            body: JSON.stringify({
                id: credential.id,
                rawId: bufferToBase64(credential.rawId),
                type: credential.type,
                response: {
                    authenticatorData: bufferToBase64(credential.response.authenticatorData),
                    clientDataJSON: bufferToBase64(credential.response.clientDataJSON),
                    signature: bufferToBase64(credential.response.signature),
                    userHandle: credential.response.userHandle
                        ? bufferToBase64(credential.response.userHandle)
                        : null,
                },
            }),
        });

        const result = await loginResponse.json();
        if (result.success) {
            window.location.href = result.redirect || '/';
        } else {
            passkeyError.value = result.message || 'Passkey authentication failed.';
        }
    } catch (e) {
        passkeyError.value = e.name === 'NotAllowedError'
            ? 'Passkey request was cancelled.'
            : 'Passkey authentication failed.';
    }
}

function base64ToBuffer(base64) {
    const binary = atob(base64.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

function bufferToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
```

Add `passkeyError` ref:
```javascript
const passkeyError = ref(null);
```

Add error display below the passkey button:
```html
<p v-if="passkeyError" data-testid="passkey-error" class="mt-2 text-center text-xs text-[var(--color-danger)]">
    {{ passkeyError }}
</p>
```

- [ ] **Step 8: Run all tests**

Run: `php artisan test --compact --filter=PasskeyAuthenticationTest && npx vitest run tests/js/Pages/Auth/Login.spec.js`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/User.php app/Http/Controllers/PasskeyController.php resources/js/Pages/Auth/Login.vue routes/web.php config/webauthn.php database/migrations/*webauthn* tests/Feature/PasskeyAuthenticationTest.php composer.json composer.lock
git commit -m "feat: add passkey (WebAuthn) authentication support

Install laragear/webauthn, add WebAuthnAuthenticatable trait to User
model. PasskeyController handles registration and authentication flows.
Login.vue implements browser WebAuthn API for passkey sign-in.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 8: Quality Pass

**Files:** All modified files from Tasks 1-7

- [ ] **Step 1: Run Laravel Pint**

```bash
vendor/bin/pint --dirty --format agent
```

Fix any formatting issues.

- [ ] **Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

Fix any type errors.

- [ ] **Step 3: Run Rector**

```bash
vendor/bin/rector process --dry-run
```

Apply any suggested fixes.

- [ ] **Step 4: Run ESLint**

```bash
npm run lint:fix
```

- [ ] **Step 5: Run Prettier**

```bash
npm run format
```

- [ ] **Step 6: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: All tests passing.

- [ ] **Step 7: Run full JS test suite**

```bash
npx vitest run
```

Expected: All tests passing.

- [ ] **Step 8: Commit any quality fixes**

```bash
git add -A
git commit -m "style: quality pass — Pint, PHPStan, Rector, ESLint, Prettier

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Notes

### OAuth2 Email Matching
The existing `DeviceFlowUserService::findOrCreateFromDeviceFlow()` already matches by email if no `external_id` match is found. This means if an admin sets a password for a user with email `admin@test.com`, and that same email comes through Borealis OAuth2, the accounts will be linked automatically. No changes needed.

### Future Phase: IP-Based Auto-Login
The user mentioned wanting IP-based auto-login as a later goal. This would use the existing `UserIpAddress` relationship to automatically authenticate users based on their IP address. Defer to a separate plan.

### Config Migration
When moving Borealis from env to DB, existing deployments will need to manually enter their Borealis credentials through the admin UI after upgrading. Since this is not production, this is acceptable.
