# Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all issues identified in the code quality review and UI/UX audit reports, except for Custom CSS injection (documented known risk).

**Architecture:** Six phases ordered by severity and dependency — backend critical DRY/architecture first (foundation other work builds on), then backend service cleanup, backend medium fixes, frontend critical accessibility, frontend refactors, and finally minor UI polish.

**Tech Stack:** Laravel 12 (PHP 8.5), Vue 3 + Inertia.js, Tailwind CSS v4, Vitest, PHPUnit, PHPStan Level 8

**Source Reports:**
- `docs/reviews/code-quality-review.md` (Code Review — CR)
- `docs/reviews/ui-ux-audit.md` (UI/UX Audit — UI)

**Excluded:** CR 6.1 (Custom CSS input — documented known risk)

---

## Phase 1: Backend Critical DRY & Architecture

### Task 1: Extract Setting::set() static method

**Fixes:** CR 1.1 (triplicated saveSetting)

**Files:**
- Modify: `app/Models/Setting.php`
- Modify: `app/Http/Controllers/Admin/ThemeSettingsController.php`
- Modify: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Modify: `app/Http/Controllers/Admin/DnsDetectionSettingsController.php`
- Test: `tests/Unit/Models/SettingTest.php`

- [ ] **Step 1: Write the failing test for Setting::set()**

```php
// tests/Unit/Models/SettingTest.php
public function testSetCreatesNewSetting(): void
{
    Setting::set('test.code', 'Test Name', 'test-value');

    $this->assertDatabaseHas('settings', [
        'code' => 'test.code',
        'name' => 'Test Name',
        'value' => 'test-value',
    ]);
}

public function testSetUpdatesExistingSetting(): void
{
    Setting::factory()->create(['code' => 'test.code', 'name' => 'Old Name', 'value' => 'old']);

    Setting::set('test.code', 'Test Name', 'new-value');

    $this->assertDatabaseHas('settings', [
        'code' => 'test.code',
        'value' => 'new-value',
    ]);
    $this->assertDatabaseCount('settings', 1);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=testSetCreatesNewSetting`
Expected: FAIL — method `set` does not exist

- [ ] **Step 3: Implement Setting::set()**

Add to `app/Models/Setting.php`:

```php
public static function set(string $code, string $name, mixed $value): void
{
    $setting = static::whereCode($code)->first();
    if (! $setting) {
        $setting = new static;
        $setting->code = $code;
        $setting->name = $name;
    }
    $setting->value = $value;
    $setting->save();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=Setting`

- [ ] **Step 5: Remove saveSetting() from all three controllers**

In each of `ThemeSettingsController.php`, `GeneralSettingsController.php`, and `DnsDetectionSettingsController.php`:
- Delete the private `saveSetting()` method entirely
- Replace all calls `$this->saveSetting($code, $name, $value)` with `Setting::set($code, $name, $value)`

- [ ] **Step 6: Run full test suite for affected controllers**

Run: `php artisan test --compact --filter="ThemeSettings\|GeneralSettings\|DnsDetection"`

- [ ] **Step 7: Run PHPStan and Pint**

