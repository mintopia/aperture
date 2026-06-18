# Managed Network Ranges Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add admin-configurable IPv4/IPv6 network ranges so Aperture only manages IPs within those ranges — preventing remote/VPN sessions from polluting user IP records.

**Architecture:** A `NetworkRangeService` checks IPs against CIDR ranges stored in `Setting`. `User::addIp()` calls this service as a gate — returning `null` for unmanaged IPs. A new Network Settings page lets admins configure ranges and houses the relocated DNS filter default toggle.

**Tech Stack:** Laravel 12, PHP 8.5, Inertia.js, Vue 3 (Composition API), Tailwind CSS 4, PHPUnit, Vitest

**Spec:** `docs/superpowers/specs/2026-04-23-managed-network-ranges-design.md`

---

## File Structure

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `app/Services/NetworkRangeService.php` | CIDR matching + setting cache |
| Create | `app/Http/Controllers/Admin/NetworkSettingsController.php` | Network settings page backend |
| Create | `resources/js/Pages/Admin/Settings/Network.vue` | Network settings page frontend |
| Create | `tests/Unit/Services/NetworkRangeServiceTest.php` | Unit tests for CIDR matching |
| Create | `tests/Feature/Admin/NetworkSettingsControllerTest.php` | Feature tests for settings page |
| Create | `tests/js/Pages/Admin/Settings/Network.spec.js` | Vitest tests for settings page |
| Modify | `app/Models/User.php` | `addIp()` return type → `?IpAddress`, add guard |
| Modify | `app/Providers/AppServiceProvider.php` | Register `NetworkRangeService` as scoped |
| Modify | `app/Http/Controllers/Portal/DashboardController.php` | Handle null from `addIp()` |
| Modify | `app/Http/Controllers/CaptivePortalController.php` | Handle null from `addIp()` |
| Modify | `app/Http/Controllers/PortalController.php` | Handle null from `addIp()` (3 methods) |
| Modify | `app/Jobs/ScanNetworkDevices.php` | Handle null from `addIp()` |
| Modify | `app/Http/Controllers/Admin/GeneralSettingsController.php` | Remove DNS filter default |
| Modify | `resources/js/Pages/Admin/Content/Settings.vue` | Remove DNS filter default section |
| Modify | `resources/js/Components/Admin/Sidebar.vue` | Add Network nav item |
| Modify | `routes/web.php` | Add network settings routes |

---

### Task 1: NetworkRangeService

**Files:**
- Create: `app/Services/NetworkRangeService.php`
- Create: `tests/Unit/Services/NetworkRangeServiceTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Context:** This is the core service that determines whether an IP address falls within admin-configured managed network ranges. It reads CIDR ranges from `Setting::get()` (a simple key-value store model with static `get(code, default)` and `set(code, name, value)` methods). The service is registered as `scoped` (not `singleton`) because the project uses Laravel Octane — scoped gives per-request caching.

When a setting doesn't exist in the database (fresh install), the service defaults to `['0.0.0.0/0']` for IPv4 and `['::/0']` for IPv6 — matching current behavior where all IPs are managed. When a setting exists but contains an empty JSON array `'[]'`, no IPs are managed (deny all).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/NetworkRangeServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Setting;
use App\Services\NetworkRangeService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetworkRangeServiceTest extends TestCase
{
    private NetworkRangeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NetworkRangeService;
    }

    #[Test]
    public function it_allows_ipv4_within_configured_range(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->assertTrue($this->service->isManaged('10.0.0.1'));
        $this->assertTrue($this->service->isManaged('10.255.255.255'));
    }

    #[Test]
    public function it_rejects_ipv4_outside_configured_range(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->assertFalse($this->service->isManaged('192.168.1.1'));
        $this->assertFalse($this->service->isManaged('172.16.0.1'));
    }

    #[Test]
    public function it_allows_ipv6_within_configured_range(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fc00::/7']));

        $this->assertTrue($this->service->isManaged('fd00::1'));
        $this->assertTrue($this->service->isManaged('fc00::abcd'));
    }

    #[Test]
    public function it_rejects_ipv6_outside_configured_range(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fc00::/7']));

        $this->assertFalse($this->service->isManaged('2001:db8::1'));
    }

    #[Test]
    public function it_matches_multiple_ranges(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]));

        $this->assertTrue($this->service->isManaged('10.1.2.3'));
        $this->assertTrue($this->service->isManaged('172.20.0.1'));
        $this->assertTrue($this->service->isManaged('192.168.1.1'));
        $this->assertFalse($this->service->isManaged('8.8.8.8'));
    }

    #[Test]
    public function it_denies_all_when_ranges_are_empty(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode([]));

        $this->assertFalse($this->service->isManaged('10.0.0.1'));
        $this->assertFalse($this->service->isManaged('fd00::1'));
    }

    #[Test]
    public function it_defaults_to_allow_all_when_no_settings_exist(): void
    {
        // No Setting::set calls — settings don't exist in DB

        $this->assertTrue($this->service->isManaged('10.0.0.1'));
        $this->assertTrue($this->service->isManaged('192.168.1.1'));
        $this->assertTrue($this->service->isManaged('8.8.8.8'));
        $this->assertTrue($this->service->isManaged('fd00::1'));
        $this->assertTrue($this->service->isManaged('2001:db8::1'));
    }

    #[Test]
    public function it_handles_slash_32_single_host(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.1/32']));

        $this->assertTrue($this->service->isManaged('10.0.0.1'));
        $this->assertFalse($this->service->isManaged('10.0.0.2'));
    }

    #[Test]
    public function it_handles_slash_128_single_host(): void
    {
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fd00::1/128']));

        $this->assertTrue($this->service->isManaged('fd00::1'));
        $this->assertFalse($this->service->isManaged('fd00::2'));
    }

    #[Test]
    public function it_returns_false_for_invalid_ip(): void
    {
        $this->assertFalse($this->service->isManaged('not-an-ip'));
        $this->assertFalse($this->service->isManaged(''));
    }

    #[Test]
    public function it_caches_ranges_within_same_instance(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->assertTrue($this->service->isManaged('10.0.0.1'));

        // Change setting — same instance should use cached value
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));

        $this->assertTrue($this->service->isManaged('10.0.0.2'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=NetworkRangeServiceTest`

