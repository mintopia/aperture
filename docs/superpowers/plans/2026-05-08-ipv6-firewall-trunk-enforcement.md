# IPv6 Detection, Firewall Enforcement & Trunk Port Exclusion

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure firewall rules are always applied when IPs are linked to granted users (including via MAC cascade), add IPv6 detection retries on portal and periodic polling on dashboard, and exclude trunk ports from MAC address tracking.

**Architecture:** Five independent changes unified by the theme of "reliable IP enforcement." R3/R4 ensure every IP-to-user association triggers a firewall call. R1/R2 ensure IPv6 addresses are detected and submitted reliably. R5 filters noisy trunk port MACs from tracking. Changes span PHP controllers, a model method, a sync service, Blade JS, and a Vue component.

**Tech Stack:** Laravel 11 (PHP 8.3), Vue 3 + Inertia.js, PHPUnit, Vitest, Mockery

---

## File Map

| Requirement | Files Modified | Files Created |
|---|---|---|
| R5: Trunk exclusion | `app/Services/NetworkSwitch/PortSyncService.php` | — |
| R3: Firewall enforcement | `app/Http/Controllers/PortalController.php`, `app/Http/Controllers/Portal/DashboardController.php` | — |
| R4: MAC cascade firewall | `app/Models/User.php` | — |
| R1: Portal IPv6 retries | `resources/views/portal.blade.php` | — |
| R2: Dashboard IPv6 polling | `app/Http/Controllers/Portal/DashboardController.php`, `resources/js/Pages/Portal/Dashboard.vue` | `resources/js/composables/useIpv6Detection.js` |
| Tests | — | Tests listed per task below |

---

### Task 1: Trunk Port MAC Exclusion

**Files:**
- Modify: `app/Services/NetworkSwitch/PortSyncService.php` (syncPortMacs method, ~line 418)
- Test: `tests/Unit/Services/NetworkSwitch/PortSyncServiceTest.php`

- [ ] **Step 1: Write failing test — trunk ports excluded from MAC sync**

Add to `tests/Unit/Services/NetworkSwitch/PortSyncServiceTest.php`:

```php
public function test_sync_skips_mac_entries_on_trunk_ports(): void
{
    $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
        new PortStatus(
            interface: 'Gi1/0/1',
            status: 'connected',
            speed: 'a-1000',
            duplex: 'a-full',
            vlan: '100',
            switchportMode: 'access',
        ),
        new PortStatus(
            interface: 'Gi1/0/48',
            status: 'connected',
            speed: 'a-1000',
            duplex: 'a-full',
            vlan: '',
            switchportMode: 'trunk',
        ),
    ]));

    $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect([
        new ForwardingEntry(mac: 'AA:BB:CC:DD:EE:01', port: 'Gi1/0/1', vlan: 100),
        new ForwardingEntry(mac: 'AA:BB:CC:DD:EE:02', port: 'Gi1/0/48', vlan: 100),
        new ForwardingEntry(mac: 'AA:BB:CC:DD:EE:03', port: 'Gi1/0/48', vlan: 200),
    ]));

    $result = $this->service->syncSwitch($this->switchConfig);

    $this->assertSame(1, $result->syncRun->macs_created);
    $this->assertDatabaseHas('switch_port_macs', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    $this->assertDatabaseMissing('switch_port_macs', ['mac_address' => 'AA:BB:CC:DD:EE:02']);
    $this->assertDatabaseMissing('switch_port_macs', ['mac_address' => 'AA:BB:CC:DD:EE:03']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_sync_skips_mac_entries_on_trunk_ports`
Expected: FAIL — trunk port MACs are currently being created.

- [ ] **Step 3: Implement trunk port filter**

In `app/Services/NetworkSwitch/PortSyncService.php`, in the `syncPortMacs` method, add a trunk check after the null port check (~line 418):

```php
if (! $port instanceof SwitchPort) {
    continue;
}

if ($port->switchport_mode === 'trunk') {
    continue;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_sync_skips_mac_entries_on_trunk_ports`
Expected: PASS

