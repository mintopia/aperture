# Bugfix Batch — 12 Production Issues

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 12 production bugs spanning admin login redirect, DHCP display, switch management, audit log readability, and sync resilience.

**Architecture:** Backend fixes in Laravel controllers/services/jobs, frontend fixes in Vue components. Each task is independent — safe to implement in any order.

**Tech Stack:** Laravel 12, PHP 8.4, Vue 3, Inertia.js, PHPUnit, Vitest

---

### Task 1: Admin login redirects to captive portal instead of admin dashboard

**Bug:** When an admin logs in from the admin login form, `redirect()->intended()` may find a stored captive portal URL from a prior unauthenticated visit, sending them to the frontend polling page instead of the admin dashboard.

**Root cause:** `redirect()->intended($defaultUrl)` checks session for a stored intended URL. If a captive portal redirect was stored, it takes precedence over `$defaultUrl` even for admin users.

**Files:**
- Modify: `app/Http/Controllers/LoginController.php:30-44`
- Test: `tests/Feature/LoginControllerTest.php`

- [ ] **Step 1: Write failing tests**

In `tests/Feature/LoginControllerTest.php`, add two tests:

```php
public function test_admin_login_ignores_non_admin_intended_url(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Simulate a stored captive portal intended URL
    session()->put('url.intended', '/');

    $response = $this->post(route('login'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('admin.home'));
}

public function test_admin_login_follows_admin_intended_url(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    // Simulate a stored admin intended URL
    session()->put('url.intended', route('admin.switches.index'));

    $response = $this->post(route('login'), [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('admin.switches.index'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="test_admin_login_ignores_non_admin_intended_url|test_admin_login_follows_admin_intended_url"`
Expected: FAIL — admin gets redirected to `/` instead of admin dashboard

- [ ] **Step 3: Fix LoginController::authenticate**

In `app/Http/Controllers/LoginController.php`, replace the redirect logic:

```php
// Before:
$defaultUrl = $user->hasRole('admin') ? route('admin.home') : '/';
return redirect()->intended($defaultUrl);

// After:
if ($user->hasRole('admin')) {
    $intended = session()->pull('url.intended', '');
    $url = str_starts_with($intended, '/admin') ? $intended : route('admin.home');
    return redirect($url);
}
return redirect()->intended('/');
```

This ensures admin users only follow intended URLs that are admin routes. Non-admin intended URLs (captive portal, `/`) are discarded.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="LoginControllerTest"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LoginController.php tests/Feature/LoginControllerTest.php
git commit -m "fix(auth): admin login ignores non-admin intended URLs"
```

---

### Task 2: 500 error on MAC addresses page 2

**Bug:** Navigating to page 2 of MAC addresses causes `SearchHelper::toLikePattern(): Argument #1 ($input) must be of type string, null given`. The production deployment references `SearchHelper` but it doesn't exist in the current codebase.

**Root cause:** The current `MacAddressController` uses `sprintf` with default empty strings, which works. But the production deployment has a different version. The fix is to add null safety to the filter inputs so pagination links (which may omit filter params) work correctly regardless of implementation.

**Files:**
- Modify: `app/Http/Controllers/Admin/MacAddressController.php:19-28`
- Test: `tests/Feature/Admin/MacAddressControllerTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Feature/Admin/MacAddressControllerTest.php`:

```php
public function test_index_page_2_without_filter_params(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    MacAddress::factory()->count(25)->create();

    $response = $this->actingAs($admin)->get(route('admin.macs.index', ['page' => 2]));

    $response->assertOk();
}
```

- [ ] **Step 2: Run test to verify it passes (baseline)**

Run: `php artisan test --filter="test_index_page_2_without_filter_params"`
Expected: PASS with current code (sprintf version). This test documents the expected behaviour.

- [ ] **Step 3: Harden filter input handling**

In `app/Http/Controllers/Admin/MacAddressController.php`, cast all filter inputs to string explicitly:

```php
$filters = (object) [
    'perPage' => $request->input('perPage', 20),
    'mac' => (string) $request->input('mac', ''),
    'hostname' => (string) $request->input('hostname', ''),
    'nickname' => (string) $request->input('nickname', ''),
    'ip' => (string) $request->input('ip', ''),
    'order' => 'created_at',
    'direction' => 'desc',
];
```

- [ ] **Step 4: Run full controller tests**

Run: `php artisan test --filter="MacAddressControllerTest"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/MacAddressController.php tests/Feature/Admin/MacAddressControllerTest.php
git commit -m "fix(macs): cast filter inputs to string to prevent null TypeError"
```

---

### Task 3: Cisco integration does not show as enabled

**Bug:** The integrations page shows Cisco as disabled even when configured with a `switch_id`.

**Root cause:** `SettingsController::isIntegrationEnabled()` checks for `$config['enabled']` key, then falls back to `!empty($config['endpoint'])`. Cisco uses `switch_id` (not `endpoint`) and stores no explicit `enabled` flag, so the method always returns `false`.

