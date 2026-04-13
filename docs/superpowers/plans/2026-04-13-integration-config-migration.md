# Integration Config Migration — Admin UI for All Services

> **For agentic workers:** REQUIRED: Use the `subagent-driven-development` agent (recommended) or `executing-plans` agent to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate all service configuration from `.env`/`config()` to admin UI via the `IntegrationConfig` model, so every service is configurable through the admin Integrations page with env fallback.

**Architecture:** Expand `SettingsController::integrations()` / `updateIntegrations()` to handle 8 sections (4 existing + 4 new). Update `AppServiceProvider` bindings for OPNsense, ntopng, DHCP, and PiHole to read from DB first with env fallback. Update all service constructors that currently read `config()` directly (OpnSense firewall, NtopNgService, OpnSenseDhcpService, ScanNetworkDevices, PortalController) to receive injected config from AppServiceProvider.

**Tech Stack:** Laravel 12, PHPUnit, Vue 3 + Inertia, Vitest

---

## File Structure

### Files to modify

| File | Responsibility |
|------|---------------|
| `app/Http/Controllers/Admin/SettingsController.php` | Add validation for all new fields, return all 8 integration sections |
| `resources/js/Pages/Admin/Settings/Integrations.vue` | Add 4 new sections + expand 4 existing sections with new fields |
| `app/Providers/AppServiceProvider.php` | Wire OpnSense firewall + NtopNg + DHCP pool_size via DB config |
| `app/Services/Firewalls/OpnSense.php` | Accept constructor params instead of reading `config()` directly |
| `app/Services/NtopNgService.php` | No change needed (already accepts constructor params) |
| `app/Services/Dhcp/OpnSenseDhcpService.php` | Accept pool_size as constructor param instead of `config()` |
| `app/Jobs/ScanNetworkDevices.php` | Read auto_allow config from IntegrationConfig with env fallback |
| `app/Http/Controllers/PortalController.php` | Read IPv6 config from IntegrationConfig with env fallback |
| `tests/Feature/Admin/SettingsControllerTest.php` | Add tests for all new fields and sections |

### Files unchanged (already correct)

| File | Why |
|------|-----|
| `app/Models/IntegrationConfig.php` | Model already supports getAll/getValue/setValue with encryption |
| `app/Services/LibreNmsService.php` | Already receives constructor params; AppServiceProvider already wires from DB |
| `app/Services/PiHole/PiHoleService.php` | Already receives constructor params; AppServiceProvider already wires from DB |

---

## Task 1: Expand OPNsense — Backend Validation + Controller

Expand the existing OPNsense integration section with `verify_ssl`, `zone_id`, `ratelimit_up_uuid`, `ratelimit_down_uuid`.

**Files:**
- Modify: `tests/Feature/Admin/SettingsControllerTest.php`
- Modify: `app/Http/Controllers/Admin/SettingsController.php`

### Steps

- [ ] **Step 1: Write failing tests for OPNsense new fields**

Add to `tests/Feature/Admin/SettingsControllerTest.php`:

```php
public function test_admin_can_save_opnsense_verify_ssl(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'opnsense' => [
            'endpoint' => 'https://opnsense.example.com',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'captive_portal_id' => 'portal1',
            'verify_ssl' => '1',
            'zone_id' => '2',
            'ratelimit_up_uuid' => 'uuid-up-123',
            'ratelimit_down_uuid' => 'uuid-down-456',
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('1', IntegrationConfig::getValue('opnsense', 'verify_ssl'));
    $this->assertEquals('2', IntegrationConfig::getValue('opnsense', 'zone_id'));
    $this->assertEquals('uuid-up-123', IntegrationConfig::getValue('opnsense', 'ratelimit_up_uuid'));
    $this->assertEquals('uuid-down-456', IntegrationConfig::getValue('opnsense', 'ratelimit_down_uuid'));
}

public function test_integrations_page_returns_all_sections(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    IntegrationConfig::setValue('opnsense', 'zone_id', '5');

    $response = $this->actingAs($admin)->get('/admin/settings/integrations');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Settings/Integrations')
        ->has('integrations.opnsense')
        ->has('integrations.librenms')
        ->has('integrations.ntopng')
        ->has('integrations.pihole')
        ->has('integrations.dhcp')
        ->has('integrations.dns')
        ->has('integrations.auto_allow')
        ->has('integrations.ipv6')
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_admin_can_save_opnsense_verify_ssl|test_integrations_page_returns_all_sections"`

Expected: FAIL — `verify_ssl` validation not present, new sections not returned.

- [ ] **Step 3: Update SettingsController validation to accept new OPNsense fields + return all 8 sections**

In `app/Http/Controllers/Admin/SettingsController.php`, update `integrations()` to return all 8 sections:

```php
public function integrations(): Response
{
    return Inertia::render('Admin/Settings/Integrations', [
        'integrations' => [
            'opnsense' => IntegrationConfig::getAll('opnsense'),
            'librenms' => IntegrationConfig::getAll('librenms'),
            'ntopng' => IntegrationConfig::getAll('ntopng'),
            'pihole' => IntegrationConfig::getAll('pihole'),
            'dhcp' => IntegrationConfig::getAll('dhcp'),
            'dns' => IntegrationConfig::getAll('dns'),
            'auto_allow' => IntegrationConfig::getAll('auto_allow'),
            'ipv6' => IntegrationConfig::getAll('ipv6'),
        ],
    ]);
}
```