- [ ] **Step 5: Write test — trunk ports with null switchport_mode are not excluded**

```php
public function test_sync_includes_mac_entries_on_ports_with_null_switchport_mode(): void
{
    $this->switchAdapter->shouldReceive('getAllPorts')->andReturn(collect([
        new PortStatus(
            interface: 'Gi1/0/1',
            status: 'connected',
            speed: 'a-1000',
            duplex: 'a-full',
            vlan: '100',
            switchportMode: '',
        ),
    ]));

    $this->switchAdapter->shouldReceive('getForwardingDatabase')->andReturn(collect([
        new ForwardingEntry(mac: 'AA:BB:CC:DD:EE:01', port: 'Gi1/0/1', vlan: 100),
    ]));

    $result = $this->service->syncSwitch($this->switchConfig);

    $this->assertSame(1, $result->syncRun->macs_created);
    $this->assertDatabaseHas('switch_port_macs', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
}
```

- [ ] **Step 6: Run to confirm it passes (null mode is not excluded)**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_sync_includes_mac_entries_on_ports_with_null_switchport_mode`
Expected: PASS

- [ ] **Step 7: Run full PortSyncServiceTest to check for regressions**

Run: `cd /home/workspace/aperture && php artisan test --filter=PortSyncServiceTest`
Expected: All tests PASS

- [ ] **Step 8: Commit**

```bash
git add app/Services/NetworkSwitch/PortSyncService.php tests/Unit/Services/NetworkSwitch/PortSyncServiceTest.php
git commit -m "feat: exclude trunk ports from MAC address tracking

Trunk ports carry traffic for multiple VLANs and their forwarding tables
contain MACs from across the network, not just directly connected devices.
Excluding them prevents noise in user-to-MAC associations.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 2: Firewall Enforcement on Portal and Dashboard

When a user with granted access visits the portal or dashboard, ensure the firewall rules are actually applied — even if the DB already shows `internet_enabled = true`. This catches cases where the firewall lost state (reboot, failover, etc.).

**Files:**
- Modify: `app/Http/Controllers/PortalController.php`
- Modify: `app/Http/Controllers/Portal/DashboardController.php`
- Test: `tests/Feature/PortalControllerTest.php`
- Test: `tests/Feature/Portal/DashboardControllerTest.php`

- [ ] **Step 1: Write failing test — portal status endpoint calls firewall**

Add to `tests/Feature/PortalControllerTest.php`:

```php
public function test_status_calls_firewall_enable_when_user_has_internet(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldReceive('addIp')->once();
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $this->actingAs($user)->get('/status');
}
```

Add required imports at top of test file:

```php
use App\Services\Interfaces\CaptivePortalInterface;
use Mockery;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_status_calls_firewall_enable_when_user_has_internet`
Expected: FAIL — `addIp` on CaptivePortalInterface is never called.

- [ ] **Step 3: Implement firewall call in PortalController::status()**

In `app/Http/Controllers/PortalController.php`, modify the `status()` method. It already has `IpAddressActionService $actionService` injected:

```php
public function status(Request $request, IpAddressActionService $actionService): JsonResponse
{
    $clientIp = (string) $request->getClientIp();
    /** @var User $user */
    $user = $request->user();
    $this->ensureInternetEnabled($user);
    $ip = $user->addIp($clientIp);

    if ($ip instanceof IpAddress && $ip->internet_enabled) {
        try {
            $actionService->enableInternet($ip);
        } catch (Throwable) {
            // Firewall sync is best-effort
        }
    }

    return response()->json((object) [
        'ip' => $clientIp,
        'internetEnabled' => $ip !== null && (bool) $ip->internet_enabled,
    ]);
}
```

