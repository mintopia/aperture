# IPv6-to-MAC Linkage via Client IPv4

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a client submits their IPv6 address to `POST /ipv6`, link the IPv6 to the same MAC address that's associated with the client's IPv4 request IP — because both addresses come from the same device.

**Architecture:** The `PortalController::ipv6()` method already validates a JWT containing the client's IPv6 and calls `$user->addIp($ipv6)`. After that call, we look up the `IpAddress` record for the client's IPv4 (`$request->getClientIp()`), get its most recent MAC via `currentMac()`, and attach that MAC to the IPv6 `IpAddress` via the `ip_address_mac_address` pivot with `source='ipv6_detection'`. If the link already exists, we update `last_seen_at`. This reuses the same attach/update pattern as `ScanNetworkDevices::linkIpMac()`.

**Tech Stack:** Laravel 11 (PHP 8.3), PHPUnit, Mockery, SQLite for tests

---

## File Map

| Component | Files Modified | Files Created |
|---|---|---|
| Controller | `app/Http/Controllers/PortalController.php` (ipv6 method) | — |
| Tests | `tests/Feature/PortalControllerTest.php` | — |

---

### Task 1: IPv6-to-MAC Linkage in PortalController

**Files:**
- Modify: `app/Http/Controllers/PortalController.php` (ipv6 method, ~line 51)
- Modify: `tests/Feature/PortalControllerTest.php`

- [ ] **Step 1: Write failing test — IPv6 submission links MAC from client IPv4**

Add to `tests/Feature/PortalControllerTest.php`:

```php
use App\Models\MacAddress;

public function test_ipv6_links_mac_from_client_ipv4(): void
{
    Queue::fake();
    $user = User::factory()->create(['internet_blocked' => false]);

    // Set up client IPv4 with a known MAC
    $clientIp = IpAddress::factory()->create(['address' => '127.0.0.1']);
    $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    $clientIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->with('valid.jwt.token', 'https://ipv6.example.com/.well-known/jwks.json')
        ->andReturn('2001:db8::1');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    $this->actingAs($user)->postJson('/ipv6', ['token' => 'valid.jwt.token']);

    $ipv6Record = IpAddress::whereAddress('2001:db8::1')->first();
    $this->assertNotNull($ipv6Record);
    $this->assertDatabaseHas('ip_address_mac_address', [
        'ip_address_id' => $ipv6Record->id,
        'mac_address_id' => $mac->id,
        'source' => 'ipv6_detection',
    ]);
}
```