Expected: All tests fail with "Class 'App\Services\NetworkRangeService' not found"

- [ ] **Step 3: Create NetworkRangeService**

Create `app/Services/NetworkRangeService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

class NetworkRangeService
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function isManaged(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        $isV6 = str_contains($ip, ':');
        $ranges = $this->getRanges($isV6);

        foreach ($ranges as $cidr) {
            if ($this->ipInCidr($binary, $cidr, $isV6)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function getRanges(bool $isV6): array
    {
        $key = $isV6 ? 'network.managed_ranges_v6' : 'network.managed_ranges_v4';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $raw = Setting::get($key);
        $default = $isV6 ? ['::/0'] : ['0.0.0.0/0'];

        /** @var list<string> $ranges */
        $ranges = $raw !== null ? json_decode((string) $raw, true) : $default;

        $this->cache[$key] = is_array($ranges) ? $ranges : $default;

        return $this->cache[$key];
    }

    private function ipInCidr(string $ipBinary, string $cidr, bool $isV6): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$subnet, $prefixStr] = $parts;
        $prefix = (int) $prefixStr;

        $subnetBinary = @inet_pton($subnet);
        if ($subnetBinary === false) {
            return false;
        }

        $expectedLength = $isV6 ? 16 : 4;
        if (strlen($ipBinary) !== $expectedLength || strlen($subnetBinary) !== $expectedLength) {
            return false;
        }

        $mask = $this->buildMask($prefix, $expectedLength);

        return ($ipBinary & $mask) === ($subnetBinary & $mask);
    }

    private function buildMask(int $prefix, int $bytes): string
    {
        $mask = '';
        $remaining = $prefix;

        for ($i = 0; $i < $bytes; $i++) {
            if ($remaining >= 8) {
                $mask .= chr(255);
                $remaining -= 8;
            } elseif ($remaining > 0) {
                $mask .= chr(256 - (1 << (8 - $remaining)));
                $remaining = 0;
            } else {
                $mask .= chr(0);
            }
        }

        return $mask;
    }
}
```

- [ ] **Step 4: Register as scoped in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add the import and registration.

Add import:
```php
use App\Services\NetworkRangeService;
```

In the `register()` method, add after the existing `$this->app->scoped(ThemeService::class);` line:
```php
$this->app->scoped(NetworkRangeService::class);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=NetworkRangeServiceTest`

Expected: All 11 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Services/NetworkRangeService.php tests/Unit/Services/NetworkRangeServiceTest.php app/Providers/AppServiceProvider.php
git commit -m "feat: add NetworkRangeService for managed IP range checking"
```

---

### Task 2: User::addIp() Guard

**Files:**
- Modify: `app/Models/User.php:165-188`
- Create: `tests/Feature/UserAddIpManagedRangeTest.php`

**Context:** `User::addIp(string $clientIp): IpAddress` is at line 165 of `app/Models/User.php`. It creates/finds an `IpAddress`, links it to the user via `UserIpAddress`, and calls `IpPolicyService::applyUserPolicy()`. We change the return type to `?IpAddress` and add a guard at the top that checks `NetworkRangeService::isManaged()`.

When no settings exist in the database (fresh install, test environment), the service defaults to `['0.0.0.0/0']` + `['::/0']` — all IPs are managed. This means **all existing tests continue to pass without modification**.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/UserAddIpManagedRangeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserIpAddress;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserAddIpManagedRangeTest extends TestCase
{
    #[Test]
    public function add_ip_returns_ip_when_in_managed_range(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $user = User::factory()->create();
        $result = $user->addIp('10.0.0.1');

        $this->assertInstanceOf(IpAddress::class, $result);
        $this->assertSame('10.0.0.1', $result->address);
        $this->assertDatabaseHas('ip_addresses', ['address' => '10.0.0.1']);
        $this->assertDatabaseHas('user_ip_addresses', ['user_id' => $user->id]);
    }

    #[Test]
    public function add_ip_returns_null_when_outside_managed_range(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $user = User::factory()->create();
        $result = $user->addIp('192.168.1.1');

        $this->assertNull($result);
        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.168.1.1']);
        $this->assertDatabaseMissing('user_ip_addresses', ['user_id' => $user->id]);
    }

    #[Test]
    public function add_ip_manages_all_when_no_settings_exist(): void
    {
        // No settings in DB — defaults to 0.0.0.0/0 and ::/0
        $user = User::factory()->create();
        $result = $user->addIp('203.0.113.50');

        $this->assertInstanceOf(IpAddress::class, $result);
        $this->assertSame('203.0.113.50', $result->address);
    }

    #[Test]
    public function add_ip_denies_all_when_ranges_empty(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode([]));

        $user = User::factory()->create();
        $result = $user->addIp('10.0.0.1');

        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=UserAddIpManagedRangeTest`