Add import at top: `use App\Models\IpAddress;` and `use Throwable;` (if not already present).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_status_calls_firewall_enable_when_user_has_internet`
Expected: PASS

- [ ] **Step 5: Write test — portal status does NOT call firewall for blocked users**

```php
public function test_status_does_not_call_firewall_when_user_is_blocked(): void
{
    $user = User::factory()->create([
        'internet_enabled' => true,
        'internet_blocked' => true,
    ]);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldNotReceive('addIp');
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $this->actingAs($user)->get('/status');
}
```

- [ ] **Step 6: Run to confirm it passes (blocked users get internet_enabled=false via policy)**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_status_does_not_call_firewall_when_user_is_blocked`
Expected: PASS (IpPolicyService sets `internet_enabled = false` when user is blocked, so the `if ($ip->internet_enabled)` check prevents the firewall call)

- [ ] **Step 7: Write failing test — portal index calls firewall**

```php
public function test_index_calls_firewall_enable_when_user_has_internet(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldReceive('addIp')->once();
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $this->actingAs($user)->get('/');
}
```

- [ ] **Step 8: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_index_calls_firewall_enable_when_user_has_internet`
Expected: FAIL

- [ ] **Step 9: Implement firewall call in PortalController::index()**

Modify `index()` to inject `IpAddressActionService`:

```php
public function index(Request $request, IpAddressActionService $actionService): View
{
    $clientIp = (string) $request->getClientIp();
    /** @var User $user */
    $user = $request->user();
    $ip = $user->addIp($clientIp);

    if ($ip instanceof IpAddress && $ip->internet_enabled) {
        try {
            $actionService->enableInternet($ip);
        } catch (Throwable) {
            // Firewall sync is best-effort
        }
    }

    $dbConfig = IntegrationConfig::getAll('ipv6');
    $ipv6DetectionEndpoint = $dbConfig['detection_endpoint'] ?? '';

    $dnsCheckUrl = Setting::get('dns.check_url', '');

    return view('portal', [
        'ip' => $ip,
        'ipv6DetectionEndpoint' => $ipv6DetectionEndpoint,
        'dnsCheckUrl' => $dnsCheckUrl,
    ]);
}
```

- [ ] **Step 10: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_index_calls_firewall_enable_when_user_has_internet`
Expected: PASS

- [ ] **Step 11: Write failing test — IPv6 endpoint calls firewall**

```php
public function test_ipv6_calls_firewall_enable_when_user_has_internet(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);

    IntegrationConfig::set('ipv6', 'jwks_url', 'https://example.com/.well-known/jwks.json');

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')->andReturn('2001:db8::1');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldReceive('addIp')->once();
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $this->actingAs($user)->postJson('/ipv6', ['token' => 'test-jwt-token']);
}
```

- [ ] **Step 12: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_calls_firewall_enable_when_user_has_internet`
Expected: FAIL

- [ ] **Step 13: Implement firewall call in PortalController::ipv6()**

Modify `ipv6()` to inject `IpAddressActionService`:

```php
public function ipv6(Request $request, Ipv6JwtService $jwtService, IpAddressActionService $actionService): JsonResponse
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

    if ($ip instanceof IpAddress && $ip->internet_enabled) {
        try {
            $actionService->enableInternet($ip);
        } catch (Throwable) {
            // Firewall sync is best-effort
        }
    }

    return response()->json((object) [
        'ip' => $ipv6,
        'internetEnabled' => $ip !== null && (bool) $ip->internet_enabled,
    ]);
}
```

- [ ] **Step 14: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_ipv6_calls_firewall_enable_when_user_has_internet`
Expected: PASS

- [ ] **Step 15: Write failing test — dashboard calls firewall**

Add to `tests/Feature/Portal/DashboardControllerTest.php`:

```php
public function test_dashboard_calls_firewall_enable_when_user_has_internet(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldReceive('addIp')->once();
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $this->actingAs($user)->get('/portal');
}
```

Add imports:
```php
use App\Services\Interfaces\CaptivePortalInterface;
use Mockery;
```