**Files:**
- Modify: `app/Http/Controllers/Admin/SettingsController.php:66-73`
- Test: `tests/Feature/Admin/SettingsControllerIntegrationExpansionTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Feature/Admin/SettingsControllerIntegrationExpansionTest.php`:

```php
public function test_cisco_integration_shows_enabled_when_switch_id_configured(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    IntegrationConfig::set('cisco', 'switch_id', '1');

    $response = $this->actingAs($admin)->get(route('admin.settings.integrations'));

    $response->assertOk();
    $page = $response->viewData('page');
    $services = collect($page['props']['services']);
    $cisco = $services->firstWhere('id', 'cisco');

    $this->assertTrue($cisco['enabled']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="test_cisco_integration_shows_enabled_when_switch_id_configured"`
Expected: FAIL — `$cisco['enabled']` is false

- [ ] **Step 3: Fix isIntegrationEnabled**

In `app/Http/Controllers/Admin/SettingsController.php`, update the method:

```php
private function isIntegrationEnabled(array $config): bool
{
    if (isset($config['enabled'])) {
        return (bool) $config['enabled'];
    }

    return ! empty($config['endpoint'] ?? null)
        || ! empty($config['switch_id'] ?? null);
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="SettingsController"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/SettingsController.php tests/Feature/Admin/SettingsControllerIntegrationExpansionTest.php
git commit -m "fix(integrations): detect Cisco as enabled when switch_id is configured"
```

---

### Task 4: Switch edit form requires re-entering username

**Bug:** When editing a switch, leaving the username blank fails validation because `UpdateSwitchRequest` requires `username`. The form pre-fills username from the model, but users expect to leave it blank to mean "keep current" (same as password).

**Root cause:** `UpdateSwitchRequest` rules have `'username' => 'required|string|max:255'`. The SwitchConfig model includes username in serialization (not in `$hidden`), so the form IS pre-filled. However, the user's expectation is that blank = keep current for ALL credential fields.

**Files:**
- Modify: `app/Http/Requests/Admin/UpdateSwitchRequest.php:35`
- Modify: `app/Http/Controllers/Admin/SwitchManagementController.php:109-117`
- Test: `tests/Feature/Admin/SwitchManagementControllerTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Feature/Admin/SwitchManagementControllerTest.php`:

```php
public function test_update_switch_keeps_existing_username_when_blank(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $switch = SwitchConfig::factory()->create(['username' => 'originaluser']);

    $response = $this->actingAs($admin)->put(route('admin.switches.update', $switch), [
        'name' => $switch->name,
        'hostname' => $switch->hostname,
        'type' => $switch->type,
        'username' => '',
        'password' => '',
        'port' => $switch->port,
        'timeout' => $switch->timeout,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('switch_configs', [
        'id' => $switch->id,
        'username' => 'originaluser',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="test_update_switch_keeps_existing_username_when_blank"`
Expected: FAIL — validation error on username field

- [ ] **Step 3: Make username nullable in UpdateSwitchRequest**

In `app/Http/Requests/Admin/UpdateSwitchRequest.php`, change:

```php
// Before:
'username' => 'required|string|max:255',

// After:
'username' => 'nullable|string|max:255',
```

- [ ] **Step 4: Handle empty username in controller**

In `app/Http/Controllers/Admin/SwitchManagementController.php`, update the `update` method:

```php
public function update(UpdateSwitchRequest $request, SwitchConfig $switchConfig): RedirectResponse
{
    $validated = $request->validated();

    if (empty($validated['username'])) {
        unset($validated['username']);
    }

    if (empty($validated['password'])) {
        unset($validated['password']);
    }

    if (empty($validated['enable_password'])) {
        unset($validated['enable_password']);
    }

    $switchConfig->update($validated);
    // ... rest of method unchanged
```

- [ ] **Step 5: Update placeholder text in Edit.vue**

In `resources/js/Pages/Admin/Switches/Edit.vue`, add a placeholder to the username field (around line 200):