```bash
vendor/bin/phpstan analyse app/Models/Setting.php app/Http/Controllers/Admin/ThemeSettingsController.php app/Http/Controllers/Admin/GeneralSettingsController.php app/Http/Controllers/Admin/DnsDetectionSettingsController.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Commit**

```bash
git add app/Models/Setting.php app/Http/Controllers/Admin/ThemeSettingsController.php app/Http/Controllers/Admin/GeneralSettingsController.php app/Http/Controllers/Admin/DnsDetectionSettingsController.php tests/Unit/Models/SettingTest.php
git commit -m "refactor: extract Setting::set() to eliminate triplicated saveSetting()"
```

---

### Task 2: Extract SwitchConfig::defaultFallback()

**Fixes:** CR 1.2 (duplicated fallback SwitchConfig construction)

**Files:**
- Modify: `app/Models/SwitchConfig.php`
- Modify: `app/Providers/AppServiceProvider.php:373-395`
- Modify: `app/Http/Controllers/Admin/IpAddressController.php:205-216`
- Test: `tests/Unit/Models/SwitchConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
// tests/Unit/Models/SwitchConfigTest.php
public function testDefaultFallbackReturnsSwitchConfigFromAppConfig(): void
{
    config([
        'aperture.cisco.hostname' => 'test-switch.local',
        'aperture.cisco.username' => 'admin',
        'aperture.cisco.password' => 'secret',
        'aperture.cisco.enablePassword' => 'enable-secret',
        'aperture.cisco.timeout' => 10,
    ]);

    $config = SwitchConfig::defaultFallback();

    $this->assertInstanceOf(SwitchConfig::class, $config);
    $this->assertSame('Default Cisco Switch', $config->name);
    $this->assertSame('test-switch.local', $config->hostname);
    $this->assertSame('cisco', $config->type);
    $this->assertSame('admin', $config->username);
    $this->assertSame('secret', $config->password);
    $this->assertSame('enable-secret', $config->enable_password);
    $this->assertTrue($config->enabled);
    $this->assertSame(22, $config->port);
    $this->assertSame(10, $config->timeout);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=testDefaultFallbackReturnsSwitchConfig`

- [ ] **Step 3: Add static factory method to SwitchConfig**

Add to `app/Models/SwitchConfig.php`:

```php
public static function defaultFallback(): self
{
    return new self([
        'name' => 'Default Cisco Switch',
        'hostname' => (string) config('aperture.cisco.hostname', ''),
        'type' => 'cisco',
        'username' => (string) config('aperture.cisco.username', ''),
        'password' => (string) config('aperture.cisco.password', ''),
        'enable_password' => (string) config('aperture.cisco.enablePassword', ''),
        'enabled' => true,
        'port' => 22,
        'timeout' => (int) config('aperture.cisco.timeout', 5),
    ]);
}
```

- [ ] **Step 4: Replace both callsites**

In `AppServiceProvider.php:373-395`, replace the `new SwitchConfig([...])` block with:
```php
return SwitchConfig::defaultFallback();
```

In `IpAddressController.php:205-216`, replace the identical block with:
```php
$switchConfig = SwitchConfig::defaultFallback();
```

- [ ] **Step 5: Run tests, PHPStan, Pint**

```bash
php artisan test --compact --filter="SwitchConfig\|AppServiceProvider\|IpAddress"
vendor/bin/phpstan analyse app/Models/SwitchConfig.php app/Providers/AppServiceProvider.php app/Http/Controllers/Admin/IpAddressController.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git commit -m "refactor: extract SwitchConfig::defaultFallback() to eliminate duplication"
```

---

### Task 3: Decompose AppServiceProvider into focused providers

**Fixes:** CR 2.1 (god method), CR 5.3 (mixed register/boot)

**Files:**
- Create: `app/Providers/IntegrationServiceProvider.php`
- Create: `app/Providers/NetworkServiceProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `bootstrap/providers.php` (or `config/app.php` depending on structure)
- Test: existing tests in `tests/Unit/Providers/AppServiceProviderTest.php`

- [ ] **Step 1: Identify the boundaries**

Read `app/Providers/AppServiceProvider.php` and split into three groups:

**IntegrationServiceProvider** — External service bindings:
- `OpnSense` (FirewallBackendInterface)
- `NtopNgService`
- `BorealisService`
- `LibreNmsService` (NetworkInventoryInterface)
- `PiHoleService` (DnsFilteringInterface)
- `PrometheusService` (MetricsProviderInterface)
- `PrometheusTrafficMonitor` (TrafficMonitorInterface)
- `IntegrationTesterRegistry`
- `OpnSenseDhcpService` (DhcpInterface)

**NetworkServiceProvider** — Network infrastructure:
- `SshProxyClient` (SshProxyClientInterface)
- `SwitchServiceFactory`
- `NetworkSwitchInterface`
- `MacAddressResolverInterface`

**AppServiceProvider** — remains with:
- Model observers
- View::share for siteTitle
- AuthProvider binding

- [ ] **Step 2: Create IntegrationServiceProvider**

Create `app/Providers/IntegrationServiceProvider.php`. Move all integration singleton closures from both `register()` and `boot()` into the new provider's `register()` method. Include the `getIntegrationDbConfig()` helper.

- [ ] **Step 3: Create NetworkServiceProvider**

Create `app/Providers/NetworkServiceProvider.php`. Move SSH proxy, switch factory, network switch, and MAC resolver bindings. Include the `getDefaultSwitchConfig()` helper (which now calls `SwitchConfig::defaultFallback()` from Task 2).

- [ ] **Step 4: Slim down AppServiceProvider**

Remove all moved bindings. `register()` should contain only `AuthProviderInterface` binding. `boot()` should contain only model observers and `View::share`.

- [ ] **Step 5: Register new providers**

Add to `bootstrap/providers.php` (or `config/app.php`):
```php
App\Providers\IntegrationServiceProvider::class,
App\Providers\NetworkServiceProvider::class,
```

- [ ] **Step 6: Standardize parameter naming**

In both new providers, use `$app` consistently (not `$application`) for the Application parameter in closures. (Fixes CR 4.3.)

- [ ] **Step 7: Run full test suite**

```bash
php artisan test --compact
```

All existing tests must pass unchanged — the bindings are identical, just relocated.

- [ ] **Step 8: Run PHPStan and Pint**

```bash
vendor/bin/phpstan analyse app/Providers/
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Commit**

```bash
git commit -m "refactor: decompose AppServiceProvider into Integration and Network providers"
```

---

### Task 4: Move business logic out of IpAddress model

**Fixes:** CR 2.2 (service locator), CR 2.3 (__get override), CR 5.2 (business logic in model), CR 8.1 (silent failure in updateUsage)

**Files:**
- Modify: `app/Models/IpAddress.php`
- Modify: `app/Services/IpAddressActionService.php`
- Modify: all callers of `$ip->shutPort()`, `$ip->unshutPort()`, `$ip->updateUsage()`, `$ip->getStats()`, `$ip->port`, `$ip->mac`
- Test: `tests/Unit/Models/IpAddressTest.php`, `tests/Unit/Services/IpAddressActionServiceTest.php`

- [ ] **Step 1: Find all callers of the model's business methods**

Search for `->shutPort(`, `->unshutPort(`, `->updateUsage(`, `->getStats(`, `->port`, `->mac` on IpAddress instances. Use `find_references` to identify every callsite.

- [ ] **Step 2: Replace __get magic with proper Eloquent accessors**

In `app/Models/IpAddress.php`, remove the `__get()` override entirely. Add:

```php
protected function mac(): Attribute
{
    return Attribute::make(
        get: fn () => $this->macAddress?->mac_address,
    );
}
```

Remove the `port` and `portUpdatedAt` virtual properties — callers should use `IpAddressActionService::getPortInfo($ip)` instead. (The `portUpdatedAt` case was dead code returning `null`.)

- [ ] **Step 3: Move getPortInfo() to IpAddressActionService**

Move `getPortInfo()` from the model to `IpAddressActionService`, accepting `IpAddress $ip` as a parameter. Inject `NetworkInventoryInterface` via the service constructor (it's already a singleton in the container).

```php
// IpAddressActionService
public function getPortInfo(IpAddress $ip): ?PortDetail
{
    try {
        $resolved = $this->inventory->resolveIpToPort($ip->address);
        if ($resolved === null) {
            return null;
        }
        return $this->inventory->getPortDetail($resolved->port);
    } catch (Throwable) {
        return null;
    }
}
```

- [ ] **Step 4: Remove model proxy methods**

Remove `shutPort()`, `unshutPort()`, `updateUsage()`, `getStats()` from the model. Update all callers to use `IpAddressActionService` directly (resolved via DI or the container).

- [ ] **Step 5: Add error logging to updateUsage**

In `IpAddressActionService::updateUsage()`, the caller in the model previously caught `ClientException` silently. Update the service method to catch `Throwable` and log:

```php
public function updateUsage(IpAddress $ip): void
{
    try {
        $stats = $this->ntopNg->getIpStats($ip->address);
        $rcvd = 'bytes.rcvd';
        $sent = 'bytes.sent';
        $ip->received = (int) ($stats->rsp->$rcvd ?? 0);
        $ip->sent = (int) ($stats->rsp->$sent ?? 0);
        $ip->save();
    } catch (Throwable $e) {
        Log::warning('Failed to update IP usage stats', [
            'ip' => $ip->address,
            'error' => $e->getMessage(),
        ]);
    }
}
```

(This also addresses CR 8.2 — the dynamic property access is documented with variable names explaining the dotted keys.)

- [ ] **Step 6: Update all callers**

Every place that called `$ip->shutPort()` etc. now needs to inject or resolve `IpAddressActionService`. Key callers:
- `IpAddressController::port()` — already has access via DI
- `IpAddressAction` job — resolve via constructor injection
- Any other references found in Step 1

- [ ] **Step 7: Run tests and fix any breakage**

```bash
php artisan test --compact
npx vitest run
```

- [ ] **Step 8: Run PHPStan and Pint**

```bash
vendor/bin/phpstan analyse app/Models/IpAddress.php app/Services/IpAddressActionService.php
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Commit**

```bash
git commit -m "refactor: move business logic from IpAddress model to IpAddressActionService"
```

---

### Task 5: Fix N+1 queries in SwitchPortController::show()

**Fixes:** CR 3.1 (N+1 query risk), CR 2.4 (large method)

**Files:**
- Modify: `app/Http/Controllers/Admin/SwitchPortController.php:107-146`
- Test: `tests/Feature/Admin/SwitchPortControllerTest.php`

- [ ] **Step 1: Extract MAC resolution into a dedicated method**

Extract the MAC-to-IP resolution logic (lines 107-146) into a private method:

```php
private function resolveConnectedDevices(Collection $macs, MacAddressResolverInterface $macResolver): array
{
    // 1. Resolve all MACs to IPs in one pass
    $macIpMap = [];
    foreach ($macs as $mac) {
        $resolved = $macResolver->resolveMacToIps($mac->mac_address);
        foreach ($resolved as $ipData) {
            $macIpMap[$ipData['ip']] = [
                'mac' => $mac->mac_address,
                'vendor' => $mac->vendor,
                'ip' => $ipData['ip'],
            ];
        }
    }

    // 2. Batch-load all IpAddress records
    $ipAddresses = IpAddress::whereIn('address', array_keys($macIpMap))
        ->with(['users' => fn ($q) => $q->with('user')->latest('last_seen_at')->limit(1)])
        ->get()
        ->keyBy('address');

    // 3. Assemble results
    return array_map(function (array $entry) use ($ipAddresses) {
        $ipRecord = $ipAddresses->get($entry['ip']);
        $latestUser = $ipRecord?->users->first();
        return [
            ...$entry,
            'user' => $latestUser?->user ? [
                'id' => $latestUser->user->id,
                'nickname' => $latestUser->user->nickname,
            ] : null,
        ];
    }, array_values($macIpMap));
}
```

- [ ] **Step 2: Update show() to use the extracted method**

Replace the inline MAC resolution block with a single call:
```php
$connectedDevices = $this->resolveConnectedDevices($macs, $macResolver);
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=SwitchPort
```

- [ ] **Step 4: Run PHPStan and Pint, commit**

```bash
vendor/bin/phpstan analyse app/Http/Controllers/Admin/SwitchPortController.php
vendor/bin/pint --dirty --format agent
git commit -m "fix: batch IP lookups in SwitchPortController to eliminate N+1 queries"
```

---

### Task 6: Fix N+1 in ScanNetworkDevices job

**Fixes:** CR 3.2

**Files:**
- Modify: `app/Jobs/ScanNetworkDevices.php:68-104`
- Test: `tests/Unit/Jobs/ScanNetworkDevicesTest.php`

- [ ] **Step 1: Batch MAC address lookups**

In the `handle()` method, after collecting all resolved MACs, pre-load matching records:

```php
// Collect all normalized MACs first
$normalizedMacs = array_map(fn ($m) => $this->normalizeMac($m), array_values($resolvedMacs));

// Batch load existing MacAddress records
$existingMacs = MacAddress::whereIn('mac_address', $normalizedMacs)->get()->keyBy('mac_address');

// Then in the loop, use $existingMacs->get($normalizedMac) instead of MacAddress::where(...)->first()
```

- [ ] **Step 2: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact --filter=ScanNetwork
vendor/bin/phpstan analyse app/Jobs/ScanNetworkDevices.php
vendor/bin/pint --dirty --format agent
git commit -m "fix: batch MAC lookups in ScanNetworkDevices to eliminate N+1 queries"
```

---

## Phase 2: Backend Service Cleanup

### Task 7: Extract generic reconciliation in OpnSense

**Fixes:** CR 1.3 (duplicated reconciliation logic)

**Files:**
- Modify: `app/Services/Firewalls/OpnSense.php:339-461`
- Test: `tests/Unit/Services/Firewalls/OpnSenseTest.php`

- [ ] **Step 1: Create generic reconcile method**

Extract a private method:

```php
/**
 * @param  list<string>  $currentIps  IPs currently in the desired state on the firewall
 * @param  list<string>  $desiredIps  IPs that should be in the desired state per DB
 */
private function reconcile(
    array $currentIps,
    array $desiredIps,
    callable $enableAction,
    callable $disableAction,
    bool $dryRun,
): ReconcileResult {
    $current = array_flip($currentIps);
    $desired = array_flip($desiredIps);

    $toAdd = array_diff_key($desired, $current);
    $toRemove = array_diff_key($current, $desired);
    $unchanged = array_intersect_key($current, $desired);

    $added = [];
    $removed = [];
    $errors = [];

    if (! $dryRun) {
        foreach (array_keys($toAdd) as $ip) {
            try {
                $enableAction($ip);
                $added[] = $ip;
            } catch (Throwable $e) {
                $errors[] = ['ip' => $ip, 'action' => 'enable', 'error' => $e->getMessage()];
            }
        }
        foreach (array_keys($toRemove) as $ip) {
            try {
                $disableAction($ip);
                $removed[] = $ip;
            } catch (Throwable $e) {
                $errors[] = ['ip' => $ip, 'action' => 'disable', 'error' => $e->getMessage()];
            }
        }
    }

    return new ReconcileResult(
        added: $dryRun ? array_keys($toAdd) : $added,
        removed: $dryRun ? array_keys($toRemove) : $removed,
        unchanged: array_keys($unchanged),
        errors: $errors,
    );
}
```

- [ ] **Step 2: Rewrite reconcileInternet() and reconcileRateLimits()**

```php
public function reconcileInternet(bool $dryRun = false): ReconcileResult
{
    $currentIps = $this->fetchConnectedIps();
    $desiredIps = IpAddress::where('internet_enabled', true)->pluck('address')->toArray();

    return $this->reconcile(
        $currentIps,
        $desiredIps,
        fn (string $ip) => $this->updateIp($ip),
        fn (string $ip) => $this->removeIp($ip),
        $dryRun,
    );
}

public function reconcileRateLimits(bool $dryRun = false): ReconcileResult
{
    $currentIps = $this->fetchRateLimitedIps();
    $desiredIps = IpAddress::where('rate_limit_enabled', true)->pluck('address')->toArray();

    return $this->reconcile(
        $currentIps,
        $desiredIps,
        fn (string $ip) => $this->limitIp($ip),
        fn (string $ip) => $this->unlimitIp($ip),
        $dryRun,
    );
}
```

- [ ] **Step 3: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact --filter=OpnSense
vendor/bin/phpstan analyse app/Services/Firewalls/OpnSense.php
vendor/bin/pint --dirty --format agent
git commit -m "refactor: extract generic reconcile() to eliminate duplicated reconciliation logic"
```

---

### Task 8: Refactor OpnSenseApiService to instance methods

**Fixes:** CR 1.4 (duplicated validation), CR 3.4 (static methods)

**Files:**
- Modify: `app/Services/Firewalls/OpnSenseApiService.php`
- Modify: all callers (search for `OpnSenseApiService::`)
- Test: existing tests

- [ ] **Step 1: Convert to instance class with injected config**

Replace static methods with instance methods. Add constructor accepting config array. Extract `validateConfig()` private method to eliminate duplicated guard logic in `getShaperRules()` and `getZones()`.

```php
class OpnSenseApiService
{
    private readonly string $endpoint;
    private readonly string $key;
    private readonly string $secret;
    private readonly bool $verifySsl;

    public function __construct(array $config)
    {
        $this->endpoint = $config['endpoint'] ?? '';
        $this->key = $config['key'] ?? '';
        $this->secret = $config['secret'] ?? '';
        $this->verifySsl = (bool) ($config['verify_ssl'] ?? true);
    }

    private function validateConfig(): ?string
    {
        if ($this->endpoint === '') {
            return 'OPNsense endpoint is not configured.';
        }
        if ($this->key === '' || $this->secret === '') {
            return 'OPNsense API credentials are not configured.';
        }
        return null;
    }

    public function getShaperRules(): array { /* use $this->validateConfig() */ }
    public function getZones(): array { /* use $this->validateConfig() */ }
}
```

- [ ] **Step 2: Register as singleton and update callers**

Register in `IntegrationServiceProvider` (from Task 3). Update all callers from `OpnSenseApiService::getShaperRules($config)` to injected instance calls.

- [ ] **Step 3: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact
vendor/bin/phpstan analyse app/Services/Firewalls/OpnSenseApiService.php
vendor/bin/pint --dirty --format agent
git commit -m "refactor: convert OpnSenseApiService from static to instance methods"
```

---

### Task 9: Clean up OpnSenseDhcpService enrichRange duplication

**Fixes:** CR 1.5

**Files:**
- Modify: `app/Services/Dhcp/OpnSenseDhcpService.php:241-310`

- [ ] **Step 1: Extract buildEnrichedRange helper**

Keep IPv4/IPv6 address arithmetic in separate methods, but extract the shared `DhcpRange` construction:

```php
private function buildEnrichedRange(DhcpRange $range, int $totalAddresses, int $usedAddresses): DhcpRange
{
    $utilisation = $totalAddresses > 0 ? $usedAddresses / $totalAddresses : 0.0;

    return new DhcpRange(
        interface: $range->interface,
        subnet: $range->subnet,
        rangeFrom: $range->rangeFrom,
        rangeTo: $range->rangeTo,
        gateway: $range->gateway,
        description: $range->description,
        prefix: $range->prefix,
        pools: $range->pools,
        totalAddresses: $totalAddresses,
        usedAddresses: $usedAddresses,
        utilisation: $utilisation,
    );
}
```

Slim both `enrichIpv4RangeWithUsage()` and `enrichIpv6RangeWithUsage()` to just compute `$totalAddresses` and `$usedAddresses`, then call `$this->buildEnrichedRange(...)`.

- [ ] **Step 2: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact --filter=Dhcp
vendor/bin/phpstan analyse app/Services/Dhcp/OpnSenseDhcpService.php
vendor/bin/pint --dirty --format agent
git commit -m "refactor: extract buildEnrichedRange to eliminate DHCP enrichment duplication"
```

---

### Task 10: Consolidate theme settings loading

**Fixes:** CR 5.1 (theme loaded twice), CR 7.2 (duplicated defaults)

**Files:**
- Modify: `config/aperture.php` — add theme defaults section
- Create: `app/Services/ThemeService.php` — single source of truth for theme state
- Modify: `app/Http/Middleware/InjectTheme.php` — use ThemeService
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` — use ThemeService
- Modify: `app/Http/Controllers/Admin/ThemeSettingsController.php` — use config defaults
- Modify: `resources/js/composables/useAccentColor.js` — import defaults from Inertia props
- Modify: `resources/js/composables/useTheme.js` — import defaults from useAccentColor
- Delete: `resources/js/composables/useAccentHue.js` — merge into useAccentColor (Fixes CR 7.1)

- [ ] **Step 1: Add theme defaults to config/aperture.php**

```php
'theme' => [
    'mode' => 'dark',
    'accent_hue' => 55,
    'accent_chroma' => 0.19,
    'accent_lightness' => 72,
],
```

- [ ] **Step 2: Create ThemeService**

```php
// app/Services/ThemeService.php
class ThemeService
{
    private ?array $cached = null;

    public function getTheme(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $this->cached = [
            'mode' => (string) Setting::get('theme.mode', config('aperture.theme.mode')),
            'accent_hue' => (int) Setting::get('theme.accent_hue', config('aperture.theme.accent_hue')),
            'accent_chroma' => (float) Setting::get('theme.accent_chroma', config('aperture.theme.accent_chroma')),
            'accent_lightness' => (int) Setting::get('theme.accent_lightness', config('aperture.theme.accent_lightness')),
            'custom_css' => Setting::get('theme.custom_css'),
            'site_title' => (string) Setting::get('site_title', config('app.name', 'Aperture')),
        ];

        return $this->cached;
    }
}
```

Register as a request-scoped singleton. Update both `InjectTheme` and `HandleInertiaRequests` to inject and use `ThemeService::getTheme()`.

- [ ] **Step 3: Consolidate frontend composables**

Move `applyAccentHue()` into `useAccentColor.js` as an exported function. Delete `useAccentHue.js`. Update any imports of `useAccentHue` to use `useAccentColor`. Remove hardcoded defaults from `useTheme.js` — read from Inertia props via `usePage().props.theme`.

- [ ] **Step 4: Run all tests**

```bash
php artisan test --compact
npx vitest run
```

- [ ] **Step 5: PHPStan, Pint, ESLint, Prettier, commit**

```bash
vendor/bin/phpstan analyse app/Services/ThemeService.php app/Http/Middleware/
vendor/bin/pint --dirty --format agent
npx eslint resources/js/composables/
npx prettier --write resources/js/composables/
git commit -m "refactor: consolidate theme loading into ThemeService, centralize defaults"
```

---

### Task 11: Inject Guzzle clients for testability

**Fixes:** CR 3.3

**Files:**
- Modify: `app/Services/Firewalls/OpnSense.php` — accept Client via constructor
- Modify: `app/Providers/IntegrationServiceProvider.php` — construct Client before passing

- [ ] **Step 1: Refactor OpnSense constructor**

Replace endpoint/key/secret parameters with a pre-configured `Client`:

```php
public function __construct(
    private readonly Client $client,
    private readonly int $zoneId = 0,
    private readonly string $uploadRuleUuid = '',
    private readonly string $downloadRuleUuid = '',
) {}
```

- [ ] **Step 2: Update provider to construct Client**

In the IntegrationServiceProvider, construct the Guzzle Client and pass it:

```php
$client = new Client([
    'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
    'base_uri' => $dbConfig['endpoint'] ?? '',
    'auth' => [$dbConfig['key'] ?? '', $dbConfig['secret'] ?? ''],
]);

return new OpnSense($client, ...);
```

- [ ] **Step 3: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact --filter=OpnSense
vendor/bin/phpstan analyse app/Services/Firewalls/OpnSense.php
vendor/bin/pint --dirty --format agent
git commit -m "refactor: inject Guzzle client into OpnSense for testability"
```

---

### Task 12: Clean up TestConnectionController

**Fixes:** CR 2.5

**Files:**
- Modify: `app/Http/Controllers/Admin/TestConnectionController.php:41-184`

- [ ] **Step 1: Extract error handler**

Add private method:

```php
private function recordAndReturnError(
    string $service,
    string $hostname,
    string $message,
    ?int $statusCode = null,
    ?string $requestMethod = null,
    ?string $requestUrl = null,
): JsonResponse {
    ConnectionTestLog::record($service, $hostname, false, $message);

    return response()->json([
        'success' => false,
        'message' => $message,
        'status_code' => $statusCode,
        'request_method' => $requestMethod,
        'request_url' => $requestUrl,
    ]);
}
```

Replace all 4 catch blocks to call this method.

- [ ] **Step 2: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact --filter=TestConnection
vendor/bin/phpstan analyse app/Http/Controllers/Admin/TestConnectionController.php
vendor/bin/pint --dirty --format agent
git commit -m "refactor: extract error handler in TestConnectionController"
```

---

## Phase 3: Backend Medium Fixes

### Task 13: Harden IpAddressController validation

**Fixes:** CR 3.5 (loose comparison), CR 6.3 (missing validation), CR 9.1 (unused $config), CR 9.2 (eslint-disable)

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`
- Modify: `resources/js/Pages/Admin/Ips/Show.vue:49`

- [ ] **Step 1: Add proper validation to port(), limit(), internet()**

```php
public function port(Request $request, IpAddress $ip): RedirectResponse
{
    $request->validate(['shutdown' => 'required|boolean']);
    if ($request->boolean('shutdown')) {
        // shutPort logic
    } else {
        // unshutPort logic
    }
    // ...
}

public function limit(Request $request, IpAddress $ip): RedirectResponse
{
    $request->validate(['limit' => 'required|boolean']);
    $ip->rate_limit_enabled = $request->boolean('limit');
    // ...
}

public function internet(Request $request, IpAddress $ip): RedirectResponse
{
    $request->validate(['allow' => 'required|boolean']);
    $ip->internet_enabled = $request->boolean('allow');
    // ...
}
```

- [ ] **Step 2: Remove unused $config variable**

In `show()` method, remove `$config = null;` and the `'config' => $config` prop.

- [ ] **Step 3: Remove unused parameter in Show.vue**

In `resources/js/Pages/Admin/Ips/Show.vue:49`, change `function togglePort(ip)` to `function togglePort()` and remove the `// eslint-disable-next-line` comment.

- [ ] **Step 4: Run tests, linting, commit**

```bash
php artisan test --compact --filter=IpAddress
vendor/bin/phpstan analyse app/Http/Controllers/Admin/IpAddressController.php
npx eslint resources/js/Pages/Admin/Ips/Show.vue
vendor/bin/pint --dirty --format agent
git commit -m "fix: add proper validation to IpAddressController actions, remove dead code"
```

---

### Task 14: Harden SearchController

**Fixes:** CR 5.4

**Files:**
- Modify: `app/Http/Controllers/Admin/SearchController.php`

- [ ] **Step 1: Escape LIKE metacharacters and add max length**

```php
public function search(Request $request): JsonResponse
{
    $request->validate([
        'q' => 'required|string|min:2|max:100',
    ]);

    $query = str_replace(['%', '_'], ['\%', '\_'], $request->input('q'));
    $pattern = sprintf('%%%s%%', $query);

    // ... existing LIKE queries with escaped $pattern
}
```

- [ ] **Step 2: Add throttle middleware to the search route**

In the route definition, add `->middleware('throttle:60,1')`.

- [ ] **Step 3: Run tests, PHPStan, Pint, commit**

```bash
php artisan test --compact --filter=Search
vendor/bin/phpstan analyse app/Http/Controllers/Admin/SearchController.php
vendor/bin/pint --dirty --format agent
git commit -m "fix: escape LIKE metacharacters and add rate limiting to search"
```

---

### Task 15: Add declare(strict_types=1) to all PHP files

**Fixes:** CR 9.3

**Files:**
- Modify: all PHP files in `app/` missing the declaration

- [ ] **Step 1: Use Rector to add strict_types**

Add a Rector rule for `declare(strict_types=1)` and run:

```bash
vendor/bin/rector process app/ --dry-run
vendor/bin/rector process app/
```

Alternatively, find files missing it and add manually:

```bash
grep -rL "declare(strict_types=1)" app/ --include="*.php"
```

- [ ] **Step 2: Run full test suite to catch type errors**

```bash
php artisan test --compact
```

- [ ] **Step 3: Fix any type errors surfaced, run PHPStan, Pint, commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/pint --dirty --format agent
git commit -m "chore: add declare(strict_types=1) to all PHP files"
```

---

## Phase 4: Frontend Critical Accessibility

### Task 16: Fix GlobalSearch accessibility (P0)

**Fixes:** UI P0 (no focus trap, no ARIA), UI P2 (no loading indicator, silent error)

**Files:**
- Modify: `resources/js/Components/Admin/GlobalSearch.vue`
- Test: `tests/js/Components/Admin/GlobalSearch.spec.js`

- [ ] **Step 1: Write failing tests**

```javascript
it('has dialog ARIA attributes when open', async () => {
    // Open search, verify role="dialog", aria-modal="true", aria-label
});

it('traps focus within the search overlay', async () => {
    // Open search, press Tab, verify focus stays within
});

it('shows loading indicator while fetching', async () => {
    // Trigger search, verify loading spinner is visible
});

it('shows error message when search fails', async () => {
    // Mock failed fetch, verify error message is shown
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run tests/js/Components/Admin/GlobalSearch.spec.js`

- [ ] **Step 3: Add ARIA attributes to the overlay**

On the overlay container, add:
```html
role="dialog"
aria-modal="true"
aria-label="Search"
```

On the input, add:
```html
aria-label="Search users and IP addresses"
```

- [ ] **Step 4: Implement focus trap**

Use the same pattern as `ConfirmModal.vue`:
- On open, focus the input
- Track focusable elements within the dialog
- On Tab/Shift+Tab at edges, wrap focus
- On Escape, close and restore focus to the trigger element

- [ ] **Step 5: Add loading indicator**

The `loading` ref already exists. Add a spinner element in the results area:

```html
<div v-if="loading" class="flex justify-center py-6" data-testid="search-loading">
    <svg class="h-5 w-5 animate-spin text-[var(--color-text-muted)]" ...>...</svg>
</div>
```

- [ ] **Step 6: Show error state instead of silent failure**

Replace the empty catch block:
```javascript
const error = ref('');

// In the search function:
try {
    error.value = '';
    // ... fetch logic
} catch (_e) {
    error.value = 'Search is temporarily unavailable.';
    results.value = { users: [], ips: [] };
}
```

Add in template:
```html
<p v-if="error" class="px-4 py-3 text-[13px] text-[var(--color-danger)]" data-testid="search-error">
    {{ error }}
</p>
```

- [ ] **Step 7: Run tests, ESLint, Prettier, commit**

```bash
npx vitest run tests/js/Components/Admin/GlobalSearch.spec.js
npx eslint resources/js/Components/Admin/GlobalSearch.vue
npx prettier --write resources/js/Components/Admin/GlobalSearch.vue
git commit -m "fix: add focus trap, ARIA roles, loading and error states to GlobalSearch"
```

---

### Task 17: Add skip-navigation links

**Fixes:** UI P1 (no skip-nav)

**Files:**
- Modify: `resources/js/Layouts/AdminLayout.vue`
- Modify: `resources/js/Layouts/PortalLayout.vue`
- Test: `tests/js/Layouts/AdminLayout.spec.js` (create if needed)

- [ ] **Step 1: Add skip link to AdminLayout**

As the first child of the root div, add:

```html
<a
    href="#main-content"
    class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] focus:rounded focus:bg-[var(--color-surface)] focus:px-4 focus:py-2 focus:text-[var(--color-text)] focus:shadow-lg"
    data-testid="skip-nav"
>
    Skip to content
</a>
```

Add `id="main-content"` to the `<main>` element.

- [ ] **Step 2: Add same skip link to PortalLayout**

Same pattern. Add `id="main-content"` to the `<main>` element.

- [ ] **Step 3: Write test, run, commit**

```javascript
it('renders skip navigation link', () => {
    const wrapper = mount(AdminLayout, { ... });
    const skipLink = wrapper.find('[data-testid="skip-nav"]');
    expect(skipLink.exists()).toBe(true);
    expect(skipLink.attributes('href')).toBe('#main-content');
});
```

```bash
npx vitest run tests/js/Layouts/
npx prettier --write resources/js/Layouts/
git commit -m "fix: add skip-navigation links to Admin and Portal layouts"
```

---

### Task 18: Fix Sidebar icons and navigation

**Fixes:** CR 2.6 (inline SVG), CR 2.7 (hardcoded URLs), CR 6.2 (v-html XSS), CR 7.3 (resize debounce), UI P1 (icon ARIA)

**Files:**
- Create: `resources/js/Components/Icons/` — individual SVG icon components
- Modify: `resources/js/Components/Admin/Sidebar.vue`
- Test: `tests/js/Components/Admin/Sidebar.spec.js`

- [ ] **Step 1: Create icon components**

Create one SFC per icon (DashboardIcon.vue, UsersIcon.vue, GlobeIcon.vue, SwitchesIcon.vue, DatabaseIcon.vue, DocumentIcon.vue, SettingsIcon.vue). Each renders the SVG directly with `aria-hidden="true"` and accepts size via class.

Example `resources/js/Components/Icons/DashboardIcon.vue`:
```vue
<template>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4 shrink-0" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
    </svg>
</template>
```

- [ ] **Step 2: Replace v-html icons with components**

In `Sidebar.vue`, import icon components. Replace the `icons` object with component references. In the template, replace `<span v-html="item.icon" />` with `<component :is="item.icon" />`.

- [ ] **Step 3: Replace hardcoded URLs with route()**

Change navigation items to use Ziggy's `route()`:
```javascript
{ label: 'Dashboard', href: route('admin.home'), icon: DashboardIcon },
{ label: 'Users', href: route('admin.users.index'), icon: UsersIcon },
// etc.
```

- [ ] **Step 4: Debounce resize listener**

Replace the raw resize listener with `matchMedia`:
```javascript
const mql = window.matchMedia('(min-width: 1025px)');

function onBreakpointChange(e) {
    isDesktop.value = e.matches;
}

onMounted(() => {
    isDesktop.value = mql.matches;
    mql.addEventListener('change', onBreakpointChange);
});

onUnmounted(() => {
    mql.removeEventListener('change', onBreakpointChange);
});
```

- [ ] **Step 5: Update tests**

Update Sidebar tests to account for component-based icons and route() usage.

- [ ] **Step 6: Run tests, ESLint, Prettier, commit**

```bash
npx vitest run tests/js/Components/Admin/Sidebar.spec.js
npx eslint resources/js/Components/Icons/ resources/js/Components/Admin/Sidebar.vue
npx prettier --write resources/js/Components/Icons/ resources/js/Components/Admin/Sidebar.vue
git commit -m "refactor: replace v-html icons with components, use named routes, debounce resize"
```

---

### Task 19: Fix ThemeToggle and UserMenu accessibility

**Fixes:** UI P1 (ThemeToggle), UI P2 (UserMenu)

**Files:**
- Modify: `resources/js/Components/ThemeToggle.vue`
- Modify: `resources/js/Components/UserMenu.vue`
- Test: `tests/js/Components/ThemeToggle.spec.js`, `tests/js/Components/UserMenu.spec.js`

- [ ] **Step 1: Fix ThemeToggle**

Add to the button:
```html
role="switch"
:aria-checked="mode === 'dark'"
:aria-label="mode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'"
```

- [ ] **Step 2: Fix UserMenu**

On the trigger button, add:
```html
aria-haspopup="menu"
:aria-expanded="open"
```

On the dropdown container, add:
```html
role="menu"
```

On each menu item link, add:
```html
role="menuitem"
```

Add arrow key navigation within the menu (up/down to move between items, Enter to activate).

- [ ] **Step 3: Write tests, run, commit**

```bash
npx vitest run tests/js/Components/
npx eslint resources/js/Components/ThemeToggle.vue resources/js/Components/UserMenu.vue
npx prettier --write resources/js/Components/ThemeToggle.vue resources/js/Components/UserMenu.vue
git commit -m "fix: add ARIA attributes to ThemeToggle and UserMenu"
```

---

### Task 20: Responsive fixes for stat strips and grids

**Fixes:** UI P1 (stat strips overflow, dashboard grid, MetadataStrip), UI P2 (admin layout padding)

**Files:**
- Modify: `resources/js/Components/UI/MetadataStrip.vue`
- Modify: `resources/js/Pages/Admin/Dashboard.vue`
- Modify: `resources/js/Layouts/AdminLayout.vue`
- Test: visual verification in browser

- [ ] **Step 1: Make MetadataStrip responsive**

Add `flex-wrap` and adjust the border/spacing pattern:

```html
<div class="flex flex-wrap gap-y-3" ...>
    <div v-for="(item, i) in items" :key="i"
        :class="[i > 0 ? 'border-l border-[var(--color-border)] pl-6 ml-6 max-sm:border-0 max-sm:pl-0 max-sm:ml-0' : '']"
    >
```

- [ ] **Step 2: Make Dashboard stat strip and grid responsive**

For the stat strip, add `flex-wrap gap-y-4` and remove fixed `mr-8 pr-8` in favor of responsive gap:

```html
<div class="flex flex-wrap gap-x-8 gap-y-4">
```

For the DHCP/IPs grid:
```html
class="grid grid-cols-1 gap-6 md:grid-cols-[3fr_2fr]"
```

Apply same `flex-wrap` pattern to stat strips on Users/Index, Switches/Index, and Dhcp/Index.

- [ ] **Step 3: Reduce admin layout padding on mobile**

In `AdminLayout.vue`, change main padding:
```html
<main class="... px-6 md:px-10">
```

- [ ] **Step 4: Run tests, commit**

```bash
npx vitest run
npx prettier --write resources/js/Components/UI/MetadataStrip.vue resources/js/Pages/Admin/Dashboard.vue resources/js/Layouts/AdminLayout.vue
git commit -m "fix: make stat strips, MetadataStrip, and dashboard grid responsive"
```

---

## Phase 5: Frontend Refactors

### Task 21: Refactor Switches/Index to use DataTable

**Fixes:** UI P1 (hand-built table duplicating DataTable)

**Files:**
- Modify: `resources/js/Pages/Admin/Switches/Index.vue`
- Test: `tests/js/Pages/Admin/Switches/Index.spec.js`

- [ ] **Step 1: Rewrite to use DataTable component**

Replace the 418-line custom table with DataTable. Define columns array matching the current display. Use DataTable's built-in sorting, ARIA, and row click handlers. Move the custom cell rendering (status dots, port breakdowns, sync timestamps) into `#row` slot template.

- [ ] **Step 2: Update tests to work with DataTable**

- [ ] **Step 3: Run tests, ESLint, Prettier, commit**

```bash
npx vitest run tests/js/Pages/Admin/Switches/
npx eslint resources/js/Pages/Admin/Switches/Index.vue
npx prettier --write resources/js/Pages/Admin/Switches/Index.vue
git commit -m "refactor: rewrite Switches/Index to use DataTable component"
```

---

### Task 22: Fix Switches/Show broken template references

**Fixes:** UI P2 (latestSync, canDownloadConfig, showConfig, runningConfig undefined)

**Files:**
- Modify: `resources/js/Pages/Admin/Switches/Show.vue`
- Test: `tests/js/Pages/Admin/Switches/Show.spec.js`

- [ ] **Step 1: Investigate and fix broken refs**

Read the component to determine if these are:
a) Template remnants that should be removed (features never implemented)
b) Missing props that should be passed from the controller
c) Missing computed properties that should be added

Fix accordingly — either remove dead template sections or add the missing data flow.

- [ ] **Step 2: Run tests, commit**

```bash
npx vitest run tests/js/Pages/Admin/Switches/
git commit -m "fix: resolve broken template references in Switches/Show"
```

---

### Task 23: Replace window.confirm in Account Settings

**Fixes:** UI P2 (window.confirm for destructive actions)

**Files:**
- Modify: `resources/js/Pages/Account/Settings.vue`
- Test: `tests/js/Pages/Account/Settings.spec.js`

- [ ] **Step 1: Add ConfirmModal for password clearing**

Import `ConfirmModal`. Add a `showClearPasswordModal` ref. Replace `if (confirm(...))` with modal trigger. Add a `<ConfirmModal>` in the template:

```html
<ConfirmModal
    :open="showClearPasswordModal"
    @close="showClearPasswordModal = false"
    @confirm="doClearPassword"
    title="Remove Password?"
    message="You will need passkeys or Borealis to sign in."
    confirm-label="Remove Password"
    variant="danger"
/>
```

- [ ] **Step 2: Add ConfirmModal for passkey deletion**

Same pattern with `showDeletePasskeyModal` ref and the passkey-specific messaging.

- [ ] **Step 3: Run tests, commit**

```bash
npx vitest run tests/js/Pages/Account/
npx prettier --write resources/js/Pages/Account/Settings.vue
git commit -m "fix: replace window.confirm with ConfirmModal in Account Settings"
```

---

### Task 24: Fix FilterBar accessibility and search consistency

**Fixes:** UI P2 (FilterBar input no label, search inconsistency)

**Files:**
- Modify: `resources/js/Components/UI/FilterBar.vue`
- Modify: `resources/js/Pages/Admin/Ips/Index.vue` — unify search to debounced
- Test: `tests/js/Components/UI/FilterBar.spec.js`

- [ ] **Step 1: Add accessible labels to FilterBar**

Add `aria-label="Search"` to the search input. Add `<label class="sr-only">` elements for each filter select, or use `aria-label` on each select.

- [ ] **Step 2: Add debounced search to FilterBar**

Add a `debounce` prop (default 300ms) to FilterBar. Emit `update:search` on debounced input, removing the need for pages to handle Enter-to-search individually.

- [ ] **Step 3: Update IP Index to use debounced search**

Remove the `@keyup.enter="search"` handler. The FilterBar's debounced emit handles it.

- [ ] **Step 4: Run tests, commit**

```bash
npx vitest run tests/js/Components/UI/FilterBar.spec.js tests/js/Pages/Admin/Ips/Index.spec.js
npx prettier --write resources/js/Components/UI/FilterBar.vue resources/js/Pages/Admin/Ips/Index.vue
git commit -m "fix: add FilterBar labels, debounced search, unify search behavior"
```

---

### Task 25: Convert Content Pages Index to semantic table

**Fixes:** UI P2 (div-based table)

**Files:**
- Modify: `resources/js/Pages/Admin/Content/Pages/Index.vue`
- Test: `tests/js/Pages/Admin/Content/Pages/Index.spec.js`

- [ ] **Step 1: Rewrite to use DataTable or semantic `<table>`**

Replace the div-based table with either DataTable (preferred for consistency) or semantic `<table>/<thead>/<tbody>/<tr>/<td>` elements.

- [ ] **Step 2: Run tests, commit**

```bash
npx vitest run tests/js/Pages/Admin/Content/
npx prettier --write resources/js/Pages/Admin/Content/Pages/Index.vue
git commit -m "fix: convert Content Pages index to semantic table for accessibility"
```

---

## Phase 6: Frontend Polish

### Task 26: MarkdownEditor improvements

**Fixes:** UI P2 (regex converters, window.prompt for links)

**Files:**
- Modify: `resources/js/Components/UI/MarkdownEditor.vue`
- Modify: `package.json` — add `marked` and `turndown` dependencies
- Test: `tests/js/Components/UI/MarkdownEditor.spec.js`

- [ ] **Step 1: Install proper Markdown libraries**

```bash
npm install marked turndown
```

- [ ] **Step 2: Replace regex converters**

Replace `markdownToHtml()` with `marked.parse()` and `htmlToMarkdown()` with `new TurndownService().turndown()`.

- [ ] **Step 3: Replace window.prompt for link insertion**

Add an inline popover/mini-form for URL input instead of `window.prompt()`. Use a small positioned div with an input field and submit button.

- [ ] **Step 4: Run tests, commit**

```bash
npx vitest run tests/js/Components/UI/MarkdownEditor.spec.js
npx eslint resources/js/Components/UI/MarkdownEditor.vue
npx prettier --write resources/js/Components/UI/MarkdownEditor.vue
git commit -m "fix: replace regex Markdown converters with marked/turndown, inline link input"
```

---

### Task 27: Miscellaneous P2 UI fixes

**Fixes:** Multiple P2 and P3 items

**Files:**
- Modify: `resources/js/Pages/Auth/Login.vue:186` — replace `text-red-500` with `text-[var(--color-danger)]`
- Modify: `resources/js/Pages/Admin/Ips/Index.vue` — add "Add IP" button linking to create page
- Modify: `resources/js/Components/UI/StatusPill.vue` — add `'muted'` to validator
- Modify: `resources/js/Components/UI/DataTable.vue` — fix `role="link"` to `role="row"`
- Modify: `resources/js/Pages/Admin/Settings/Theme.vue` — add `aria-label` to range inputs and preset buttons, replace `text-white` with `text-[var(--color-accent-text)]`
- Modify: `resources/js/Pages/Admin/Ips/Create.vue` — replace `text-white` with `text-[var(--color-accent-text)]`
- Modify: `resources/js/Components/UI/Pagination.vue` — add `aria-label` to prev/next links
- Modify: `resources/js/Pages/Admin/Switches/Ports/Show.vue` — add `aria-label` to port nav links
- Modify: inline section headers to use `SectionHeader` component in: `Ips/Index.vue`, `Switches/Index.vue`, `Dhcp/Index.vue`, `Switches/Create.vue`

- [ ] **Step 1: Fix Login.vue hardcoded color**

Change `text-red-500` to `text-[var(--color-danger)]`.

- [ ] **Step 2: Add "Add IP" button to IP Index**

Add a button/Link in the header next to the page title:
```html
<Link :href="route('admin.ips.create')" class="..." data-testid="action-create-ip">
    Add IP Address
</Link>
```

- [ ] **Step 3: Add 'muted' to StatusPill validator**

```javascript
validator: (v) => ['success', 'danger', 'warning', 'info', 'neutral', 'muted'].includes(v),
```

Add muted styling:
```javascript
muted: 'bg-[var(--color-text-muted)]/10 text-[var(--color-text-muted)]',
```

- [ ] **Step 4: Fix DataTable row role**

Change `role="link"` to remove the role attribute entirely (let the native `<tr>` semantics apply), or use `role="row"`.

- [ ] **Step 5: Fix theme/accent button text colors**

In Theme.vue, Create.vue, and other files using `text-white` on accent buttons, change to `text-[var(--color-accent-text)]`.

- [ ] **Step 6: Add aria-labels to pagination and port nav**

In `Pagination.vue`, add `aria-label="Previous page"` and `aria-label="Next page"` to the prev/next links.

In `Ports/Show.vue`, add descriptive aria-labels to port navigation arrows.

- [ ] **Step 7: Replace inline section headers with SectionHeader component**

In each page that manually creates section headers, import and use the `SectionHeader` component instead.

- [ ] **Step 8: Run all tests, linting, formatting, commit**

```bash
npx vitest run
npx eslint resources/js/
npx prettier --check resources/js/
vendor/bin/pint --dirty --format agent
git commit -m "fix: miscellaneous P2/P3 UI fixes (colors, ARIA, StatusPill, section headers)"
```

---

### Task 28: P3 cleanup and dead code removal

**Fixes:** Remaining P3 items from UI audit

**Files:**
- Modify: `resources/js/Pages/Admin/Ips/Show.vue` — remove dead `togglePort` function
- Modify: `resources/js/Pages/Admin/Switches/Create.vue` — remove `reactive()` wrapper around `useForm`
- Modify: `resources/js/Components/Admin/GlobalSearch.vue` — move `debounceTimer` to `ref()`
- Modify: `resources/js/Pages/Account/Settings.vue` — standardize to pixel-based font sizes and theme border radius
- Modify: `resources/js/Pages/Admin/Users/Show.vue` — change `<a>` Edit button to Inertia `<Link>`
- Modify: `resources/js/Pages/Admin/Users/Edit.vue` — change cancel `<a>` to Inertia `<Link>`

- [ ] **Step 1: Remove dead code**

- Delete unused `togglePort()` in IP Show
- Remove `reactive()` wrapping `useForm()` in Switches/Create
- Move `debounceTimer` from module-level `let` to `ref()` inside setup in GlobalSearch

- [ ] **Step 2: Fix navigation patterns**

- Change `<a>` tags to Inertia `<Link>` components in Users/Show (Edit button) and Users/Edit (Cancel button)

- [ ] **Step 3: Standardize Account Settings styling**

Update `Account/Settings.vue` to use the same border-radius (`rounded-md`), font sizes (`text-[13px]` body, `text-[32px]` heading), and theme variables as the rest of the admin.

- [ ] **Step 4: Run all tests, linting, commit**

```bash
npx vitest run
npx eslint resources/js/
npx prettier --check resources/js/
git commit -m "chore: P3 cleanup — dead code removal, navigation fixes, style consistency"
```

---

## Verification

After all tasks are complete:

- [ ] **Run full PHP test suite:** `php artisan test --compact`
- [ ] **Run full JS test suite:** `npx vitest run --coverage`
- [ ] **Run PHPStan:** `vendor/bin/phpstan analyse`
- [ ] **Run Pint:** `vendor/bin/pint --format agent`
- [ ] **Run ESLint:** `npx eslint resources/js/`
- [ ] **Run Prettier:** `npx prettier --check resources/js/ resources/css/`
- [ ] **Build:** `npm run build`
- [ ] **Manual browser smoke test** of key pages: Dashboard, Users, IPs, Switches, Content, Portal Dashboard