- [ ] **Step 16: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_dashboard_calls_firewall_enable_when_user_has_internet`
Expected: FAIL

- [ ] **Step 17: Implement firewall call in DashboardController::index()**

Modify `index()` to inject `IpAddressActionService`:

```php
public function index(Request $request, IpAddressActionService $actionService): Response
{
    /** @var User $user */
    $user = $request->user();
    $clientIp = (string) $request->getClientIp();
    $ip = $user->addIp($clientIp);

    if ($ip instanceof IpAddress && $ip->internet_enabled) {
        try {
            $actionService->enableInternet($ip);
        } catch (Throwable) {
            // Firewall sync is best-effort
        }
    }

    $blocks = ContentBlock::active()->get();
    // ... rest of existing method unchanged
```

Add imports: `use App\Services\IpAddressActionService;`, `use App\Models\IpAddress;`, `use Throwable;`

- [ ] **Step 18: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_dashboard_calls_firewall_enable_when_user_has_internet`
Expected: PASS

- [ ] **Step 19: Run full test suites for both controllers**

Run: `cd /home/workspace/aperture && php artisan test --filter=PortalControllerTest && php artisan test --filter=DashboardControllerTest`
Expected: All PASS

- [ ] **Step 20: Commit**

```bash
git add app/Http/Controllers/PortalController.php app/Http/Controllers/Portal/DashboardController.php tests/Feature/PortalControllerTest.php tests/Feature/Portal/DashboardControllerTest.php
git commit -m "feat: enforce firewall rules on every portal and dashboard visit

Calls enableInternet on every page load for users with internet access,
ensuring the firewall reflects the DB state even after firewall restarts
or failover events.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 3: MAC Cascade Firewall Enforcement

When `cascadeMacOwnership` links a sibling IP to a user, and the user has internet access, the firewall should immediately allow that IP.

**Files:**
- Modify: `app/Models/User.php` (cascadeMacOwnership method, ~line 225)
- Test: `tests/Feature/NetworkDeviceTracking/UserLoginCascadeTest.php`

- [ ] **Step 1: Write failing test — cascaded IPs get firewall rules**

Add to `tests/Feature/NetworkDeviceTracking/UserLoginCascadeTest.php`:

```php
public function test_login_cascade_calls_firewall_for_sibling_ips(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);
    $primaryIp = IpAddress::factory()->create(['address' => '10.0.0.1', 'internet_enabled' => true]);
    $siblingIp = IpAddress::factory()->create(['address' => '10.0.0.2']);

    $mac = MacAddress::factory()->create(['user_id' => null]);
    $primaryIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
    $siblingIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldReceive('addIp')->with('10.0.0.1', Mockery::any())->once();
    $captivePortal->shouldReceive('addIp')->with('10.0.0.2', Mockery::any())->once();
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $user->addIp('10.0.0.1');
}
```

Add imports:
```php
use App\Services\Interfaces\CaptivePortalInterface;
use Mockery;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_login_cascade_calls_firewall_for_sibling_ips`
Expected: FAIL — captivePortal::addIp is not called for the sibling IP `10.0.0.2`.

- [ ] **Step 3: Implement firewall call in cascadeMacOwnership**

In `app/Models/User.php`, in the `cascadeMacOwnership` method, after the cascaded `addIp` call:

```php
$cascaded = $this->addIp($siblingIp->address, cascade: false);

if ($cascaded instanceof IpAddress) {
    AuditLog::record(
        action: 'ip.user_cascaded',
        subject: $siblingIp,
        related: $mac,
        actor: $this,
        process: 'portal_login',
        metadata: ['source_ip' => $ip->address],
    );

    if ($cascaded->internet_enabled) {
        try {
            app(IpAddressActionService::class)->enableInternet($cascaded);
        } catch (Throwable) {
            // Firewall sync is best-effort
        }
    }
}
```

Add import at top of User.php: `use App\Services\IpAddressActionService;` (and `use Throwable;` if not already present).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_login_cascade_calls_firewall_for_sibling_ips`
Expected: PASS

- [ ] **Step 5: Write test — cascade does NOT call firewall for blocked users**

```php
public function test_login_cascade_does_not_call_firewall_when_user_blocked(): void
{
    $user = User::factory()->create([
        'internet_enabled' => true,
        'internet_blocked' => true,
    ]);
    $primaryIp = IpAddress::factory()->create(['address' => '10.0.0.1']);
    $siblingIp = IpAddress::factory()->create(['address' => '10.0.0.2']);

    $mac = MacAddress::factory()->create(['user_id' => null]);
    $primaryIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
    $siblingIp->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

    $captivePortal = Mockery::mock(CaptivePortalInterface::class);
    $captivePortal->shouldNotReceive('addIp');
    $this->app->instance(CaptivePortalInterface::class, $captivePortal);

    $user->addIp('10.0.0.1');
}
```