```html
<input
    id="username"
    v-model="form.username"
    type="text"
    data-testid="switch-username"
    autocomplete="off"
    placeholder="Leave blank to keep current"
    class="..."
/>
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter="SwitchManagementControllerTest"`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Admin/UpdateSwitchRequest.php app/Http/Controllers/Admin/SwitchManagementController.php resources/js/Pages/Admin/Switches/Edit.vue
git commit -m "fix(switches): allow blank username/password on edit to keep current values"
```

---

### Task 5: Switch sync fails entirely on DHCP snooping error

**Bug:** If DHCP snooping table fetch fails for a switch (e.g. sw-edge-2), the entire sync is marked as failed. The switch page shows "last sync 7 hours ago with error" and it doesn't retry on the usual schedule because the circuit breaker trips.

**Root cause:** `PortSyncService::processSnoopingBindings()` is called inside a DB transaction. If it throws, the entire transaction rolls back and the sync fails. Snooping is supplementary data — it should not prevent port/MAC sync from succeeding.

**Files:**
- Modify: `app/Services/NetworkSwitch/PortSyncService.php:76-80`
- Test: `tests/Unit/Services/NetworkSwitch/PortSyncServiceSnoopingTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Unit/Services/NetworkSwitch/PortSyncServiceSnoopingTest.php`:

```php
public function test_sync_succeeds_when_snooping_throws(): void
{
    $switchConfig = SwitchConfig::factory()->create(['enabled' => true]);

    $adapter = Mockery::mock(CiscoSwitchAdapter::class, SupportsDhcpSnooping::class);
    $adapter->shouldReceive('getAllPorts')->andReturn(collect());
    $adapter->shouldReceive('getForwardingDatabase')->andReturn(collect());
    $adapter->shouldReceive('getDhcpSnoopingBindings')->andThrow(new \RuntimeException('DHCP snooping table not found'));

    $factory = Mockery::mock(SwitchServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($adapter);

    $portConfigSync = Mockery::mock(PortConfigSync::class);
    $portConfigSync->shouldReceive('fetchFromNetwork')->andReturn([]);
    $portConfigSync->shouldReceive('sync');

    $portStatusSync = Mockery::mock(PortStatusSync::class);
    $portStatusSync->shouldReceive('sync')->andReturn(['created' => 0, 'updated' => 0, 'stateChanges' => []]);

    $portMacSync = Mockery::mock(PortMacSync::class);
    $portMacSync->shouldReceive('sync')->andReturn(['created' => 0, 'updated' => 0, 'syncedMacIds' => []]);
    $portMacSync->shouldReceive('cleanStaleMacs');

    $runTracker = app(SyncRunTracker::class);

    $service = new PortSyncService($factory, $runTracker, $portStatusSync, $portMacSync, $portConfigSync);
    $result = $service->syncSwitch($switchConfig);

    $this->assertEquals('completed', $result->syncRun->status);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="test_sync_succeeds_when_snooping_throws"`
Expected: FAIL — RuntimeException propagates up and sync fails

- [ ] **Step 3: Wrap processSnoopingBindings in try-catch**

In `app/Services/NetworkSwitch/PortSyncService.php`, inside the DB::transaction closure (around line 76):

```php
// Before:
$this->processSnoopingBindings($adapter, $switchConfig);

// After:
try {
    $this->processSnoopingBindings($adapter, $switchConfig);
} catch (Throwable $throwable) {
    Log::warning('DHCP snooping sync failed, continuing with port sync', [
        'switch' => $switchConfig->hostname,
        'error' => $throwable->getMessage(),
    ]);
}
```

Add `use Illuminate\Support\Facades\Log;` and `use Throwable;` at the top if not already imported.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="PortSyncService"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/NetworkSwitch/PortSyncService.php tests/Unit/Services/NetworkSwitch/PortSyncServiceSnoopingTest.php
git commit -m "fix(sync): DHCP snooping failure no longer blocks port sync"
```

---

### Task 6: Normalize IPv6 addresses to lowercase

**Bug:** IPv6 addresses may be stored with uppercase hex characters (e.g. `2001:DB8::1`), causing display inconsistency and filtering issues.

**Root cause:** IPv6 addresses are stored as-is from the switch output without normalizing case.

**Files:**
- Modify: `app/Jobs/SyncDhcpData.php` (lease sync — normalize IP)
- Modify: `app/Services/NetworkSwitch/PortSyncService.php` (snooping — normalize IP)
- Modify: `app/Models/IpAddress.php` (model mutator)
- Test: `tests/Unit/Models/IpAddressTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Unit/Models/IpAddressTest.php`:

```php
public function test_ipv6_address_is_normalized_to_lowercase(): void
{
    $ip = IpAddress::create(['address' => '2001:DB8::ABCD:1']);

    $this->assertEquals('2001:db8::abcd:1', $ip->address);
}

public function test_ipv4_address_is_not_affected(): void
{
    $ip = IpAddress::create(['address' => '10.30.0.1']);

    $this->assertEquals('10.30.0.1', $ip->address);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="test_ipv6_address_is_normalized_to_lowercase"`
Expected: FAIL — address stored as uppercase

- [ ] **Step 3: Add mutator to IpAddress model**

In `app/Models/IpAddress.php`, add a mutator:

```php
protected function address(): Attribute
{
    return Attribute::make(
        set: fn (string $value): string => str_contains($value, ':') ? strtolower($value) : $value,
    );
}
```

Add `use Illuminate\Database\Eloquent\Casts\Attribute;` if not imported.

- [ ] **Step 4: Normalize in DhcpSnoopingObservation too**

In `app/Services/NetworkSwitch/PortSyncService.php`, in `processSnoopingBindings`, normalize the IP:

```php
// Before:
'ip' => $binding['ip'],

// After:
'ip' => str_contains($binding['ip'], ':') ? strtolower($binding['ip']) : $binding['ip'],
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="IpAddressTest"`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/IpAddress.php app/Services/NetworkSwitch/PortSyncService.php tests/Unit/Models/IpAddressTest.php
git commit -m "fix(ipv6): normalize IPv6 addresses to lowercase on storage"
```

---

### Task 7: DHCP Ranges — no used IPs and no IPv6 pool total size

**Bug 4:** DHCP ranges page shows 0 used IPs for all ranges.
**Bug 5:** IPv6 ranges show no total size.

**Root cause (used IPs):** `CiscoDhcpService::getRanges()` never sets `usedAddresses` or `utilisation` on the DhcpRange VOs. Unlike OpnSenseDhcpService which enriches ranges with lease counts, Cisco ranges are returned raw.

**Root cause (IPv6 size):** IPv6 DhcpRange VOs are created without `totalAddresses`. The prefix provides enough info to calculate it.

**Files:**
- Modify: `app/Services/Cisco/CiscoDhcpService.php:111-153`
- Test: `tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php`

- [ ] **Step 1: Write failing tests**

In `tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php`:

```php
public function test_get_ranges_includes_used_address_counts(): void
{
    // Setup: transport that returns pool config + binding table with leases in range
    $transport = Mockery::mock(SwitchCommandTransportInterface::class);
    $parser = new IosOutputParser();

    // Build the service and mock snapshot with known pool config and bindings
    // (Use existing test patterns from this file for transport mock setup)
    
    $service = new CiscoDhcpService($transport, $parser, '0', true);
    // ... trigger snapshot with pool config having one IPv4 pool
    // ... and binding table with 3 leases in that pool's range
    
    $ranges = $service->getRanges();
    $ipv4Range = $ranges->first(fn ($r) => $r->type === 'ipv4');
    
    $this->assertNotNull($ipv4Range->usedAddresses);
    $this->assertGreaterThan(0, $ipv4Range->usedAddresses);
}

public function test_get_ranges_ipv6_includes_total_addresses(): void
{
    // Setup similar — with IPv6 pool having prefix 2001:db8::/64
    $ranges = $service->getRanges();
    $ipv6Range = $ranges->first(fn ($r) => $r->type === 'ipv6');
    
    $this->assertNotNull($ipv6Range->totalAddresses);
}
```

Note: Use existing test patterns in this file. The exact transport mock setup should follow the established pattern for `CiscoDhcpServiceTest`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="test_get_ranges_includes_used_address_counts|test_get_ranges_ipv6_includes_total_addresses"`
Expected: FAIL — usedAddresses is null, totalAddresses is null

- [ ] **Step 3: Enrich IPv4 ranges with used address counts**

In `app/Services/Cisco/CiscoDhcpService.php`, in `getRanges()`, after building IPv4 ranges, count leases per range using the binding table data:

```php
$bindings = $this->snapshot['bindings'] ?? [];

$ranges = collect($effectiveRanges)
    ->map(function (array $range) use ($bindings): DhcpRange {
        $total = (int) $range['total_addresses'];
        $used = $this->countBindingsInRange($bindings, $range['range_from'], $range['range_to']);
        $utilisation = $total > 0 ? $used / $total : 0.0;

        return new DhcpRange(
            interface: $range['name'],
            type: 'ipv4',
            subnet: $range['subnet'],
            rangeFrom: $range['range_from'],
            rangeTo: $range['range_to'],
            prefix: null,
            gateway: $range['gateway'] !== '' ? $range['gateway'] : null,
            description: null,
            totalAddresses: $total,
            usedAddresses: $used,
            utilisation: $utilisation,
        );
    });
```

Add private helper method:

```php
/**
 * @param  array<int, array{ip: string}>  $bindings
 */
private function countBindingsInRange(array $bindings, string $rangeFrom, string $rangeTo): int
{
    $start = ip2long($rangeFrom);
    $end = ip2long($rangeTo);
    if ($start === false || $end === false) {
        return 0;
    }

    return count(array_filter($bindings, function (array $binding) use ($start, $end): bool {
        $ip = ip2long($binding['ip'] ?? '');
        return $ip !== false && $ip >= $start && $ip <= $end;
    }));
}
```

- [ ] **Step 4: Add totalAddresses for IPv6 ranges from prefix**

In the IPv6 range construction within `getRanges()`:

```php
$ipv6Ranges = collect($ipv6Config['pools'])
    ->map(function (array $pool): DhcpRange {
        $prefix = $pool['prefix'] !== '' ? $pool['prefix'] : null;
        $totalAddresses = null;
        if ($prefix !== null && preg_match('#/(\d+)$#', $prefix, $m)) {
            $bits = 128 - (int) $m[1];
            // Cap display at a reasonable number — IPv6 /64 has 2^64 addresses
            $totalAddresses = $bits <= 32 ? (1 << $bits) : null;
        }

        return new DhcpRange(
            interface: $pool['name'],
            type: 'ipv6',
            subnet: null,
            rangeFrom: null,
            rangeTo: null,
            prefix: $prefix,
            gateway: null,
            description: null,
            totalAddresses: $totalAddresses,
        );
    });
```

- [ ] **Step 5: Run tests**

Run: `php artisan test --filter="CiscoDhcpServiceTest"`
Expected: ALL PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Cisco/CiscoDhcpService.php tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php
git commit -m "fix(dhcp): enrich Cisco ranges with used IP counts and IPv6 total sizes"
```

---

### Task 8: DHCP Ranges missing an IPv6 range from the switch

**Bug:** One of the IPv6 ranges configured on the production switch isn't being detected.

**Root cause:** Likely a parsing issue in `IosOutputParser::parseDhcpv6PoolConfig()` — the parser may not handle all pool configuration formats (e.g. pools with `address prefix` directive vs `prefix-delegation` vs different CLI syntax).

**Files:**
- Modify: `app/Services/NetworkSwitch/IosOutputParser.php` (parseDhcpv6PoolConfig)
- Test: `tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`

- [ ] **Step 1: Investigate — connect to production switch and capture output**

SSH to the production switch (10.30.0.1) and run:

```
show running-config | section ipv6 dhcp pool
show ipv6 dhcp pool
```

Capture the raw output. Compare it against what `parseDhcpv6PoolConfig` expects to parse.

- [ ] **Step 2: Write failing test with the real output**

In `tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`, add a test case using the captured output that includes the missing pool:

```php
public function test_parse_dhcpv6_pool_config_detects_all_pools(): void
{
    $parser = new IosOutputParser();
    $output = "... (paste captured output from step 1) ...";
    
    $result = $parser->parseDhcpv6PoolConfig($output);
    
    // Assert all pools are found, including the missing one
    $poolNames = array_column($result['pools'], 'name');
    $this->assertContains('MISSING_POOL_NAME', $poolNames);
}
```

- [ ] **Step 3: Fix the parser**

In `app/Services/NetworkSwitch/IosOutputParser.php`, update `parseDhcpv6PoolConfig()` to handle the format of the missing pool. The exact fix depends on what the raw output reveals.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="IosOutputParserDhcpTest"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/NetworkSwitch/IosOutputParser.php tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php
git commit -m "fix(parser): detect all IPv6 DHCP pool formats from switch output"
```

---

### Task 9: DHCP lease shows IP without MAC address

**Bug:** IP 10.30.0.101 shows no MAC on the DHCP leases list, but the MAC IS available on the IP detail page.

**Root cause:** The DhcpLease record for this IP has `mac_address_id = null` (the MAC couldn't be extracted from the DHCP binding's client-ID). But the IP-MAC association exists via other sources (switch port MAC table, DHCP snooping).

**Files:**
- Modify: `app/Http/Controllers/Admin/DhcpController.php:52-60` (leases method)
- Test: `tests/Feature/Admin/DhcpControllerTest.php`

- [ ] **Step 1: Write failing test**

In `tests/Feature/Admin/DhcpControllerTest.php`:

```php
public function test_leases_shows_mac_from_ip_association_when_lease_has_no_mac(): void
{
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $ip = IpAddress::factory()->create(['address' => '10.30.0.101']);
    $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
    $ip->macAddresses()->attach($mac->id, ['source' => 'arp', 'last_seen_at' => now()]);

    CapabilityAssignment::create(['capability' => 'dhcp', 'integration' => 'cisco']);

    DhcpLease::create([
        'integration' => 'cisco',
        'ip_address_id' => $ip->id,
        'mac_address_id' => null,  // No MAC on the lease itself
        'hostname' => 'test-host',
    ]);

    $response = $this->actingAs($admin)->get(route('admin.dhcp.leases'));
    $response->assertOk();

    $page = $response->viewData('page');
    $leases = $page['props']['leases'];
    $lease = collect($leases)->firstWhere('ip', '10.30.0.101');

    $this->assertEquals('aa:bb:cc:dd:ee:ff', $lease['mac']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter="test_leases_shows_mac_from_ip_association_when_lease_has_no_mac"`
Expected: FAIL — `$lease['mac']` is empty string

- [ ] **Step 3: Fall back to IP's MAC address**

In `app/Http/Controllers/Admin/DhcpController.php`, in the `leases()` method, update the eager loading and mapping:

```php
$leases = DhcpLease::where('integration', $integration)
    ->with(['ipAddress.macAddresses', 'macAddress'])
    ->get()
    ->map(fn (DhcpLease $lease): array => [
        'ip' => $lease->ipAddress->address ?? '',
        'mac' => $lease->macAddress->mac_address
            ?? $lease->ipAddress?->macAddresses?->sortByDesc('pivot.last_seen_at')->first()?->mac_address
            ?? '',
        'hostname' => $lease->hostname ?? '',
        'expires' => $lease->expires_at?->toIso8601String() ?? '',
    ])
    ->values()
    ->all();
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="DhcpControllerTest"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/DhcpController.php tests/Feature/Admin/DhcpControllerTest.php
git commit -m "fix(dhcp): fall back to IP-MAC association when lease has no MAC"
```

---

### Task 10: IPv6 Pool leases not filtering by network

**Bug:** Clicking an IPv6 pool to view its leases shows ALL leases instead of filtering to the network.

**Root cause:** The `Leases.vue` filtering uses `isIpInRange(ip, start, end)`, but IPv6 ranges from Cisco have no `start`/`end` (only a `prefix`). The `DhcpController::leases()` passes `range_from`/`range_to` which are null for IPv6 ranges, so the filter condition `range.start && range.end` is false and no filtering occurs.

**Files:**
- Modify: `app/Http/Controllers/Admin/DhcpController.php:67-75` (pass prefix in ranges)
- Modify: `resources/js/Pages/Admin/Dhcp/Leases.vue:52-58,128-140` (IPv6 prefix filtering)
- Test: `resources/js/Pages/Admin/Dhcp/__tests__/Leases.test.js` (create or update)
- Test: `tests/Feature/Admin/DhcpControllerTest.php`

- [ ] **Step 1: Add prefix to the ranges passed to leases page**

In `app/Http/Controllers/Admin/DhcpController.php`, in the `leases()` method, update the ranges mapping:

```php
$ranges = DhcpRangeRecord::where('integration', $integration)
    ->get()
    ->map(fn (DhcpRangeRecord $range): array => [
        'network' => $range->subnet ?: $range->prefix,
        'start' => $range->range_from ?: null,
        'end' => $range->range_to ?: null,
        'prefix' => $range->prefix,
        'type' => $range->type,
    ])
    ->values()
    ->all();
```

- [ ] **Step 2: Update Leases.vue to handle IPv6 prefix filtering**

In `resources/js/Pages/Admin/Dhcp/Leases.vue`, update the `isIpInRange` function to handle IPv6 prefix matching:

```javascript
function isIpInRange(ip, start, end) {
    if (!ip) return false;

    // IPv6 prefix-based matching
    if (ip.includes(':') && (!start || !end)) {
        return false;
    }

    if (ip.includes(':')) {
        return ip >= start && ip <= end;
    }

    const ipNum = ipToNumber(ip);
    const startNum = ipToNumber(start);
    const endNum = ipToNumber(end);

    return ipNum >= startNum && ipNum <= endNum;
}

function isIpInPrefix(ip, prefix) {
    if (!ip || !prefix) return false;
    // Match by network prefix — e.g. "2001:db8:1::/64" matches "2001:db8:1::5"
    const networkPart = prefix.replace(/\/\d+$/, '').replace(/::?$/, '');
    const normalizedIp = ip.toLowerCase();
    const normalizedNet = networkPart.toLowerCase();
    return normalizedIp.startsWith(normalizedNet);
}
```

Update the filtering in both `totalFilteredCount` and `filteredLeases` computed properties:

```javascript
const selectedRange = filterValues.value.range;
if (selectedRange) {
    const range = props.ranges.find((r) => r.network === selectedRange);
    if (range) {
        if (range.start && range.end) {
            filtered = filtered.filter((lease) => isIpInRange(lease.ip, range.start, range.end));
        } else if (range.prefix) {
            filtered = filtered.filter((lease) => isIpInPrefix(lease.ip, range.prefix));
        }
    }
}
```

- [ ] **Step 3: Write Vitest test**

Create `resources/js/Pages/Admin/Dhcp/__tests__/Leases.test.js` if it doesn't exist, or add:

```javascript
import { describe, it, expect } from 'vitest';

// Extract the isIpInPrefix logic for unit testing
function isIpInPrefix(ip, prefix) {
    if (!ip || !prefix) return false;
    const networkPart = prefix.replace(/\/\d+$/, '').replace(/::?$/, '');
    return ip.toLowerCase().startsWith(networkPart.toLowerCase());
}

describe('IPv6 prefix filtering', () => {
    it('matches IP within prefix', () => {
        expect(isIpInPrefix('2001:db8:1::5', '2001:db8:1::/64')).toBe(true);
    });
    it('rejects IP outside prefix', () => {
        expect(isIpInPrefix('2001:db8:2::5', '2001:db8:1::/64')).toBe(false);
    });
    it('handles null inputs', () => {
        expect(isIpInPrefix(null, '2001:db8:1::/64')).toBe(false);
        expect(isIpInPrefix('2001:db8:1::5', null)).toBe(false);
    });
});
```

- [ ] **Step 4: Run tests**

Run: `npx vitest run --reporter=verbose resources/js/Pages/Admin/Dhcp/__tests__/Leases.test.js`
Run: `php artisan test --filter="DhcpControllerTest"`
Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/DhcpController.php resources/js/Pages/Admin/Dhcp/Leases.vue resources/js/Pages/Admin/Dhcp/__tests__/Leases.test.js
git commit -m "fix(dhcp): IPv6 leases filter by prefix when range start/end unavailable"
```

---

### Task 11: Audit log should be more human-readable

**Bug:** Audit log entries display raw action strings (e.g. `mac.linked_ip`) with subject/related as type#ID. Users want textual descriptions like "Linked aa:bb:cc to 10.30.0.1 via DHCP".

**Root cause:** The AuditLog model has no description generation. The Vue template renders raw action, subject_type#id, and related_type#id columns.

**Files:**
- Create: `app/Services/AuditLog/AuditLogDescriptionGenerator.php`
- Modify: `app/Http/Controllers/Admin/AuditLogController.php:73` (add description field)
- Modify: `resources/js/Pages/Admin/AuditLog/Index.vue` (add description column)
- Test: `tests/Unit/Services/AuditLog/AuditLogDescriptionGeneratorTest.php`

- [ ] **Step 1: Write failing tests for description generator**

Create `tests/Unit/Services/AuditLog/AuditLogDescriptionGeneratorTest.php`:

```php
<?php

namespace Tests\Unit\Services\AuditLog;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Services\AuditLog\AuditLogDescriptionGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogDescriptionGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_description_for_mac_linked_ip(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'aa:bb:cc:dd:ee:ff']);
        $ip = IpAddress::factory()->create(['address' => '10.30.0.1']);

        $log = AuditLog::create([
            'action' => 'mac.linked_ip',
            'subject_type' => $mac->getMorphClass(),
            'subject_id' => $mac->id,
            'related_type' => $ip->getMorphClass(),
            'related_id' => $ip->id,
            'process' => 'dhcp',
        ]);
        $log->load(['subject', 'related']);

        $description = AuditLogDescriptionGenerator::generate($log);

        $this->assertStringContainsString('aa:bb:cc:dd:ee:ff', $description);
        $this->assertStringContainsString('10.30.0.1', $description);
    }

    public function test_generates_description_for_user_login(): void
    {
        $user = User::factory()->create(['nickname' => 'TestUser']);

        $log = AuditLog::create([
            'action' => 'user.login',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
            'process' => 'auth',
            'metadata' => ['email' => 'test@example.com'],
        ]);
        $log->load(['subject', 'related']);

        $description = AuditLogDescriptionGenerator::generate($log);

        $this->assertStringContainsString('logged in', $description);
    }

    public function test_generates_fallback_for_unknown_action(): void
    {
        $log = AuditLog::create([
            'action' => 'some.unknown.action',
            'process' => 'system',
        ]);
        $log->load(['subject', 'related']);

        $description = AuditLogDescriptionGenerator::generate($log);

        $this->assertStringContainsString('some.unknown.action', $description);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="AuditLogDescriptionGeneratorTest"`
Expected: FAIL — class does not exist

- [ ] **Step 3: Create AuditLogDescriptionGenerator**

Create `app/Services/AuditLog/AuditLogDescriptionGenerator.php`:

```php
<?php

namespace App\Services\AuditLog;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\User;

final class AuditLogDescriptionGenerator
{
    public static function generate(AuditLog $log): string
    {
        $method = str_replace('.', '_', $log->action);
        if (method_exists(static::class, $method)) {
            return static::$method($log);
        }

        return static::fallback($log);
    }

    private static function subjectLabel(AuditLog $log): string
    {
        $subject = $log->subject;
        return match (true) {
            $subject instanceof MacAddress => $subject->mac_address,
            $subject instanceof IpAddress => $subject->address,
            $subject instanceof User => $subject->nickname ?? $subject->email,
            $subject instanceof SwitchConfig => $subject->name ?? $subject->hostname,
            default => class_basename($log->subject_type ?? 'Unknown') . ' #' . $log->subject_id,
        };
    }

    private static function relatedLabel(AuditLog $log): string
    {
        $related = $log->related;
        return match (true) {
            $related instanceof MacAddress => $related->mac_address,
            $related instanceof IpAddress => $related->address,
            $related instanceof User => $related->nickname ?? $related->email,
            $related instanceof SwitchConfig => $related->name ?? $related->hostname,
            default => class_basename($log->related_type ?? 'Unknown') . ' #' . $log->related_id,
        };
    }

    private static function mac_linked_ip(AuditLog $log): string
    {
        return sprintf('Linked %s to %s via %s', static::subjectLabel($log), static::relatedLabel($log), $log->process);
    }

    private static function mac_unlinked_ip(AuditLog $log): string
    {
        return sprintf('Unlinked %s from %s', static::subjectLabel($log), static::relatedLabel($log));
    }

    private static function user_login(AuditLog $log): string
    {
        return sprintf('%s logged in', static::subjectLabel($log));
    }

    private static function user_login_failed(AuditLog $log): string
    {
        $email = $log->metadata['email'] ?? 'unknown';
        return sprintf('Failed login attempt for %s', $email);
    }

    private static function user_logout(AuditLog $log): string
    {
        return sprintf('%s logged out', static::subjectLabel($log));
    }

    private static function user_created(AuditLog $log): string
    {
        return sprintf('Created user %s', static::subjectLabel($log));
    }

    private static function switch_updated(AuditLog $log): string
    {
        return sprintf('Updated switch %s', static::subjectLabel($log));
    }

    private static function switch_created(AuditLog $log): string
    {
        return sprintf('Created switch %s', static::subjectLabel($log));
    }

    private static function switch_deleted(AuditLog $log): string
    {
        return sprintf('Deleted switch #%s', $log->subject_id);
    }

    private static function ip_internet_enabled(AuditLog $log): string
    {
        return sprintf('Enabled internet for %s', static::subjectLabel($log));
    }

    private static function ip_internet_disabled(AuditLog $log): string
    {
        return sprintf('Disabled internet for %s', static::subjectLabel($log));
    }

    private static function ip_rate_limit_enabled(AuditLog $log): string
    {
        return sprintf('Enabled rate limit for %s', static::subjectLabel($log));
    }

    private static function ip_rate_limit_disabled(AuditLog $log): string
    {
        return sprintf('Disabled rate limit for %s', static::subjectLabel($log));
    }

    private static function ip_dns_filtering_enabled(AuditLog $log): string
    {
        return sprintf('Enabled DNS filtering for %s', static::subjectLabel($log));
    }

    private static function ip_dns_filtering_disabled(AuditLog $log): string
    {
        return sprintf('Disabled DNS filtering for %s', static::subjectLabel($log));
    }

    private static function session_expired(AuditLog $log): string
    {
        return sprintf('Session expired for %s', static::subjectLabel($log));
    }

    private static function settings_updated(AuditLog $log): string
    {
        $key = $log->metadata['key'] ?? 'setting';
        return sprintf('Updated %s', $key);
    }

    private static function fallback(AuditLog $log): string
    {
        $parts = [str_replace(['_', '.'], ' ', ucfirst($log->action))];

        if ($log->subject_type !== null) {
            $parts[] = static::subjectLabel($log);
        }

        if ($log->related_type !== null) {
            $parts[] = '→ ' . static::relatedLabel($log);
        }

        return implode(' ', $parts);
    }
}
```

- [ ] **Step 4: Add description to AuditLogController response**

In `app/Http/Controllers/Admin/AuditLogController.php`, update the transform closure:

```php
use App\Services\AuditLog\AuditLogDescriptionGenerator;

// In the transform:
$logs->getCollection()->transform(fn (AuditLog $log): array => [
    'id' => $log->id,
    'action' => $log->action,
    'description' => AuditLogDescriptionGenerator::generate($log),
    // ... rest unchanged
]);
```

- [ ] **Step 5: Display description in Vue template**

In `resources/js/Pages/Admin/AuditLog/Index.vue`, add a Description column after the Action column definition and template row. In the columns definition array (check existing), add:

```javascript
{ key: 'description', label: 'Description', sortable: false },
```

Add in the row template, after the action `<td>`:

```html
<td data-testid="audit-log-description" class="text-[13px] text-[var(--color-text)]">
    {{ row.description }}
</td>
```

- [ ] **Step 6: Run tests**

Run: `php artisan test --filter="AuditLogDescriptionGeneratorTest|AuditLogControllerTest"`
Expected: ALL PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/AuditLog/AuditLogDescriptionGenerator.php app/Http/Controllers/Admin/AuditLogController.php resources/js/Pages/Admin/AuditLog/Index.vue tests/Unit/Services/AuditLog/AuditLogDescriptionGeneratorTest.php
git commit -m "feat(audit): add human-readable descriptions to audit log entries"
```

---

### Verification

After all tasks are complete:

- [ ] **Run full test suite:** `php artisan test --parallel`
- [ ] **Run Pint:** `./vendor/bin/pint`
- [ ] **Run PHPStan:** `./vendor/bin/phpstan analyse --level=8`
- [ ] **Run Rector:** `./vendor/bin/rector process --dry-run`
- [ ] **Run JS lint:** `npx eslint resources/js/ --fix`
- [ ] **Run JS format:** `npx prettier --write resources/js/`
- [ ] **Build frontend:** `npm run build`
- [ ] **Verify on production** (via Playwright) that all 12 issues are resolved