Expected: Tests that expect `null` fail because `addIp()` currently always returns `IpAddress`.

- [ ] **Step 3: Modify User::addIp()**

In `app/Models/User.php`, add the import at the top with the other imports:

```php
use App\Services\NetworkRangeService;
```

Replace the `addIp` method (lines 165-188) with:

```php
    public function addIp(string $clientIp): ?IpAddress
    {
        if (! app(NetworkRangeService::class)->isManaged($clientIp)) {
            return null;
        }

        $ip = IpAddress::whereAddress($clientIp)->first();
        if (! $ip) {
            $ip = new IpAddress;
            $ip->address = $clientIp;
            $ip->last_seen_at = now();
            $ip->save();
        }

        $userIp = $this->ips()->whereIpAddressId($ip->id)->first();
        if (! $userIp) {
            $userIp = new UserIpAddress;
            $userIp->user()->associate($this);
            $userIp->ip()->associate($ip);
        }

        $userIp->last_seen_at = now();
        $userIp->save();

        app(IpPolicyService::class)->applyUserPolicy($this, $ip);

        return $ip;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=UserAddIpManagedRangeTest`

Expected: All 4 tests pass.

- [ ] **Step 5: Verify existing tests still pass**

Run: `php artisan test --compact`

Expected: All existing tests pass (default settings allow all IPs).

- [ ] **Step 6: Commit**

```bash
git add app/Models/User.php tests/Feature/UserAddIpManagedRangeTest.php
git commit -m "feat: gate User::addIp() on managed network ranges"
```

---

### Task 3: Call Site Updates

**Files:**
- Modify: `app/Http/Controllers/Portal/DashboardController.php:23-56`
- Modify: `app/Http/Controllers/CaptivePortalController.php:92-105`
- Modify: `app/Http/Controllers/PortalController.php:17-71`
- Modify: `app/Jobs/ScanNetworkDevices.php:112-133`

**Context:** Six places call `User::addIp()` and expect a non-null `IpAddress` back. Now that `addIp()` can return `null`, each call site needs a null check. The changes are minimal — null-safe operators (`?->`) and early returns.

**Important:** Existing tests use the default settings (no `network.*` settings in DB), which means `NetworkRangeService` defaults to `0.0.0.0/0` / `::/0` — all IPs managed. Existing tests continue to pass. The new tests below specifically set restrictive ranges to exercise the null paths.

- [ ] **Step 1: Write failing tests for DashboardController null path**