- [ ] **Step 6: Run to confirm it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_login_cascade_does_not_call_firewall_when_user_blocked`
Expected: PASS (IpPolicyService sets `internet_enabled = false` when blocked)

- [ ] **Step 7: Run full cascade test suite**

Run: `cd /home/workspace/aperture && php artisan test --filter=UserLoginCascadeTest`
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add app/Models/User.php tests/Feature/NetworkDeviceTracking/UserLoginCascadeTest.php
git commit -m "feat: enforce firewall rules for IPs discovered via MAC cascade

When a user logs in and MAC cascade discovers sibling IPs on shared MACs,
those IPs are now immediately allowed in the firewall if the user has
internet access.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 4: Portal IPv6 Detection Before Redirect

Currently the portal page either redirects immediately (if already enabled) or does a single IPv6 attempt (if not yet enabled). Change to always attempt IPv6 detection 3 times before redirecting, regardless of initial state.

**Files:**
- Modify: `resources/views/portal.blade.php`
- Test: `tests/Feature/PortalControllerTest.php`

- [ ] **Step 1: Write test — portal always passes IPv6 endpoint to view**

The IPv6 endpoint variable needs to be available in the JS regardless of `internet_enabled` state. Add to `tests/Feature/PortalControllerTest.php`:

```php
public function test_portal_passes_ipv6_endpoint_regardless_of_internet_status(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);
    IntegrationConfig::set('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');

    $response = $this->actingAs($user)->get('/');

    $response->assertViewHas('ipv6DetectionEndpoint', 'https://{random}.ipv6.example.com');
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_portal_passes_ipv6_endpoint_regardless_of_internet_status`
Expected: PASS (the controller already passes `ipv6DetectionEndpoint` to the view unconditionally)

- [ ] **Step 3: Rewrite portal.blade.php script section**

Replace the entire `@section('scripts')` block in `resources/views/portal.blade.php` with:

```blade
@section('scripts')
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var statusOK = document.getElementById('status-ok');
        var statusWaiting = document.getElementById('status-waiting');
        var ipv6Endpoint = @json($ipv6DetectionEndpoint ?? '');

        function uuid() {
            if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
                return crypto.randomUUID();
            }
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                var r = Math.random() * 16 | 0;
                return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        }

        function attemptIpv6Detection(remaining, callback) {
            if (remaining <= 0 || !ipv6Endpoint) {
                callback();
                return;
            }

            var endpoint = ipv6Endpoint.replace('{random}', uuid());
            fetch(endpoint)
                .then(function(response) { return response.ok ? response.text() : null; })
                .then(function(token) {
                    if (token && token.trim().length > 0) {
                        return fetch("/ipv6", {
                            method: "POST",
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ 'token': token.trim() }),
                        });
                    }
                })
                .then(function() {
                    setTimeout(function() {
                        attemptIpv6Detection(remaining - 1, callback);
                    }, 1000);
                })
                .catch(function() {
                    setTimeout(function() {
                        attemptIpv6Detection(remaining - 1, callback);
                    }, 1000);
                });
        }

        function redirectToDashboard() {
            window.location.href = @json(route('portal.dashboard'));
        }

        var checks = 0;
        var internetEnabled = {{ $ip?->internet_enabled ? 'true' : 'false' }};

        function onInternetEnabled() {
            if (statusWaiting) statusWaiting.classList.add('hidden');
            if (statusOK) statusOK.classList.remove('hidden');
            checkDns();
            attemptIpv6Detection(3, redirectToDashboard);
        }

        function checkStatus() {
            checks++;
            var timeout = 2000;
            if (checks > 20) {
                timeout = 30000;
            } else if (checks > 4) {
                timeout = 10000;
            }

            fetch('/status')
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.internetEnabled === true) {
                        if (!internetEnabled) {
                            internetEnabled = true;
                            onInternetEnabled();
                        }
                    } else {
                        setTimeout(checkStatus, timeout);
                    }
                })
                .catch(function(error) {
                    console.error('Error fetching status:', error);
                    setTimeout(checkStatus, timeout);
                });
        }

        function checkDns() {
            @if($dnsCheckUrl)
            var dnsUrl = @json($dnsCheckUrl).replace('{uuid}', uuid());
            fetch(dnsUrl)
                .then(function(response) { return response.ok ? response.json() : null; })
                .then(function(data) {
                    if (data && data.server !== 'event') {
                        document.getElementById('dns-warning').classList.remove('hidden');
                    }
                })
                .catch(function() {});
            @endif
        }

        if (internetEnabled) {
            onInternetEnabled();
        }

        @if(!$ip?->internet_enabled && !Auth::user()->internet_blocked)
        setTimeout(checkStatus, 2000);
        @endif
    });
    </script>