Add `use App\Models\MacAddress;` to the imports at the top of the test file (after the existing `use App\Models\IpAddress;` line).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_links_mac_from_client_ipv4`
Expected: FAIL — no row in `ip_address_mac_address` linking the IPv6 to the MAC.

- [ ] **Step 3: Implement MAC linkage in PortalController::ipv6()**

In `app/Http/Controllers/PortalController.php`, add import at top:

```php
use App\Models\IpAddress;
use App\Models\AuditLog;
```

Replace the `ipv6` method with:

```php
public function ipv6(Request $request, Ipv6JwtService $jwtService): JsonResponse
{
    $request->validate(['token' => 'required|string']);

    $dbConfig = IntegrationConfig::getAll('ipv6');
    $jwksUrl = $dbConfig['jwks_url'] ?? '';

    if ($jwksUrl === '') {
        return response()->json(['error' => 'IPv6 detection not configured'], 503);
    }

    try {
        $ipv6 = $jwtService->verifyAndExtract($request->input('token'), $jwksUrl);
    } catch (Throwable $throwable) {
        return response()->json(['error' => 'Invalid token'], 422);
    }

    /** @var User $user */
    $user = $request->user();
    $ip = $user->addIp($ipv6);

    if ($ip instanceof IpAddress) {
        $clientIpRecord = IpAddress::whereAddress((string) $request->getClientIp())->first();
        $mac = $clientIpRecord?->currentMac();

        if ($mac !== null) {
            $existing = $ip->macAddresses()->where('mac_addresses.id', $mac->id)->first();

            if ($existing !== null) {
                $ip->macAddresses()->updateExistingPivot($mac->id, [
                    'last_seen_at' => now(),
                ]);
            } else {
                $ip->macAddresses()->attach($mac, [
                    'source' => 'ipv6_detection',
                    'last_seen_at' => now(),
                ]);

                AuditLog::record(
                    action: 'ip_mac.linked',
                    subject: $ip,
                    related: $mac,
                    process: 'ipv6_detection',
                    metadata: ['source' => 'ipv6_detection', 'client_ip' => (string) $request->getClientIp()],
                );
            }
        }
    }

    return response()->json((object) [
        'ip' => $ipv6,
        'internetEnabled' => $ip !== null && (bool) $ip->internet_enabled,
    ]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_links_mac_from_client_ipv4`
Expected: PASS

- [ ] **Step 5: Write test — no MAC linked when client IPv4 has no MAC**

Add to `tests/Feature/PortalControllerTest.php`:

```php
public function test_ipv6_does_not_link_mac_when_client_ip_has_no_mac(): void
{
    Queue::fake();
    $user = User::factory()->create(['internet_blocked' => false]);

    // Client IPv4 exists but has no MAC
    IpAddress::factory()->create(['address' => '127.0.0.1']);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->andReturn('2001:db8::2');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    $this->actingAs($user)->postJson('/ipv6', ['token' => 'valid.jwt.token']);

    $ipv6Record = IpAddress::whereAddress('2001:db8::2')->first();
    $this->assertNotNull($ipv6Record);
    $this->assertDatabaseMissing('ip_address_mac_address', [
        'ip_address_id' => $ipv6Record->id,
    ]);
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_does_not_link_mac_when_client_ip_has_no_mac`
Expected: PASS (the `$mac` is null, so the linkage block is skipped)

- [ ] **Step 7: Write test — no MAC linked when client IPv4 not in database**

Add to `tests/Feature/PortalControllerTest.php`:

```php
public function test_ipv6_does_not_link_mac_when_client_ip_not_in_database(): void
{
    Queue::fake();
    $user = User::factory()->create(['internet_blocked' => false]);

    // No IpAddress record for 127.0.0.1

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->andReturn('2001:db8::3');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    $this->actingAs($user)->postJson('/ipv6', ['token' => 'valid.jwt.token']);

    $ipv6Record = IpAddress::whereAddress('2001:db8::3')->first();
    $this->assertNotNull($ipv6Record);
    $this->assertDatabaseMissing('ip_address_mac_address', [
        'ip_address_id' => $ipv6Record->id,
    ]);
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_does_not_link_mac_when_client_ip_not_in_database`
Expected: PASS (the `$clientIpRecord` is null, so `$mac` is null)

- [ ] **Step 9: Write test — existing MAC link gets last_seen_at updated**

Add to `tests/Feature/PortalControllerTest.php`:

```php
public function test_ipv6_updates_last_seen_when_mac_already_linked(): void
{
    Queue::fake();
    $user = User::factory()->create(['internet_blocked' => false]);

    $clientIp = IpAddress::factory()->create(['address' => '127.0.0.1']);
    $ipv6Record = IpAddress::factory()->create(['address' => '2001:db8::4']);
    $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

    $clientIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
    $ipv6Record->macAddresses()->attach($mac, [
        'source' => 'ipv6_detection',
        'last_seen_at' => now()->subHour(),
    ]);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->andReturn('2001:db8::4');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    $this->travel(1)->hours();
    $this->actingAs($user)->postJson('/ipv6', ['token' => 'valid.jwt.token']);

    $pivot = $ipv6Record->macAddresses()->where('mac_addresses.id', $mac->id)->first();
    $this->assertNotNull($pivot);
    $this->assertTrue($pivot->pivot->last_seen_at->isAfter(now()->subMinute()));
}
```

- [ ] **Step 10: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_updates_last_seen_when_mac_already_linked`
Expected: PASS

- [ ] **Step 11: Write test — audit log created on new MAC linkage**

Add to `tests/Feature/PortalControllerTest.php`:

```php
use App\Models\AuditLog;

public function test_ipv6_mac_linkage_creates_audit_log(): void
{
    Queue::fake();
    $user = User::factory()->create(['internet_blocked' => false]);

    $clientIp = IpAddress::factory()->create(['address' => '127.0.0.1']);
    $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    $clientIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->andReturn('2001:db8::5');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    $this->actingAs($user)->postJson('/ipv6', ['token' => 'valid.jwt.token']);

    $ipv6Record = IpAddress::whereAddress('2001:db8::5')->first();
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'ip_mac.linked',
        'subject_type' => IpAddress::class,
        'subject_id' => $ipv6Record->id,
        'process' => 'ipv6_detection',
    ]);
}
```

Add `use App\Models\AuditLog;` to the imports at the top of the test file.

- [ ] **Step 12: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_mac_linkage_creates_audit_log`
Expected: PASS

- [ ] **Step 13: Run full PortalControllerTest to check for regressions**

Run: `cd /home/workspace/aperture && php artisan test --filter=PortalControllerTest`
Expected: All PASS

- [ ] **Step 14: Commit**

```bash
git add app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php
git commit -m "feat: link IPv6 to MAC address via client IPv4 on detection

When a client submits their IPv6 address to POST /ipv6, the system now
looks up the MAC associated with the client's IPv4 request IP and links
it to the IPv6 address. Both addresses come from the same device, so
they share a MAC.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Lint, Format & Verify

- [ ] **Step 1: Run Laravel Pint**

Run: `cd /home/workspace/aperture && ./vendor/bin/pint app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php`

Fix any formatting issues.

- [ ] **Step 2: Run RectorPHP**

Run: `cd /home/workspace/aperture && ./vendor/bin/rector process app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php`

Fix any issues.

- [ ] **Step 3: Run PHPStan**

Run: `cd /home/workspace/aperture && ./vendor/bin/phpstan analyse app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php --level=8`

Fix any issues.

- [ ] **Step 4: Run full test suite for affected areas**

Run: `cd /home/workspace/aperture && php artisan test --filter=PortalControllerTest --parallel`
Expected: All PASS

- [ ] **Step 5: Commit any formatting fixes**

```bash
git add app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php
git commit -m "style: formatting and lint fixes for IPv6 MAC linkage

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```