Add to the existing `tests/Feature/Portal/DashboardControllerTest.php` (or create it if it doesn't exist). Find the existing test file first — the test class likely has a test like `test_dashboard_loads` or similar. Add this test to the same class:

```php
#[Test]
public function dashboard_loads_without_ip_data_when_outside_managed_range(): void
{
    Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->get(route('home'));

    $response->assertOk();
    $response->assertInertiaHas('blockContext.currentIpv4', null);
}
```

Add the `Setting` import if not already present:
```php
use App\Models\Setting;
```

- [ ] **Step 2: Write failing tests for CaptivePortalController null path**

Find the existing `tests/Feature/CaptivePortalControllerTest.php`. Add:

```php
#[Test]
public function captive_portal_poll_completes_auth_when_ip_outside_managed_range(): void
{
    Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

    $user = User::factory()->create();

    Cache::put('device_flow:test-code', [
        'status' => 'pending',
        'ip' => '10.0.0.1',
    ], now()->addMinutes(5));

    // Mock the auth provider to return a successful result
    $this->mock(AuthProviderInterface::class, function ($mock) {
        $mock->shouldReceive('pollDeviceFlow')->andReturn(new AuthResult('token', 'refresh', 3600));
        $mock->shouldReceive('getUserInfo')->andReturn(new UserInfo('test@example.com', 'Test User'));
    });

    $this->mock(DeviceFlowUserService::class, function ($mock) use ($user) {
        $mock->shouldReceive('findOrCreateFromDeviceFlow')->andReturn($user);
    });

    $response = $this->postJson(route('captive.poll', 'test-code'));

    $response->assertOk();
    $response->assertJson(['status' => 'complete']);

    // IP should NOT be created since it's outside managed range
    $this->assertDatabaseMissing('ip_addresses', ['address' => '10.0.0.1']);
}
```

**Note:** The exact mock setup depends on the existing test patterns in this file. Check the existing tests for how `AuthProviderInterface`, `DeviceFlowUserService`, and `Cache` are used, and follow the same pattern. The key assertion is that the auth flow completes (`status: complete`) but no IP record is created.

- [ ] **Step 3: Write failing tests for PortalController null paths**

Find the existing `tests/Feature/PortalControllerTest.php`. Add:

```php
#[Test]
public function portal_status_returns_null_ip_when_outside_managed_range(): void
{
    Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->getJson(route('portal.status'));

    $response->assertOk();
    $response->assertJson([
        'ip' => null,
        'internetEnabled' => false,
    ]);
}
```

- [ ] **Step 4: Write failing test for ScanNetworkDevices null path**

Find the existing test file for `ScanNetworkDevices` (likely `tests/Feature/Jobs/ScanNetworkDevicesTest.php`). Add:

```php
#[Test]
public function scan_skips_ip_outside_managed_range_for_user_with_mac(): void
{
    Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['172.16.0.0/12']));

    $user = User::factory()->create();
    $mac = MacAddress::factory()->create(['user_id' => $user->id]);

    // The job's autoAllowIp should skip this IP
    // Invoke the private method via reflection or test through the job's handle method
    // Follow existing test patterns in this file

    $this->assertDatabaseMissing('user_ip_addresses', ['user_id' => $user->id]);
}
```

**Note:** Check how existing tests in this file invoke `autoAllowIp` — it's a private method, so existing tests likely either test through `handle()` or use reflection. Follow the same approach.

- [ ] **Step 5: Run tests to verify they fail**

Run: `php artisan test --compact --filter="dashboard_loads_without_ip_data|captive_portal_poll_completes|portal_status_returns_null|scan_skips_ip"`

Expected: Tests fail because call sites don't handle null yet.

- [ ] **Step 6: Update DashboardController**

In `app/Http/Controllers/Portal/DashboardController.php`, modify the `index()` method. Replace the section from the `addIp` call through the Inertia render's `blockContext` array. The key changes:

```php
$ip = $user->addIp((string) $request->getClientIp());

$ipv6 = $ip !== null ? $this->resolveIpv6ForMac($ip->mac) : null;

return Inertia::render('Portal/Dashboard', [
    'blocks' => $blocks,
    'blockContext' => [
        'currentIpv4' => $ip?->address,
        'currentIpv6' => $ipv6,
        'internetEnabled' => (bool) ($ip?->internet_enabled ?? false),
        'internetBlocked' => (bool) $user->internet_blocked,
        'blockedMessage' => Setting::get('portal.blocked_message', ''),
        'macAddress' => $ip?->mac,
        'dnsFilteringEnabled' => (bool) $user->dns_filtering_enabled,
        'user' => [
            'name' => $user->nickname ?? '',
            'params' => $user->parameters()->pluck('value', 'key')->toArray(),
        ],
    ],
    // ... rest unchanged
]);
```

The only changes are:
- `$this->resolveIpv6ForMac($ip->mac)` → guarded with `$ip !== null ?` ternary
- `$ip->address` → `$ip?->address`
- `(bool) $ip->internet_enabled` → `(bool) ($ip?->internet_enabled ?? false)`
- `$ip->mac` → `$ip?->mac`

- [ ] **Step 7: Update CaptivePortalController**

In `app/Http/Controllers/CaptivePortalController.php`, in the `poll()` method, change:

```php
$ip = $user->addIp($flowData['ip'] ?? $request->getClientIp() ?? '0.0.0.0');
if (! $user->internet_blocked) {
    $actionService->enableInternet($ip);
}
```

To:

```php
$ip = $user->addIp($flowData['ip'] ?? $request->getClientIp() ?? '0.0.0.0');
if ($ip !== null && ! $user->internet_blocked) {
    $actionService->enableInternet($ip);
}
```

- [ ] **Step 8: Update PortalController**

In `app/Http/Controllers/PortalController.php`:

**index() method** — change:
```php
$ip = $user->addIp($clientIp);
```
No change needed to the return — `$ip` is passed directly to the view as-is. The Blade view receives `null` and should handle it gracefully. If the view accesses `$ip->address`, it needs `$ip?->address`. Check the `portal` Blade view and update any `$ip->` references to use null-safe operators.

**status() method** — change the return block:
```php
$ip = $user->addIp($clientIp);

return response()->json((object) [
    'ip' => $ip?->address,
    'internetEnabled' => (bool) ($ip?->internet_enabled ?? false),
]);
```

**ipv6() method** — change the return block:
```php
$ip = $user->addIp($ipv6);

return response()->json((object) [
    'ip' => $ip?->address,
    'internetEnabled' => (bool) ($ip?->internet_enabled ?? false),
]);
```

- [ ] **Step 9: Update ScanNetworkDevices**

In `app/Jobs/ScanNetworkDevices.php`, in `autoAllowIp()`, change:

```php
if ($macAddress->user_id !== null && $macAddress->user) {
    $ip = $macAddress->user->addIp($ipAddress);
} else {
```

To:

```php
if ($macAddress->user_id !== null && $macAddress->user) {
    $ip = $macAddress->user->addIp($ipAddress);
    if ($ip === null) {
        return;
    }
} else {
```

The `else` branch (MAC without a user) is unaffected — it creates IPs directly without the managed range check, which is correct for network device scanning.

- [ ] **Step 10: Run tests to verify they pass**

Run: `php artisan test --compact --filter="dashboard_loads_without_ip_data|captive_portal_poll_completes|portal_status_returns_null|scan_skips_ip"`

Expected: All new tests pass.

- [ ] **Step 11: Verify existing tests still pass**

Run: `php artisan test --compact`

Expected: All tests pass (defaults allow all IPs).

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/Portal/DashboardController.php app/Http/Controllers/CaptivePortalController.php app/Http/Controllers/PortalController.php app/Jobs/ScanNetworkDevices.php tests/
git commit -m "feat: handle null from addIp() at all call sites"
```

---

### Task 4: NetworkSettingsController + Routes

**Files:**
- Create: `app/Http/Controllers/Admin/NetworkSettingsController.php`
- Create: `tests/Feature/Admin/NetworkSettingsControllerTest.php`
- Modify: `routes/web.php`

**Context:** Follow the existing settings controller pattern (`GeneralSettingsController`, `DnsDetectionSettingsController`). The controller has `show()` and `update()` methods. Settings are stored via `Setting::set(code, name, value)` and read via `Setting::get(code, default)`. The value stored is a JSON string of CIDR arrays.

Routes live inside the admin group in `routes/web.php` (around line 138). The group adds `admin.` prefix to route names automatically. So `->name('settings.network')` becomes `admin.settings.network`.

CIDR validation uses `inet_pton()` to verify the IP portion and checks prefix length (0-32 for v4, 0-128 for v6).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/NetworkSettingsControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NetworkSettingsControllerTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function network_settings_page_loads(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.settings.network'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Settings/Network'));
    }

    #[Test]
    public function network_settings_page_shows_defaults_when_no_settings_exist(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.settings.network'));

        $response->assertOk();
        $response->assertInertiaHas('settings.managed_ranges_v4', "0.0.0.0/0");
        $response->assertInertiaHas('settings.managed_ranges_v6', "::/0");
        $response->assertInertiaHas('settings.dns_filter_default', false);
    }

    #[Test]
    public function network_settings_page_shows_stored_values(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8', '172.16.0.0/12']));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode(['fc00::/7']));
        Setting::set('network.dns_filter_default', 'DNS Filter Default', '1');

        $response = $this->actingAs($this->admin)->get(route('admin.settings.network'));

        $response->assertOk();
        $response->assertInertiaHas('settings.managed_ranges_v4', "10.0.0.0/8\n172.16.0.0/12");
        $response->assertInertiaHas('settings.managed_ranges_v6', "fc00::/7");
        $response->assertInertiaHas('settings.dns_filter_default', true);
    }

    #[Test]
    public function update_saves_valid_ipv4_ranges(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => "10.0.0.0/8\n192.168.0.0/16",
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $stored = json_decode((string) Setting::get('network.managed_ranges_v4'), true);
        $this->assertSame(['10.0.0.0/8', '192.168.0.0/16'], $stored);
    }

    #[Test]
    public function update_saves_valid_ipv6_ranges(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => "fc00::/7\nfe80::/10",
            'dns_filter_default' => false,
        ]);

        $response->assertRedirect();

        $stored = json_decode((string) Setting::get('network.managed_ranges_v6'), true);
        $this->assertSame(['fc00::/7', 'fe80::/10'], $stored);
    }

    #[Test]
    public function update_saves_empty_ranges(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '',
            'managed_ranges_v6' => '',
            'dns_filter_default' => false,
        ]);

        $response->assertRedirect();

        $stored = json_decode((string) Setting::get('network.managed_ranges_v4'), true);
        $this->assertSame([], $stored);
    }

    #[Test]
    public function update_rejects_invalid_ipv4_cidr(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => "10.0.0.0/8\nnot-a-cidr",
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v4');
    }

    #[Test]
    public function update_rejects_invalid_ipv6_cidr(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => 'zzz::qqq/64',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v6');
    }

    #[Test]
    public function update_rejects_ipv6_in_ipv4_field(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => 'fc00::/7',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v4');
    }

    #[Test]
    public function update_rejects_prefix_out_of_range(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '10.0.0.0/33',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ]);

        $response->assertSessionHasErrors('managed_ranges_v4');
    }

    #[Test]
    public function update_saves_dns_filter_default(): void
    {
        $response = $this->actingAs($this->admin)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => true,
        ]);

        $response->assertRedirect();
        $this->assertSame('1', Setting::get('network.dns_filter_default'));
    }

    #[Test]
    public function non_admin_cannot_access_network_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.settings.network'))->assertForbidden();
        $this->actingAs($user)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
        ])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=NetworkSettingsControllerTest`

Expected: Tests fail with route not found errors.

- [ ] **Step 3: Add routes**

In `routes/web.php`, find the settings routes section (around line 138). Add the network settings routes alongside the existing ones. Find the line:

```php
Route::get('/settings/dns-detection', [DnsDetectionSettingsController::class, 'show'])->name('settings.dns-detection');
Route::put('/settings/dns-detection', [DnsDetectionSettingsController::class, 'update'])->name('settings.dns-detection.update');
```

Add immediately after:

```php
Route::get('/settings/network', [NetworkSettingsController::class, 'show'])->name('settings.network');
Route::put('/settings/network', [NetworkSettingsController::class, 'update'])->name('settings.network.update');
```

Add the import at the top of `routes/web.php` with the other controller imports:

```php
use App\Http\Controllers\Admin\NetworkSettingsController;
```

- [ ] **Step 4: Create NetworkSettingsController**

Create `app/Http/Controllers/Admin/NetworkSettingsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NetworkSettingsController extends Controller
{
    public function show(): Response
    {
        $v4Raw = Setting::get('network.managed_ranges_v4');
        $v6Raw = Setting::get('network.managed_ranges_v6');

        /** @var list<string> $v4Ranges */
        $v4Ranges = $v4Raw !== null ? json_decode((string) $v4Raw, true) : ['0.0.0.0/0'];
        /** @var list<string> $v6Ranges */
        $v6Ranges = $v6Raw !== null ? json_decode((string) $v6Raw, true) : ['::/0'];

        return Inertia::render('Admin/Settings/Network', [
            'settings' => [
                'managed_ranges_v4' => implode("\n", is_array($v4Ranges) ? $v4Ranges : []),
                'managed_ranges_v6' => implode("\n", is_array($v6Ranges) ? $v6Ranges : []),
                'dns_filter_default' => (bool) Setting::get('network.dns_filter_default', false),
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Services'],
                ['label' => 'Network'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'managed_ranges_v4' => ['nullable', 'string', $this->cidrValidationRule(4)],
            'managed_ranges_v6' => ['nullable', 'string', $this->cidrValidationRule(6)],
            'dns_filter_default' => 'boolean',
        ]);

        $v4Lines = $this->parseLines($validated['managed_ranges_v4'] ?? '');
        $v6Lines = $this->parseLines($validated['managed_ranges_v6'] ?? '');

        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode($v4Lines));
        Setting::set('network.managed_ranges_v6', 'Managed IPv6 Ranges', json_encode($v6Lines));
        Setting::set('network.dns_filter_default', 'DNS Filter Default', ($validated['dns_filter_default'] ?? false) ? '1' : '0');

        return back()->with('success', 'Network settings updated.');
    }

    /**
     * @return list<string>
     */
    private function parseLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  4|6  $family
     */
    private function cidrValidationRule(int $family): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($family): void {
            if ($value === null || $value === '') {
                return;
            }

            $lines = $this->parseLines((string) $value);
            foreach ($lines as $line) {
                if (! $this->isValidCidr($line, $family)) {
                    $label = $family === 4 ? 'IPv4' : 'IPv6';
                    $fail("Invalid {$label} CIDR notation: {$line}");

                    return;
                }
            }
        };
    }

    /**
     * @param  4|6  $family
     */
    private function isValidCidr(string $cidr, int $family): bool
    {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$ip, $prefixStr] = $parts;

        if (! is_numeric($prefixStr)) {
            return false;
        }

        $prefix = (int) $prefixStr;
        $maxPrefix = $family === 4 ? 32 : 128;

        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }

        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        $expectedLength = $family === 4 ? 4 : 16;

        return strlen($binary) === $expectedLength;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=NetworkSettingsControllerTest`

Expected: All 12 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/NetworkSettingsController.php tests/Feature/Admin/NetworkSettingsControllerTest.php routes/web.php
git commit -m "feat: add NetworkSettingsController with CIDR validation"
```

---

### Task 5: Network Settings Vue Page + Sidebar Navigation

**Files:**
- Create: `resources/js/Pages/Admin/Settings/Network.vue`
- Create: `tests/js/Pages/Admin/Settings/Network.spec.js`
- Modify: `resources/js/Components/Admin/Sidebar.vue`

**Context:** The settings page follows the same pattern as `Admin/Content/Settings.vue` and `Admin/Settings/DnsDetection.vue`. Uses `useForm` from Inertia for form state/submission. The `FormField` component accepts `label`, `name`, `required` (boolean), and `error` (string) props with a slot for the input.

The sidebar navigation is in `resources/js/Components/Admin/Sidebar.vue`. It has a `navGroups` array with groups: MANAGEMENT, SERVICES, CONTENT. The SERVICES group has items for Integrations, IPv6 Detection, and DNS Detection — all using `SettingsIcon`.

- [ ] **Step 1: Create Network.vue**

Create `resources/js/Pages/Admin/Settings/Network.vue`:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    managed_ranges_v4: props.settings?.managed_ranges_v4 ?? '',
    managed_ranges_v6: props.settings?.managed_ranges_v6 ?? '',
    dns_filter_default: props.settings?.dns_filter_default ?? false,
});

function submit() {
    form.put(route('admin.settings.network.update'));
}
</script>

<template>
    <div>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            Network Settings
        </h1>

        <form class="space-y-4" data-testid="network-settings-form" @submit.prevent="submit">
            <!-- Managed Network Ranges -->
            <h2
                data-testid="section-heading-ranges"
                class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
            >
                Managed Network Ranges
            </h2>

            <p class="text-[13px] text-[var(--color-text-secondary)]">
                Only IPs within these ranges will be linked to users and managed by Aperture. One CIDR per line.
            </p>

            <FormField label="IPv4 Ranges" name="managed_ranges_v4" :error="form.errors.managed_ranges_v4">
                <textarea
                    id="managed_ranges_v4"
                    v-model="form.managed_ranges_v4"
                    rows="4"
                    data-testid="input-managed-ranges-v4"
                    placeholder="e.g. 10.0.0.0/8"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="IPv6 Ranges" name="managed_ranges_v6" :error="form.errors.managed_ranges_v6">
                <textarea
                    id="managed_ranges_v6"
                    v-model="form.managed_ranges_v6"
                    rows="4"
                    data-testid="input-managed-ranges-v6"
                    placeholder="e.g. fc00::/7"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
                />
            </FormField>

            <!-- Network Defaults -->
            <h2
                data-testid="section-heading-defaults"
                class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
            >
                Network Defaults
            </h2>

            <FormField label="DNS Filtering Default" name="dns_filter_default">
                <div class="flex items-center gap-3">
                    <input
                        id="dns_filter_default"
                        v-model="form.dns_filter_default"
                        type="checkbox"
                        data-testid="toggle-dns-filter-default"
                        class="h-4 w-4 cursor-pointer rounded accent-[var(--color-primary)]"
                    />
                    <label for="dns_filter_default" class="cursor-pointer text-[13px] text-[var(--color-text)]">
                        Enable DNS filtering for new connections
                    </label>
                </div>
            </FormField>

            <button
                type="submit"
                data-testid="action-save"
                :disabled="form.processing"
                class="rounded-md border border-[var(--color-primary)] bg-[var(--color-primary)] px-4 py-[7px] text-[13px] font-semibold text-white"
            >
                Save Settings
            </button>
        </form>
    </div>
</template>
```

- [ ] **Step 2: Write Vitest tests**

Create `tests/js/Pages/Admin/Settings/Network.spec.js`:

```javascript
import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Network from '@/Pages/Admin/Settings/Network.vue';

const mockRoute = vi.fn((name) => `/mock/${name}`);
vi.stubGlobal('route', mockRoute);

const mockPut = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    useForm: (data) => ({
        ...data,
        put: mockPut,
        processing: false,
        errors: {},
    }),
    Link: {
        name: 'Link',
        template: '<a><slot /></a>',
    },
}));

function mountPage(settings = {}) {
    return mount(Network, {
        props: {
            settings: {
                managed_ranges_v4: '10.0.0.0/8',
                managed_ranges_v6: 'fc00::/7',
                dns_filter_default: false,
                ...settings,
            },
        },
        global: {
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                FormField: {
                    template: '<div :data-name="name"><label>{{ label }}</label><slot /></div>',
                    props: ['label', 'name', 'error', 'required'],
                },
            },
        },
    });
}

describe('Network Settings Page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders page title', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Network Settings');
    });

    it('renders managed ranges section', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="section-heading-ranges"]').text()).toBe('Managed Network Ranges');
    });

    it('renders ipv4 textarea with current values', () => {
        const wrapper = mountPage({ managed_ranges_v4: '10.0.0.0/8\n172.16.0.0/12' });
        const textarea = wrapper.find('[data-testid="input-managed-ranges-v4"]');
        expect(textarea.exists()).toBe(true);
    });

    it('renders ipv6 textarea', () => {
        const wrapper = mountPage({ managed_ranges_v6: 'fc00::/7' });
        const textarea = wrapper.find('[data-testid="input-managed-ranges-v6"]');
        expect(textarea.exists()).toBe(true);
    });

    it('renders dns filter default checkbox', () => {
        const wrapper = mountPage();
        const checkbox = wrapper.find('[data-testid="toggle-dns-filter-default"]');
        expect(checkbox.exists()).toBe(true);
    });

    it('renders network defaults section', () => {
        const wrapper = mountPage();
        expect(wrapper.find('[data-testid="section-heading-defaults"]').text()).toBe('Network Defaults');
    });

    it('renders save button', () => {
        const wrapper = mountPage();
        const button = wrapper.find('[data-testid="action-save"]');
        expect(button.exists()).toBe(true);
        expect(button.text()).toBe('Save Settings');
    });

    it('submits form to correct route', async () => {
        const wrapper = mountPage();
        await wrapper.find('[data-testid="network-settings-form"]').trigger('submit');
        expect(mockPut).toHaveBeenCalled();
    });
});
```

- [ ] **Step 3: Run Vitest tests to verify they pass**

Run: `npx vitest run tests/js/Pages/Admin/Settings/Network.spec.js`

Expected: All 8 tests pass.

- [ ] **Step 4: Add Network to sidebar navigation**

In `resources/js/Components/Admin/Sidebar.vue`, find the SERVICES group items array. It currently has:

```javascript
{ label: 'DNS Detection', href: route('admin.settings.dns-detection'), icon: SettingsIcon },
```

Add after that line:

```javascript
{ label: 'Network', href: route('admin.settings.network'), icon: SettingsIcon },
```

- [ ] **Step 5: Run all Vitest tests**

Run: `npx vitest run`

Expected: All JS tests pass (including any existing sidebar tests).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/Settings/Network.vue tests/js/Pages/Admin/Settings/Network.spec.js resources/js/Components/Admin/Sidebar.vue
git commit -m "feat: add Network settings page and sidebar navigation"
```

---

### Task 6: Remove DNS Filter Default from General Settings

**Files:**
- Modify: `app/Http/Controllers/Admin/GeneralSettingsController.php`
- Modify: `resources/js/Pages/Admin/Content/Settings.vue`
- Modify: existing tests for GeneralSettingsController (if any assert `dns_filtering_default`)

**Context:** The DNS filter default setting (`general.dns_filtering_default`) is being relocated from the General Settings page to the new Network Settings page (as `network.dns_filter_default`). Remove it from both the controller and the Vue page.

`GeneralSettingsController::show()` currently includes `'dns_filtering_default' => (bool) Setting::get('general.dns_filtering_default', false)` in the settings array. `update()` validates `'dns_filtering_default' => 'boolean'` and persists via `Setting::set('general.dns_filtering_default', ...)`.

The Vue page `Admin/Content/Settings.vue` has a "Network Defaults" section (lines ~67-81) with a checkbox for `dns_filtering_default`.

- [ ] **Step 1: Update GeneralSettingsController show()**

In `app/Http/Controllers/Admin/GeneralSettingsController.php`, in the `show()` method, remove this line from the settings array:

```php
'dns_filtering_default' => (bool) Setting::get('general.dns_filtering_default', false),
```

- [ ] **Step 2: Update GeneralSettingsController update()**

In the `update()` method, remove from validation rules:

```php
'dns_filtering_default' => 'boolean',
```

And remove the persistence line:

```php
Setting::set('general.dns_filtering_default', 'DNS Filtering Default', $validated['dns_filtering_default'] ? '1' : '0');
```

- [ ] **Step 3: Update Settings.vue**

In `resources/js/Pages/Admin/Content/Settings.vue`:

Remove `dns_filtering_default` from the `useForm` call:
```javascript
dns_filtering_default: props.settings?.dns_filtering_default ?? false,
```

Remove the entire "Network Defaults" section heading and the DNS filtering FormField from the template. This includes:
- The `<h2>` with `data-testid="section-heading-network"` and text "Network Defaults"
- The `<FormField label="DNS Filtering Default" ...>` block with its checkbox and hint text

- [ ] **Step 4: Update existing tests**

Search for tests that reference `dns_filtering_default` in the general settings context. Check:
- `tests/Feature/Admin/GeneralSettingsControllerTest.php` (if it exists)
- Any test that asserts `dns_filtering_default` in the general settings Inertia response

Remove or update those assertions. The setting now lives under `network.dns_filter_default` and is tested in `NetworkSettingsControllerTest`.

- [ ] **Step 5: Run affected tests**

Run: `php artisan test --compact --filter="GeneralSettings"`

And: `npx vitest run tests/js/Pages/Admin/Content/`

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/GeneralSettingsController.php resources/js/Pages/Admin/Content/Settings.vue tests/
git commit -m "refactor: move DNS filter default from General to Network settings"
```

---

### Task 7: Quality Checks

**Files:** All modified files from Tasks 1-6.

**Context:** The project requires: Laravel Pint (PHP formatting), PHPStan Level 8 (static analysis), ESLint (JS linting), Prettier (JS formatting). All must pass with zero errors.

- [ ] **Step 1: Run Laravel Pint**

Run: `vendor/bin/pint --dirty --format agent`

If any files are reformatted, review the changes and commit them.

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`

Expected: Zero errors. If there are errors:
- `mixed` type issues from `Setting::get()` → add `@var` annotations or explicit casts
- Nullable return type issues → ensure all call sites properly handle `?IpAddress`
- Fix any issues and re-run until clean.

- [ ] **Step 3: Run ESLint**

Run: `npm run lint`

Fix any issues: `npm run lint:fix`

- [ ] **Step 4: Run Prettier**

Run: `npm run format:check`

Fix any issues: `npm run format`

- [ ] **Step 5: Run full test suite**

Run: `php artisan test --compact`

And: `npx vitest run`

Expected: All tests pass.

- [ ] **Step 6: Commit any quality fixes**

```bash
git add -A
git commit -m "style: apply formatting and fix static analysis issues"
```