@endsection
```

Key changes:
- `ipv6Endpoint` is always defined (not conditionally inside the `@if(!internet_enabled)` block)
- New `attemptIpv6Detection(remaining, callback)` function that retries 3 times
- New `onInternetEnabled()` function called both when already enabled and when status polling confirms it
- `onInternetEnabled` runs 3 IPv6 detection attempts then redirects
- Removed the old conditional `@if($ipv6DetectionEndpoint)` block and the old single-attempt IPv6 fetch
- Status polling starts unconditionally (was previously gated behind `!internet_enabled`)

- [ ] **Step 4: Write test — view renders IPv6 detection JS for already-enabled users**

```php
public function test_portal_renders_ipv6_detection_for_enabled_user(): void
{
    $user = User::factory()->create(['internet_enabled' => true]);
    IntegrationConfig::set('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');

    $response = $this->actingAs($user)->get('/');

    $response->assertSee('attemptIpv6Detection', false);
    $response->assertSee('ipv6.example.com', false);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_portal_renders_ipv6_detection_for_enabled_user`
Expected: PASS

- [ ] **Step 6: Run full PortalControllerTest**

Run: `cd /home/workspace/aperture && php artisan test --filter=PortalControllerTest`
Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add resources/views/portal.blade.php tests/Feature/PortalControllerTest.php
git commit -m "feat: retry IPv6 detection 3 times before redirecting from portal

Ensures IPv6 addresses are registered in the firewall before the user
leaves the portal page. Applies to both initially-enabled users and
those who just completed the device flow.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 5: Dashboard IPv6 Polling

Add periodic IPv6 detection to the dashboard, checking every 2 minutes. This catches IPv6 addresses that weren't available at initial login.

**Files:**
- Modify: `app/Http/Controllers/Portal/DashboardController.php`
- Create: `resources/js/composables/useIpv6Detection.js`
- Modify: `resources/js/Pages/Portal/Dashboard.vue`
- Test: `tests/Feature/Portal/DashboardControllerTest.php`
- Test: `tests/js/composables/useIpv6Detection.spec.js`

- [ ] **Step 1: Write failing test — dashboard passes IPv6 endpoint**

Add to `tests/Feature/Portal/DashboardControllerTest.php`:

```php
public function test_dashboard_passes_ipv6_detection_endpoint_when_configured(): void
{
    $user = User::factory()->create();
    IntegrationConfig::set('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');

    $response = $this->actingAs($user)->get('/portal');

    $response->assertInertia(fn ($page) => $page
        ->has('ipv6Detection')
        ->where('ipv6Detection.endpoint', 'https://{random}.ipv6.example.com')
    );
}
```

Add import: `use App\Models\IntegrationConfig;`

- [ ] **Step 2: Write test — dashboard passes null IPv6 detection when not configured**

```php
public function test_dashboard_passes_null_ipv6_detection_when_not_configured(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/portal');

    $response->assertInertia(fn ($page) => $page
        ->where('ipv6Detection', null)
    );
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_dashboard_passes_ipv6_detection_endpoint_when_configured && php artisan test --filter=test_dashboard_passes_null_ipv6_detection_when_not_configured`
Expected: FAIL — no `ipv6Detection` prop in the Inertia response.

- [ ] **Step 4: Implement IPv6 detection endpoint in DashboardController**

In `app/Http/Controllers/Portal/DashboardController.php`, add the IPv6 config reading and pass it to Inertia. Add import: `use App\Models\IntegrationConfig;`

In the `index()` method, before the `return Inertia::render(...)`, add:

```php
$ipv6Config = IntegrationConfig::getAll('ipv6');
$ipv6Endpoint = $ipv6Config['detection_endpoint'] ?? '';
```

Then add to the Inertia render array:

```php
'ipv6Detection' => $ipv6Endpoint !== '' ? [
    'endpoint' => $ipv6Endpoint,
] : null,
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/workspace/aperture && php artisan test --filter=test_dashboard_passes_ipv6_detection_endpoint_when_configured && php artisan test --filter=test_dashboard_passes_null_ipv6_detection_when_not_configured`
Expected: PASS

- [ ] **Step 6: Create the useIpv6Detection composable**

Create `resources/js/composables/useIpv6Detection.js`:

```javascript
import { onMounted, onUnmounted } from 'vue';

function generateUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

async function detectAndSubmitIpv6(endpointTemplate) {
    const endpoint = endpointTemplate.replace('{random}', generateUuid());
    try {
        const response = await fetch(endpoint);
        if (!response.ok) return;
        const token = await response.text();
        if (!token || !token.trim()) return;
        await fetch('/ipv6', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: token.trim() }),
        });
    } catch {
        // IPv6 detection is best-effort
    }
}