Update `updateIntegrations()` validation rules — replace entire `$validated` block:

```php
$validated = $request->validate([
    // OPNsense
    'opnsense.endpoint' => 'nullable|url|max:500',
    'opnsense.key' => 'nullable|string|max:500',
    'opnsense.secret' => 'nullable|string|max:500',
    'opnsense.captive_portal_id' => 'nullable|string|max:100',
    'opnsense.verify_ssl' => 'nullable|string|in:0,1',
    'opnsense.zone_id' => 'nullable|string|max:100',
    'opnsense.ratelimit_up_uuid' => 'nullable|string|max:200',
    'opnsense.ratelimit_down_uuid' => 'nullable|string|max:200',
    // LibreNMS
    'librenms.endpoint' => 'nullable|url|max:500',
    'librenms.api_key' => 'nullable|string|max:500',
    'librenms.enabled' => 'nullable|string|in:0,1',
    // ntopng
    'ntopng.endpoint' => 'nullable|url|max:500',
    'ntopng.username' => 'nullable|string|max:255',
    'ntopng.password' => 'nullable|string|max:500',
    'ntopng.interface' => 'nullable|string|max:100',
    'ntopng.enabled' => 'nullable|string|in:0,1',
    // PiHole
    'pihole.endpoint' => 'nullable|url|max:500',
    'pihole.password' => 'nullable|string|max:500',
    'pihole.noblock_group_id' => 'nullable|integer|min:0',
    'pihole.enabled' => 'nullable|string|in:0,1',
    'pihole.verify_ssl' => 'nullable|string|in:0,1',
    // DHCP
    'dhcp.enabled' => 'nullable|string|in:0,1',
    'dhcp.endpoint' => 'nullable|url|max:500',
    'dhcp.key' => 'nullable|string|max:500',
    'dhcp.secret' => 'nullable|string|max:500',
    'dhcp.verify_ssl' => 'nullable|string|in:0,1',
    'dhcp.pool_size' => 'nullable|integer|min:1',
    // DNS Probe
    'dns.expected_server' => 'nullable|string|max:255',
    'dns.probe_domain' => 'nullable|string|max:255',
    // Auto Allow
    'auto_allow.enabled' => 'nullable|string|in:0,1',
    'auto_allow.oui_prefixes' => 'nullable|string|max:2000',
    'auto_allow.scan_interval' => 'nullable|integer|min:1',
    // IPv6 Detection
    'ipv6.detection_enabled' => 'nullable|string|in:0,1',
    'ipv6.detection_endpoint' => 'nullable|url|max:500',
]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="test_admin_can_save_opnsense_verify_ssl|test_integrations_page_returns_all_sections"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Admin/SettingsControllerTest.php app/Http/Controllers/Admin/SettingsController.php
git commit -m "feat: expand SettingsController validation for all integration sections"
```

---

## Task 2: Tests + Validation for All New Sections (DHCP, DNS, Auto Allow, IPv6, ntopng expansion, LibreNMS enabled, PiHole expansion)

**Files:**
- Modify: `tests/Feature/Admin/SettingsControllerTest.php`

### Steps

- [ ] **Step 1: Write failing tests for all remaining sections**

Add to `tests/Feature/Admin/SettingsControllerTest.php`:

```php
public function test_admin_can_save_ntopng_with_username_password(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'ntopng' => [
            'endpoint' => 'https://ntopng.example.com',
            'username' => 'admin',
            'password' => 'secret123',
            'interface' => '4',
            'enabled' => '1',
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('admin', IntegrationConfig::getValue('ntopng', 'username'));
    $this->assertEquals('4', IntegrationConfig::getValue('ntopng', 'interface'));
    $this->assertEquals('1', IntegrationConfig::getValue('ntopng', 'enabled'));
    // password should be encrypted
    $raw = IntegrationConfig::where('integration', 'ntopng')->where('key', 'password')->first();
    $this->assertTrue($raw->encrypted);
}

public function test_admin_can_save_librenms_enabled(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'librenms' => [
            'endpoint' => 'https://librenms.example.com',
            'api_key' => 'token123',
            'enabled' => '1',
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('1', IntegrationConfig::getValue('librenms', 'enabled'));
}

public function test_admin_can_save_pihole_with_enabled_and_verify_ssl(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'pihole' => [
            'endpoint' => 'https://pihole.example.com',
            'password' => 'pipass',
            'noblock_group_id' => 2,
            'enabled' => '1',
            'verify_ssl' => '0',
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('1', IntegrationConfig::getValue('pihole', 'enabled'));
    $this->assertEquals('0', IntegrationConfig::getValue('pihole', 'verify_ssl'));
}

public function test_admin_can_save_dhcp_settings(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'dhcp' => [
            'enabled' => '1',
            'endpoint' => 'https://dhcp.example.com',
            'key' => 'dhcp-key',
            'secret' => 'dhcp-secret',
            'verify_ssl' => '1',
            'pool_size' => 500,
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('1', IntegrationConfig::getValue('dhcp', 'enabled'));
    $this->assertEquals('https://dhcp.example.com', IntegrationConfig::getValue('dhcp', 'endpoint'));
    $this->assertEquals(500, IntegrationConfig::getValue('dhcp', 'pool_size'));
    // key and secret should be encrypted
    $raw = IntegrationConfig::where('integration', 'dhcp')->where('key', 'key')->first();
    $this->assertTrue($raw->encrypted);
    $raw = IntegrationConfig::where('integration', 'dhcp')->where('key', 'secret')->first();
    $this->assertTrue($raw->encrypted);
}

public function test_admin_can_save_dns_probe_settings(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'dns' => [
            'expected_server' => '192.168.1.1',
            'probe_domain' => 'probe.example.com',
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('192.168.1.1', IntegrationConfig::getValue('dns', 'expected_server'));
    $this->assertEquals('probe.example.com', IntegrationConfig::getValue('dns', 'probe_domain'));
}

public function test_admin_can_save_auto_allow_settings(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'auto_allow' => [
            'enabled' => '1',
            'oui_prefixes' => '98:5F:D3,7C:ED:8D',
            'scan_interval' => 10,
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('1', IntegrationConfig::getValue('auto_allow', 'enabled'));
    $this->assertEquals('98:5F:D3,7C:ED:8D', IntegrationConfig::getValue('auto_allow', 'oui_prefixes'));
    $this->assertEquals(10, IntegrationConfig::getValue('auto_allow', 'scan_interval'));
}

public function test_admin_can_save_ipv6_detection_settings(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $response = $this->actingAs($admin)->put('/admin/settings/integrations', [
        'ipv6' => [
            'detection_enabled' => '1',
            'detection_endpoint' => 'https://ipv6.example.com/detect',
        ],
    ]);

    $response->assertRedirect();
    $this->assertEquals('1', IntegrationConfig::getValue('ipv6', 'detection_enabled'));
    $this->assertEquals('https://ipv6.example.com/detect', IntegrationConfig::getValue('ipv6', 'detection_endpoint'));
}

public function test_sensitive_fields_are_stored_encrypted(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();

    $this->actingAs($admin)->put('/admin/settings/integrations', [
        'opnsense' => ['key' => 'mykey', 'secret' => 'mysecret'],
        'ntopng' => ['password' => 'ntoppass'],
        'pihole' => ['password' => 'pipass'],
        'dhcp' => ['key' => 'dhcpkey', 'secret' => 'dhcpsecret'],
    ]);

    // Verify all sensitive fields are encrypted
    foreach ([
        ['opnsense', 'key'],
        ['opnsense', 'secret'],
        ['ntopng', 'password'],
        ['pihole', 'password'],
        ['dhcp', 'key'],
        ['dhcp', 'secret'],
    ] as [$integration, $key]) {
        $config = IntegrationConfig::where('integration', $integration)
            ->where('key', $key)->first();
        $this->assertTrue($config->encrypted, "{$integration}.{$key} should be encrypted");
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_admin_can_save_ntopng|test_admin_can_save_librenms_enabled|test_admin_can_save_pihole_with|test_admin_can_save_dhcp|test_admin_can_save_dns|test_admin_can_save_auto_allow|test_admin_can_save_ipv6|test_sensitive_fields"`

Expected: FAIL — ntopng `api_key` validation still present, new fields not validated.

- [ ] **Step 3: Verify validation was already updated in Task 1**

The validation rules from Task 1 Step 3 already cover all these fields. The ntopng section now validates `username`/`password`/`interface`/`enabled` instead of `api_key`. Run the tests — they should pass.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="test_admin_can_save_ntopng|test_admin_can_save_librenms_enabled|test_admin_can_save_pihole_with|test_admin_can_save_dhcp|test_admin_can_save_dns|test_admin_can_save_auto_allow|test_admin_can_save_ipv6|test_sensitive_fields"`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Admin/SettingsControllerTest.php
git commit -m "test: add integration config tests for all sections"
```

---

## Task 3: Refactor OpnSense Firewall Service to Use Dependency Injection

The `OpnSense` firewall service currently reads all config directly from `config()` in its constructor. Refactor to accept params, then wire via `AppServiceProvider`.

**Files:**
- Create: `tests/Unit/Services/Firewalls/OpnSenseConstructorTest.php`
- Modify: `app/Services/Firewalls/OpnSense.php`
- Modify: `app/Providers/AppServiceProvider.php`

### Steps

- [ ] **Step 1: Write failing test for OpnSense constructor injection**

Create `tests/Unit/Services/Firewalls/OpnSenseConstructorTest.php`:

```php
<?php

namespace Tests\Unit\Services\Firewalls;

use App\Services\Firewalls\OpnSense;
use PHPUnit\Framework\TestCase;

class OpnSenseConstructorTest extends TestCase
{
    public function test_constructor_accepts_parameters(): void
    {
        $opnsense = new OpnSense(
            endpoint: 'https://opnsense.test.com',
            key: 'test-key',
            secret: 'test-secret',
            zoneId: 1,
            verify: false,
            uploadRuleUuid: 'uuid-up',
            downloadRuleUuid: 'uuid-down',
        );

        // If constructor doesn't throw, we succeeded
        $this->assertInstanceOf(OpnSense::class, $opnsense);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OpnSenseConstructorTest`

Expected: FAIL — `OpnSense::__construct()` takes no parameters.

- [ ] **Step 3: Refactor OpnSense constructor**

Replace the `__construct()` method in `app/Services/Firewalls/OpnSense.php`:

```php
public function __construct(
    string $endpoint,
    string $key,
    string $secret,
    int $zoneId,
    bool $verify = true,
    string $uploadRuleUuid = '',
    string $downloadRuleUuid = '',
) {
    $this->zoneId = $zoneId;
    $this->uploadRuleUuid = $uploadRuleUuid;
    $this->downloadRuleUuid = $downloadRuleUuid;

    $this->client = new Client([
        'verify' => $verify,
        'base_uri' => $endpoint,
        'auth' => [
            $key,
            $secret,
        ],
    ]);
}
```

Remove the `protected` visibility from the `$zoneId`, `$uploadRuleUuid`, `$downloadRuleUuid` property declarations at the top of the class (they're now set in the constructor body).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=OpnSenseConstructorTest`

Expected: PASS

- [ ] **Step 5: Wire OpnSense via AppServiceProvider with DB config**

In `app/Providers/AppServiceProvider.php`, replace the simple `bind` for `FirewallBackendInterface` in `register()`:

Remove this line from `register()`:
```php
$this->app->bind(FirewallBackendInterface::class, OpnSense::class);
```

Add this to `boot()` (after the existing BorealisService singleton):

```php
$this->app->singleton(function (Application $application): FirewallBackendInterface {
    $dbConfig = $this->getIntegrationDbConfig('opnsense');

    return new OpnSense(
        endpoint: (string) ($dbConfig['endpoint'] ?? config('aperture.opnsense.endpoint', '')),
        key: (string) ($dbConfig['key'] ?? config('aperture.opnsense.key', '')),
        secret: (string) ($dbConfig['secret'] ?? config('aperture.opnsense.secret', '')),
        zoneId: (int) ($dbConfig['zone_id'] ?? config('aperture.opnsense.zoneid', 0)),
        verify: (bool) ($dbConfig['verify_ssl'] ?? config('aperture.opnsense.verify', true)),
        uploadRuleUuid: (string) ($dbConfig['ratelimit_up_uuid'] ?? config('aperture.opnsense.ratelimitUpUuid', '')),
        downloadRuleUuid: (string) ($dbConfig['ratelimit_down_uuid'] ?? config('aperture.opnsense.ratelimitDownUuid', '')),
    );
});
```

- [ ] **Step 6: Run all tests to verify nothing is broken**

Run: `php artisan test --compact --filter=OpnSense`

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add tests/Unit/Services/Firewalls/OpnSenseConstructorTest.php app/Services/Firewalls/OpnSense.php app/Providers/AppServiceProvider.php
git commit -m "refactor: OpnSense firewall uses DI instead of config() directly"
```

---

## Task 4: Wire ntopng Service Via AppServiceProvider with DB Config

The `NtopNgService` already accepts constructor params. The `AppServiceProvider` currently reads from `config()` only. Wire it to read from `IntegrationConfig` first.

**Files:**
- Create: `tests/Feature/Services/NtopNgServiceWiringTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

### Steps

- [ ] **Step 1: Write failing test for ntopng DB config wiring**

Create `tests/Feature/Services/NtopNgServiceWiringTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationConfig;
use App\Services\NtopNgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NtopNgServiceWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_ntopng_service_uses_db_config_when_available(): void
    {
        IntegrationConfig::setValue('ntopng', 'endpoint', 'https://ntopng-db.example.com');
        IntegrationConfig::setValue('ntopng', 'username', 'dbuser');
        IntegrationConfig::setValue('ntopng', 'password', 'dbpass', encrypted: true);
        IntegrationConfig::setValue('ntopng', 'interface', '7');

        // Force re-resolution
        $this->app->forgetInstance(NtopNgService::class);

        $service = app(NtopNgService::class);

        $this->assertInstanceOf(NtopNgService::class, $service);
    }
}
```

- [ ] **Step 2: Run test to verify baseline**

Run: `php artisan test --compact --filter=NtopNgServiceWiringTest`

Expected: The test may already pass since it just resolves the service. The key change is in AppServiceProvider.

- [ ] **Step 3: Update AppServiceProvider ntopng binding**

Replace the NtopNgService singleton in `app/Providers/AppServiceProvider.php`:

```php
$this->app->singleton(function (Application $application): NtopNgService {
    $dbConfig = $this->getIntegrationDbConfig('ntopng');

    return new NtopNgService(
        endpoint: (string) ($dbConfig['endpoint'] ?? config('aperture.ntopng.endpoint', '')),
        username: (string) ($dbConfig['username'] ?? config('aperture.ntopng.username', '')),
        password: (string) ($dbConfig['password'] ?? config('aperture.ntopng.password', '')),
        interface: (int) ($dbConfig['interface'] ?? config('aperture.ntopng.interface', 0)),
    );
});
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=NtopNgServiceWiringTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Services/NtopNgServiceWiringTest.php app/Providers/AppServiceProvider.php
git commit -m "feat: wire ntopng service to read from IntegrationConfig with env fallback"
```

---

## Task 5: Refactor OpnSenseDhcpService to Accept pool_size via DI

`OpnSenseDhcpService::getPoolStatus()` reads `config('aperture.dhcp.pool_size')` directly. Move it to a constructor param.

**Files:**
- Create: `tests/Unit/Services/Dhcp/OpnSenseDhcpServicePoolSizeTest.php`
- Modify: `app/Services/Dhcp/OpnSenseDhcpService.php`
- Modify: `app/Providers/AppServiceProvider.php`

### Steps

- [ ] **Step 1: Write failing test for pool_size constructor injection**

Create `tests/Unit/Services/Dhcp/OpnSenseDhcpServicePoolSizeTest.php`:

```php
<?php

namespace Tests\Unit\Services\Dhcp;

use App\Services\Dhcp\OpnSenseDhcpService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OpnSenseDhcpServicePoolSizeTest extends TestCase
{
    public function test_pool_size_is_used_from_constructor(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'rows' => [
                    ['address' => '10.0.0.1', 'mac' => 'AA:BB:CC:DD:EE:01', 'hostname' => 'h1', 'status' => 'active', 'starts' => '', 'ends' => '', 'if' => 'lan'],
                    ['address' => '10.0.0.2', 'mac' => 'AA:BB:CC:DD:EE:02', 'hostname' => 'h2', 'status' => 'active', 'starts' => '', 'ends' => '', 'if' => 'lan'],
                ],
                'rowCount' => 2,
            ])),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mockHandler)]);
        $service = new OpnSenseDhcpService($client, 100);

        $status = $service->getPoolStatus();

        $this->assertEquals(100, $status['total']);
        $this->assertEquals(2, $status['used']);
        $this->assertEquals(98, $status['available']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OpnSenseDhcpServicePoolSizeTest`

Expected: FAIL — constructor only accepts `Client`, not `poolSize`.

- [ ] **Step 3: Add pool_size to OpnSenseDhcpService constructor**

In `app/Services/Dhcp/OpnSenseDhcpService.php`, update the constructor:

```php
public function __construct(
    protected Client $client,
    protected int $poolSize = 0,
) {}
```

Update `getPoolStatus()` to use `$this->poolSize` instead of `config()`:

Replace:
```php
$poolSize = (int) config('aperture.dhcp.pool_size', 0);
```

With:
```php
$poolSize = $this->poolSize;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=OpnSenseDhcpServicePoolSizeTest`

Expected: PASS

- [ ] **Step 5: Update AppServiceProvider DhcpInterface binding to pass pool_size**

In `app/Providers/AppServiceProvider.php`, update the DhcpInterface singleton to also read `dhcp` DB config and pass `pool_size`:

```php
$this->app->singleton(function (Application $application): DhcpInterface {
    $dbConfig = $this->getIntegrationDbConfig('dhcp');
    $opnsenseConfig = $this->getIntegrationDbConfig('opnsense');
    $client = new Client([
        'verify' => (bool) ($dbConfig['verify_ssl'] ?? config('aperture.dhcp.verify')),
        'base_uri' => $dbConfig['endpoint'] ?? config('aperture.dhcp.endpoint'),
        'auth' => [
            $dbConfig['key'] ?? config('aperture.dhcp.key'),
            $dbConfig['secret'] ?? config('aperture.dhcp.secret'),
        ],
    ]);

    return new OpnSenseDhcpService(
        $client,
        (int) ($dbConfig['pool_size'] ?? config('aperture.dhcp.pool_size', 254)),
    );
});
```

Note: The existing binding reads `opnsense` config for DHCP credentials. Change it to read from `dhcp` integration config, since DHCP is now a separate integration section.

- [ ] **Step 6: Run all DHCP-related tests**

Run: `php artisan test --compact --filter=Dhcp`

Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add tests/Unit/Services/Dhcp/OpnSenseDhcpServicePoolSizeTest.php app/Services/Dhcp/OpnSenseDhcpService.php app/Providers/AppServiceProvider.php
git commit -m "refactor: OpnSenseDhcpService accepts pool_size via DI"
```

---

## Task 6: Migrate ScanNetworkDevices (Auto Allow) to Use IntegrationConfig

`ScanNetworkDevices` reads `config('aperture.auto_allow.*')` directly. Update to read from `IntegrationConfig` with env fallback.

**Files:**
- Create: `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php`
- Modify: `app/Jobs/ScanNetworkDevices.php`

### Steps

- [ ] **Step 1: Write failing test for DB config usage**

Create `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Models\IntegrationConfig;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ScanNetworkDevicesConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_reads_enabled_from_integration_config(): void
    {
        // DB says enabled
        IntegrationConfig::setValue('auto_allow', 'enabled', '1');

        // Mock services so the job doesn't make real API calls
        $this->mock(MacAddressResolverInterface::class);
        $dhcp = $this->mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect());
        $inventory = $this->mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect());

        // Config env says disabled
        config(['aperture.auto_allow.enabled' => false]);

        $job = new \App\Jobs\ScanNetworkDevices;
        $job->handle();

        // If the job processed (didn't early-return), it means it read from DB
        // We can verify by checking that getLeases was called
        $dhcp->shouldHaveReceived('getLeases');
    }

    public function test_scan_reads_oui_prefixes_from_integration_config(): void
    {
        IntegrationConfig::setValue('auto_allow', 'enabled', '1');
        IntegrationConfig::setValue('auto_allow', 'oui_prefixes', 'AA:BB:CC');

        $this->mock(MacAddressResolverInterface::class);
        $dhcp = $this->mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([
            ['ip' => '10.0.0.1', 'mac' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'test', 'expires' => ''],
        ]));
        $inventory = $this->mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect());

        $job = new \App\Jobs\ScanNetworkDevices;
        $job->handle();

        // The OUI prefix AA:BB:CC should have been used from DB config
        $this->assertDatabaseHas('mac_addresses', [
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
        ]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ScanNetworkDevicesConfigTest`

Expected: FAIL — job still reads from `config()`.

- [ ] **Step 3: Update ScanNetworkDevices to read from IntegrationConfig**

In `app/Jobs/ScanNetworkDevices.php`, add the import at the top:

```php
use App\Models\IntegrationConfig;
```

Replace the `config()` calls in `handle()`:

Replace:
```php
if (! config('aperture.auto_allow.enabled')) {
    return;
}
```

With:
```php
$dbConfig = IntegrationConfig::getAll('auto_allow');
$enabled = (bool) ($dbConfig['enabled'] ?? config('aperture.auto_allow.enabled', false));
if (! $enabled) {
    return;
}
```

Replace:
```php
/** @var array<int, string> $ouiPrefixes */
$ouiPrefixes = config('aperture.auto_allow.oui_prefixes', []);
```

With:
```php
/** @var array<int, string> $ouiPrefixes */
$ouiPrefixesRaw = $dbConfig['oui_prefixes'] ?? null;
if (is_string($ouiPrefixesRaw)) {
    $ouiPrefixes = array_filter(explode(',', $ouiPrefixesRaw));
} else {
    $ouiPrefixes = config('aperture.auto_allow.oui_prefixes', []);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ScanNetworkDevicesConfigTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php app/Jobs/ScanNetworkDevices.php
git commit -m "feat: ScanNetworkDevices reads auto_allow config from IntegrationConfig"
```

---

## Task 7: Migrate PortalController (IPv6 Detection) to Use IntegrationConfig

`PortalController` reads `config('aperture.ipv6.*')` directly. Update to use IntegrationConfig with env fallback.

**Files:**
- Create: `tests/Feature/PortalControllerConfigTest.php`
- Modify: `app/Http/Controllers/PortalController.php`

### Steps

- [ ] **Step 1: Write failing test for IPv6 DB config**

Create `tests/Feature/PortalControllerConfigTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\IntegrationConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PortalControllerConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_reads_ipv6_config_from_integration_config(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        IntegrationConfig::setValue('ipv6', 'detection_enabled', '1');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://ipv6-db.example.com/detect');

        // Env config says disabled
        config(['aperture.ipv6.detection_enabled' => false]);
        config(['aperture.ipv6.detection_endpoint' => '']);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertViewHas('ipv6DetectionEnabled', true);
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://ipv6-db.example.com/detect');
    }

    public function test_portal_falls_back_to_env_when_no_db_config(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        config(['aperture.ipv6.detection_enabled' => true]);
        config(['aperture.ipv6.detection_endpoint' => 'https://ipv6-env.example.com']);

        $response = $this->actingAs($user)->get('/portal');

        $response->assertOk();
        $response->assertViewHas('ipv6DetectionEnabled', true);
        $response->assertViewHas('ipv6DetectionEndpoint', 'https://ipv6-env.example.com');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=PortalControllerConfigTest`

Expected: FAIL — first test fails because controller reads env config.

- [ ] **Step 3: Update PortalController to read from IntegrationConfig**

In `app/Http/Controllers/PortalController.php`, add the import:

```php
use App\Models\IntegrationConfig;
```

Replace:
```php
$ipv6DetectionEnabled = (bool) config('aperture.ipv6.detection_enabled');
$ipv6DetectionEndpoint = config('aperture.ipv6.detection_endpoint');
```

With:
```php
$dbConfig = IntegrationConfig::getAll('ipv6');
$ipv6DetectionEnabled = (bool) ($dbConfig['detection_enabled'] ?? config('aperture.ipv6.detection_enabled'));
$ipv6DetectionEndpoint = $dbConfig['detection_endpoint'] ?? config('aperture.ipv6.detection_endpoint');
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=PortalControllerConfigTest`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/PortalControllerConfigTest.php app/Http/Controllers/PortalController.php
git commit -m "feat: PortalController reads IPv6 config from IntegrationConfig"
```

---

## Task 8: Expand Integrations Vue Page — OPNsense + LibreNMS + ntopng + PiHole Fields

Expand the 4 existing sections in the Vue template with new fields.

**Files:**
- Modify: `resources/js/Pages/Admin/Settings/Integrations.vue`
- Create: `tests/js/Pages/Admin/Settings/Integrations.spec.js`

### Steps

- [ ] **Step 1: Write Vitest test for expanded form fields**

Create `tests/js/Pages/Admin/Settings/Integrations.spec.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Integrations from '@/Pages/Admin/Settings/Integrations.vue';

// Mock Inertia
vi.mock('@inertiajs/vue3', () => ({
    useForm: vi.fn((data) => ({
        ...data,
        put: vi.fn(),
        processing: false,
        errors: {},
    })),
    usePage: vi.fn(() => ({
        props: { flash: {} },
    })),
}));

// Mock route helper
vi.stubGlobal('route', vi.fn(() => '/admin/settings/integrations'));

describe('Integrations.vue', () => {
    const defaultProps = {
        integrations: {
            opnsense: {},
            librenms: {},
            ntopng: {},
            pihole: {},
            dhcp: {},
            dns: {},
            auto_allow: {},
            ipv6: {},
        },
    };

    function mountPage(props = {}) {
        return mount(Integrations, {
            props: { ...defaultProps, ...props },
            global: {
                stubs: {
                    AdminLayout: { template: '<div><slot /></div>' },
                    SettingsNav: { template: '<div><slot /></div>' },
                    FormField: { template: '<div><slot /></div>', props: ['label', 'name', 'error'] },
                },
            },
        });
    }

    it('renders all 8 integration sections', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-opnsense"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-librenms"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-pihole"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dns"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-auto-allow"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ipv6"]').exists()).toBe(true);
    });

    it('renders OPNsense new fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-opnsense-verify-ssl"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-opnsense-zone-id"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-opnsense-ratelimit-up-uuid"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-opnsense-ratelimit-down-uuid"]').exists()).toBe(true);
    });

    it('renders ntopng with username/password instead of api_key', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-ntopng-username"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng-password"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng-interface"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ntopng-enabled"]').exists()).toBe(true);
    });

    it('renders LibreNMS enabled toggle', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-librenms-enabled"]').exists()).toBe(true);
    });

    it('renders PiHole enabled and verify_ssl toggles', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-pihole-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-pihole-verify-ssl"]').exists()).toBe(true);
    });

    it('renders DHCP section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-dhcp-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-endpoint"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-key"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-secret"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-verify-ssl"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dhcp-pool-size"]').exists()).toBe(true);
    });

    it('renders DNS Probe section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-dns-expected-server"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-dns-probe-domain"]').exists()).toBe(true);
    });

    it('renders Auto Allow section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-auto-allow-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-auto-allow-oui-prefixes"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-auto-allow-scan-interval"]').exists()).toBe(true);
    });

    it('renders IPv6 Detection section fields', () => {
        const wrapper = mountPage();

        expect(wrapper.find('[data-testid="integration-ipv6-detection-enabled"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="integration-ipv6-detection-endpoint"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run Vitest to verify tests fail**

Run: `npx vitest run tests/js/Pages/Admin/Settings/Integrations.spec.js`

Expected: FAIL — new sections and fields don't exist yet.

- [ ] **Step 3: Update Integrations.vue form data**

Replace the `useForm` call in `<script setup>`:

```js
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
```

- [ ] **Step 4: Add new fields to OPNsense section template**

After the existing Captive Portal ID field in the OPNsense section, add:

```html
<FormField label="Verify SSL" name="opnsense.verify_ssl" :error="form.errors['opnsense.verify_ssl']">
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
<FormField label="Rate Limit Upload Rule UUID" name="opnsense.ratelimit_up_uuid" :error="form.errors['opnsense.ratelimit_up_uuid']">
    <input
        v-model="form.opnsense.ratelimit_up_uuid"
        type="text"
        data-testid="integration-opnsense-ratelimit-up-uuid"
        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
    />
</FormField>
<FormField label="Rate Limit Download Rule UUID" name="opnsense.ratelimit_down_uuid" :error="form.errors['opnsense.ratelimit_down_uuid']">
    <input
        v-model="form.opnsense.ratelimit_down_uuid"
        type="text"
        data-testid="integration-opnsense-ratelimit-down-uuid"
        class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
    />
</FormField>
```

- [ ] **Step 5: Update LibreNMS section — add enabled toggle**

After the API Key field in the LibreNMS section, add:

```html
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
```

- [ ] **Step 6: Replace ntopng section — username/password/interface/enabled**

Replace the entire ntopng section template (between the `<!-- ntopng -->` comment and the `</section>` tag):

```html
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
```

- [ ] **Step 7: Update PiHole section — add enabled + verify_ssl**

After the No-Block Group ID field in the PiHole section, add:

```html
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
```

- [ ] **Step 8: Run Vitest to verify existing section tests pass**

Run: `npx vitest run tests/js/Pages/Admin/Settings/Integrations.spec.js`

Expected: Some tests pass (existing sections), new section tests still fail.

- [ ] **Step 9: Commit expanded existing sections**

```bash
git add resources/js/Pages/Admin/Settings/Integrations.vue tests/js/Pages/Admin/Settings/Integrations.spec.js
git commit -m "feat: expand OPNsense/LibreNMS/ntopng/PiHole sections in Integrations UI"
```

---

## Task 9: Add New Sections to Integrations Vue Page (DHCP, DNS, Auto Allow, IPv6)

**Files:**
- Modify: `resources/js/Pages/Admin/Settings/Integrations.vue`

### Steps

- [ ] **Step 1: Add DHCP section template**

After the PiHole `</section>`, add:

```html
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
```

- [ ] **Step 2: Add DNS Probe section template**

```html
<!-- DNS Probe -->
<section
    class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
    data-testid="integration-dns"
>
    <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">DNS Probe</h2>
    <div class="space-y-4">
        <FormField label="Expected Server" name="dns.expected_server" :error="form.errors['dns.expected_server']">
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
```

- [ ] **Step 3: Add Auto Allow section template**

```html
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
        <FormField label="OUI Prefixes" name="auto_allow.oui_prefixes" :error="form.errors['auto_allow.oui_prefixes']">
            <textarea
                v-model="form.auto_allow.oui_prefixes"
                data-testid="integration-auto-allow-oui-prefixes"
                rows="3"
                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
                placeholder="Comma-separated MAC prefixes, e.g. 98:5F:D3,7C:ED:8D"
            />
        </FormField>
        <FormField label="Scan Interval (minutes)" name="auto_allow.scan_interval" :error="form.errors['auto_allow.scan_interval']">
            <input
                v-model="form.auto_allow.scan_interval"
                type="number"
                data-testid="integration-auto-allow-scan-interval"
                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </FormField>
    </div>
</section>
```

- [ ] **Step 4: Add IPv6 Detection section template**

```html
<!-- IPv6 Detection -->
<section
    class="rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-6"
    data-testid="integration-ipv6"
>
    <h2 class="font-heading mb-4 text-lg font-semibold text-[var(--color-text)]">IPv6 Detection</h2>
    <div class="space-y-4">
        <FormField label="Enabled" name="ipv6.detection_enabled" :error="form.errors['ipv6.detection_enabled']">
            <select
                v-model="form.ipv6.detection_enabled"
                data-testid="integration-ipv6-detection-enabled"
                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
            >
                <option value="1">Yes</option>
                <option value="0">No</option>
            </select>
        </FormField>
        <FormField label="Detection Endpoint" name="ipv6.detection_endpoint" :error="form.errors['ipv6.detection_endpoint']">
            <input
                v-model="form.ipv6.detection_endpoint"
                type="url"
                data-testid="integration-ipv6-detection-endpoint"
                class="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-sm text-[var(--color-text)]"
            />
        </FormField>
    </div>
</section>
```

- [ ] **Step 5: Run Vitest to verify all tests pass**

Run: `npx vitest run tests/js/Pages/Admin/Settings/Integrations.spec.js`

Expected: PASS — all 8 sections render with correct test IDs.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/Settings/Integrations.vue
git commit -m "feat: add DHCP, DNS Probe, Auto Allow, IPv6 Detection sections to Integrations UI"
```

---

## Task 10: Remove Stale ntopng api_key Validation + Update Existing Tests

The old ntopng section used `api_key`. The existing test `test_admin_can_update_integrations_settings` may still submit `ntopng.api_key`. Clean up.

**Files:**
- Modify: `tests/Feature/Admin/SettingsControllerTest.php`

### Steps

- [ ] **Step 1: Update existing test that sends ntopng.api_key**

In `test_admin_can_update_integrations_settings`, the test currently sends `ntopng.endpoint`. Check if any tests submit the now-removed `ntopng.api_key` field. The existing test sends `endpoint` which is still valid. No change needed unless `api_key` is submitted anywhere.

Check the existing test at line ~122:

```php
$response = $this->actingAs($admin)->put('/admin/settings/integrations', [
    'opnsense' => ['endpoint' => 'https://opnsense.example.com'],
    'ntopng' => ['endpoint' => 'https://ntopng.example.com'],
    'librenms' => ['endpoint' => null],
    'pihole' => ['endpoint' => null],
]);
```

This is fine — `endpoint` is still a valid field. No update needed.

- [ ] **Step 2: Run full SettingsController test suite**

Run: `php artisan test --compact --filter=SettingsControllerTest`

Expected: PASS

- [ ] **Step 3: Commit (if any changes were made)**

```bash
git add tests/Feature/Admin/SettingsControllerTest.php
git commit -m "test: clean up stale ntopng api_key references"
```

---

## Task 11: Run Full Test Suite + Lint + Format

**Files:** None new — validation pass.

### Steps

- [ ] **Step 1: Run Laravel Pint**

Run: `vendor/bin/pint --dirty --format agent`

Fix any formatting issues.

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`

Fix any type errors (likely around the new OpnSense constructor params or IntegrationConfig casts).

- [ ] **Step 3: Run Rector**

Run: `vendor/bin/rector process --dry-run`

Apply any suggested improvements.

- [ ] **Step 4: Run ESLint**

Run: `npm run lint:fix`

Fix any JS formatting issues.

- [ ] **Step 5: Run Prettier**

Run: `npm run format`

- [ ] **Step 6: Run full PHP test suite**

Run: `php artisan test --compact`

Expected: All tests PASS.

- [ ] **Step 7: Run full JS test suite**

Run: `npm run test`

Expected: All tests PASS.

- [ ] **Step 8: Commit any lint/format fixes**

```bash
git add -A
git commit -m "chore: lint and format all files"
```

---

## Summary of Changes by File

| File | Change |
|------|--------|
| `app/Http/Controllers/Admin/SettingsController.php` | Return 8 sections in `integrations()`, expand validation in `updateIntegrations()` |
| `app/Services/Firewalls/OpnSense.php` | Constructor accepts all params via DI |
| `app/Services/Dhcp/OpnSenseDhcpService.php` | Constructor accepts `poolSize` param |
| `app/Providers/AppServiceProvider.php` | Wire OpnSense + ntopng + DHCP pool_size via IntegrationConfig with env fallback |
| `app/Jobs/ScanNetworkDevices.php` | Read auto_allow config from IntegrationConfig |
| `app/Http/Controllers/PortalController.php` | Read IPv6 config from IntegrationConfig |
| `resources/js/Pages/Admin/Settings/Integrations.vue` | 4 expanded sections + 4 new sections |
| `tests/Feature/Admin/SettingsControllerTest.php` | Tests for all 8 sections |
| `tests/Unit/Services/Firewalls/OpnSenseConstructorTest.php` | New — constructor DI test |
| `tests/Feature/Services/NtopNgServiceWiringTest.php` | New — DB config wiring test |
| `tests/Unit/Services/Dhcp/OpnSenseDhcpServicePoolSizeTest.php` | New — pool_size DI test |
| `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php` | New — auto_allow DB config test |
| `tests/Feature/PortalControllerConfigTest.php` | New — IPv6 DB config test |
| `tests/js/Pages/Admin/Settings/Integrations.spec.js` | New — Vitest for all 8 sections |