export function useIpv6Detection(endpointTemplate, intervalMs = 120000) {
    let timer = null;

    onMounted(() => {
        if (!endpointTemplate) return;

        detectAndSubmitIpv6(endpointTemplate);

        timer = setInterval(() => {
            detectAndSubmitIpv6(endpointTemplate);
        }, intervalMs);
    });

    onUnmounted(() => {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return { detectAndSubmitIpv6 };
}
```

- [ ] **Step 7: Write Vitest tests for the composable**

Create `tests/js/composables/useIpv6Detection.spec.js`:

```javascript
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('vue', () => ({
    onMounted: vi.fn((cb) => cb()),
    onUnmounted: vi.fn(),
}));

import { useIpv6Detection } from '@/composables/useIpv6Detection.js';
import { onMounted, onUnmounted } from 'vue';

describe('useIpv6Detection', () => {
    let fetchMock;

    beforeEach(() => {
        vi.useFakeTimers();
        fetchMock = vi.fn();
        global.fetch = fetchMock;
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('does nothing when endpoint is empty', () => {
        useIpv6Detection('');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does nothing when endpoint is null', () => {
        useIpv6Detection(null);
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fetches IPv6 endpoint immediately on mount', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, text: () => Promise.resolve('jwt-token') });
        fetchMock.mockResolvedValueOnce({ ok: true });

        useIpv6Detection('https://{random}.ipv6.example.com');

        await vi.runAllTimersAsync();

        expect(fetchMock).toHaveBeenCalledTimes(2);
        const firstCallUrl = fetchMock.mock.calls[0][0];
        expect(firstCallUrl).toMatch(/https:\/\/[a-f0-9-]+\.ipv6\.example\.com/);

        expect(fetchMock.mock.calls[1][0]).toBe('/ipv6');
        expect(fetchMock.mock.calls[1][1].method).toBe('POST');
    });

    it('submits token to /ipv6 endpoint', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, text: () => Promise.resolve('my-jwt') });
        fetchMock.mockResolvedValueOnce({ ok: true });

        useIpv6Detection('https://{random}.ipv6.example.com');

        await vi.runAllTimersAsync();

        const postCall = fetchMock.mock.calls[1];
        expect(postCall[0]).toBe('/ipv6');
        const body = JSON.parse(postCall[1].body);
        expect(body.token).toBe('my-jwt');
    });

    it('does not POST when detection response is not ok', async () => {
        fetchMock.mockResolvedValueOnce({ ok: false });

        useIpv6Detection('https://{random}.ipv6.example.com');

        await vi.runAllTimersAsync();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not POST when token is empty', async () => {
        fetchMock.mockResolvedValueOnce({ ok: true, text: () => Promise.resolve('') });

        useIpv6Detection('https://{random}.ipv6.example.com');

        await vi.runAllTimersAsync();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('repeats detection at configured interval', async () => {
        fetchMock.mockResolvedValue({ ok: true, text: () => Promise.resolve('jwt') });

        useIpv6Detection('https://{random}.ipv6.example.com', 5000);

        await vi.runAllTimersAsync();
        fetchMock.mockClear();

        await vi.advanceTimersByTimeAsync(5000);

        expect(fetchMock).toHaveBeenCalled();
    });

    it('registers cleanup on unmount', () => {
        fetchMock.mockResolvedValue({ ok: false });

        useIpv6Detection('https://{random}.ipv6.example.com');

        expect(onUnmounted).toHaveBeenCalled();
    });

    it('silently handles fetch errors', async () => {
        fetchMock.mockRejectedValueOnce(new Error('network error'));

        useIpv6Detection('https://{random}.ipv6.example.com');

        await expect(vi.runAllTimersAsync()).resolves.not.toThrow();
    });
});
```

- [ ] **Step 8: Run Vitest to verify tests pass**

Run: `cd /home/workspace/aperture && npx vitest run tests/js/composables/useIpv6Detection.spec.js`
Expected: All PASS

- [ ] **Step 9: Integrate composable into Dashboard.vue**

In `resources/js/Pages/Portal/Dashboard.vue`, add the import and usage:

After the existing imports, add:
```javascript
import { useIpv6Detection } from '@/composables/useIpv6Detection.js';
```

Add a new prop:
```javascript
ipv6Detection: { type: Object, default: null },
```

After the existing `onMounted` block (after the `channelCleanup = cleanup;` line), add the composable call. Place it outside the `onMounted` since the composable registers its own `onMounted`:

```javascript
useIpv6Detection(props.ipv6Detection?.endpoint);
```

Place this line after the `const liveContext = reactive(...)` line and before the `let channelCleanup = null;` line.

- [ ] **Step 10: Run full Vitest suite**

Run: `cd /home/workspace/aperture && npx vitest run`
Expected: All PASS

- [ ] **Step 11: Run full DashboardControllerTest**

Run: `cd /home/workspace/aperture && php artisan test --filter=DashboardControllerTest`
Expected: All PASS

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/Portal/DashboardController.php resources/js/composables/useIpv6Detection.js resources/js/Pages/Portal/Dashboard.vue tests/Feature/Portal/DashboardControllerTest.php tests/js/composables/useIpv6Detection.spec.js
git commit -m "feat: add periodic IPv6 detection to dashboard (every 2 minutes)

Detects the user's IPv6 address via DNS-based detection and submits it
to the API for firewall registration. Runs immediately on page load
and every 2 minutes thereafter.

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```

---

### Task 6: Final Integration & Lint

- [ ] **Step 1: Run Laravel Pint**

Run: `cd /home/workspace/aperture && ./vendor/bin/pint`

- [ ] **Step 2: Run PHPStan**

Run: `cd /home/workspace/aperture && ./vendor/bin/phpstan analyse --level=8`

Fix any issues found.

- [ ] **Step 3: Run full PHP test suite**

Run: `cd /home/workspace/aperture && php artisan test --parallel`
Expected: All PASS, 100% coverage on modified files.

- [ ] **Step 4: Run ESLint + Prettier**

Run: `cd /home/workspace/aperture && npx eslint resources/js/ --fix && npx prettier --write resources/js/`

- [ ] **Step 5: Run full Vitest suite**

Run: `cd /home/workspace/aperture && npx vitest run`
Expected: All PASS

- [ ] **Step 6: Commit any formatting fixes**

```bash
git add -A
git commit -m "style: formatting and lint fixes

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>"
```
