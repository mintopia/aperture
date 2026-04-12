# Phase 3: MAC Tracking, Xbox Auto-enable, IPv6 Automation, Cache Layer — Implementation Plan

> **For agentic workers:** REQUIRED: Use the `subagent-driven-development` agent (recommended) or `executing-plans` agent to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add MAC-based device tracking as the foundation for three features: auto-allow known devices via MAC, Xbox/console auto-enable via OUI lookup, IPv6 auto-detection, plus a cache layer for LibreNMS API calls and DI cleanup in IpAddress.

**Architecture:** Layered approach — Task 1 creates the MacAddress model and schema. Task 2 adds MAC normalization. Task 3 builds the MAC resolver service. Task 4 wires MAC resolution into the allow flow. Task 5 builds the background scan job. Task 6 adds Xbox OUI detection. Task 7 adds IPv6 auto-detection. Task 8 builds the cached network inventory decorator. Task 9 replaces IpAddress::getLNMSData(). Task 10 fixes DI in IpAddress shut/unshut. Task 11 runs quality gates.

**Tech Stack:** Laravel 12, PHPUnit, Mockery, Guzzle MockHandler, Vue 3, Vitest

**Spec:** `docs/superpowers/specs/2026-04-12-phase3-mac-ipv6-cache-design.md`

---

## File Structure

### New Files

| File | Responsibility |
|------|---------------|
| `database/migrations/xxxx_create_mac_addresses_table.php` | Schema for mac_addresses table |
| `database/migrations/xxxx_add_mac_address_id_to_ip_addresses_table.php` | FK on ip_addresses |
| `app/Models/MacAddress.php` | MacAddress Eloquent model |
| `database/factories/MacAddressFactory.php` | Factory for MacAddress |
| `app/Casts/NormalizeMacAddress.php` | Eloquent cast normalizing MACs to `AA:BB:CC:DD:EE:FF` |
| `app/Services/Interfaces/MacAddressResolverInterface.php` | Interface for MAC resolution |
| `app/Services/MacAddressResolver.php` | Resolves IP→MAC via DHCP and ARP |
| `app/Services/CachedNetworkInventoryService.php` | Caching decorator for NetworkInventoryInterface |
| `app/Jobs/ScanNetworkDevices.php` | Background job: resolve MACs, auto-allow, Xbox detect |
| `tests/Unit/Casts/NormalizeMacAddressTest.php` | Unit tests for MAC normalization |
| `tests/Unit/Models/MacAddressTest.php` | Unit tests for MacAddress model |
| `tests/Unit/Services/MacAddressResolverTest.php` | Unit tests for MAC resolver |
| `tests/Unit/Services/CachedNetworkInventoryServiceTest.php` | Unit tests for cache decorator |
| `tests/Feature/Jobs/ScanNetworkDevicesTest.php` | Feature tests for background scan |
| `tests/Feature/MacTrackingIntegrationTest.php` | Integration tests for MAC→allow flow |
| `tests/js/ipv6-detection.spec.js` | Vitest for IPv6 JS detection logic |

### Modified Files

| File | Change |
|------|--------|
| `config/aperture.php` | Add `auto_allow` and `ipv6` config sections |
| `app/Models/IpAddress.php` | Add `macAddress()` relationship, replace `getLNMSData()`, fix `shutPort()`/`unshutPort()` DI |
| `app/Models/User.php` | Add `macAddresses()` relationship |
| `app/Providers/AppServiceProvider.php` | Bind MacAddressResolverInterface, CachedNetworkInventoryService, NetworkInventoryInterface |
| `app/Console/Kernel.php` | Schedule ScanNetworkDevices every 5 minutes |
| `app/Services/Interfaces/NetworkInventoryInterface.php` | Add `getIpv6Neighbors()` method |
| `app/Services/LibreNmsService.php` | Implement `getIpv6Neighbors()` |
| Portal Vue component | Add IPv6 detection JS fetch on page load |
| `tests/Unit/Config/ApertureConfigTest.php` | Tests for new config keys |
| `tests/Unit/Providers/AppServiceProviderTest.php` | Tests for new bindings |
| Existing IP/User tests | Update for DI changes in IpAddress |

---

## Dependency Graph

```
Task 1 (Schema) ──→ Task 2 (MAC Cast) ──→ Task 3 (MacAddress Model) ──→ Task 4 (MAC Resolver) ──→ Task 5 (Wire into allow()) ──→ Task 6 (Background Scan) ──→ Task 7 (Xbox OUI)
                                                                                                                                      │
Task 8 (IPv6 detection) ←── depends on Task 5 (needs MAC resolver wired in)                                                           │
                                                                                                                                      │
Task 9 (Cached Inventory) ──→ Task 10 (Replace getLNMSData) ──→ Task 11 (Fix shut/unshut DI)  ←── independent of Tasks 6-8            │
                                                                                                                                      │
Task 12 (Quality Gates) ←── depends on all above ─────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## Risk Areas and Mitigations

### 1. MAC Resolution Timing
**Risk:** At allow-time, the DHCP lease or ARP entry may not exist yet (user just connected).
**Mitigation:** Attempt resolution at allow-time but don't block on failure. Background job retries every 5 minutes for unlinked IPs.

### 2. MAC Format Inconsistency
**Risk:** MACs come in different formats from different sources (DHCP uses `aa:bb:cc:dd:ee:ff`, Cisco uses `aabb.ccdd.eeff`, LibreNMS uses `aa:bb:cc:dd:ee:ff`).
**Mitigation:** The `NormalizeMacAddress` cast normalizes all formats to `AA:BB:CC:DD:EE:FF` on both get and set. The resolver normalizes before lookup.

### 3. Auto-allow Race Conditions
**Risk:** Background job and allow-time resolution could create duplicate MacAddress records.
**Mitigation:** Use `firstOrCreate()` with the normalized MAC as the key. Database UNIQUE constraint on `mac_address` catches any race.

### 4. IPv6 Detection API Availability
**Risk:** External IPv6 API may not be reachable if user doesn't have IPv6, or the endpoint is down.
**Mitigation:** JS fetch uses a timeout and silently fails. IPv6 detection is optional — the portal works fine without it.

### 5. Single Switch Assumption
**Risk:** `shutPort()`/`unshutPort()` DI fix assumes a single configured switch. LibreNMS data may reference multiple switches.
**Mitigation:** Document the assumption. The `NetworkSwitchInterface` singleton targets the configured switch. Multi-switch support can be added later if needed.

---

### Task 1: Config Changes

**Files:**
- Modify: `config/aperture.php`
- Modify: `tests/Unit/Config/ApertureConfigTest.php`

- [ ] **Step 1: Write failing tests for new config keys**

Add to `tests/Unit/Config/ApertureConfigTest.php`:

```php
public function test_auto_allow_enabled_defaults_to_false(): void
{
    $this->assertFalse(config('aperture.auto_allow.enabled'));
}

public function test_auto_allow_oui_prefixes_defaults_to_array(): void
{
    $this->assertIsArray(config('aperture.auto_allow.oui_prefixes'));
}

public function test_auto_allow_scan_interval_defaults_to_five(): void
{
    $this->assertEquals(5, config('aperture.auto_allow.scan_interval'));
}

public function test_ipv6_detection_enabled_defaults_to_false(): void
{
    $this->assertFalse(config('aperture.ipv6.detection_enabled'));
}

public function test_ipv6_detection_endpoint_defaults_to_null(): void
{
    $this->assertNull(config('aperture.ipv6.detection_endpoint'));
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ApertureConfigTest`
Expected: FAIL — config keys don't exist yet

- [ ] **Step 3: Add config keys to aperture.php**

After the `dns` block in `config/aperture.php`, add:

```php
'auto_allow' => [
    'enabled' => env('APERTURE_AUTO_ALLOW_ENABLED', false),
    'oui_prefixes' => array_filter(explode(',', env('APERTURE_AUTO_ALLOW_OUI_PREFIXES', implode(',', [
        '98:5F:D3',
        '7C:ED:8D',
        '00:50:F2',
        '28:18:78',
        'C8:3F:26',
        '60:45:BD',
        '94:9A:A9',
        '48:4D:7E',
        'B4:09:31',
        'DC:B4:C4',
    ])))),
    'scan_interval' => (int) env('APERTURE_AUTO_ALLOW_SCAN_INTERVAL', 5),
],
'ipv6' => [
    'detection_enabled' => env('APERTURE_IPV6_DETECTION_ENABLED', false),
    'detection_endpoint' => env('APERTURE_IPV6_DETECTION_ENDPOINT'),
],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ApertureConfigTest`
Expected: PASS

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add config/aperture.php tests/Unit/Config/ApertureConfigTest.php
git commit -m "feat: add auto_allow and ipv6 config keys"
```

---

### Task 2: NormalizeMacAddress Cast

**Files:**
- Create: `app/Casts/NormalizeMacAddress.php`
- Create: `tests/Unit/Casts/NormalizeMacAddressTest.php`

- [ ] **Step 1: Write failing tests for MAC normalization**

```php
// tests/Unit/Casts/NormalizeMacAddressTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Casts;

use App\Casts\NormalizeMacAddress;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

class NormalizeMacAddressTest extends TestCase
{
    private NormalizeMacAddress $cast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cast = new NormalizeMacAddress;
    }

    private function model(): Model
    {
        return new class extends Model {};
    }

    public function test_normalizes_colon_separated_lowercase(): void
    {
        $result = $this->cast->set($this->model(), 'mac', 'aa:bb:cc:dd:ee:ff', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_normalizes_colon_separated_uppercase(): void
    {
        $result = $this->cast->set($this->model(), 'mac', 'AA:BB:CC:DD:EE:FF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_normalizes_dash_separated(): void
    {
        $result = $this->cast->set($this->model(), 'mac', 'AA-BB-CC-DD-EE-FF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_normalizes_cisco_format(): void
    {
        $result = $this->cast->set($this->model(), 'mac', 'aabb.ccdd.eeff', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_normalizes_bare_hex(): void
    {
        $result = $this->cast->set($this->model(), 'mac', 'aabbccddeeff', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_normalizes_mixed_case(): void
    {
        $result = $this->cast->set($this->model(), 'mac', 'aA:bB:cC:dD:eE:fF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_get_returns_value_unchanged(): void
    {
        $result = $this->cast->get($this->model(), 'mac', 'AA:BB:CC:DD:EE:FF', []);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_set_returns_null_for_null(): void
    {
        $result = $this->cast->set($this->model(), 'mac', null, []);
        $this->assertNull($result);
    }

    public function test_get_returns_null_for_null(): void
    {
        $result = $this->cast->get($this->model(), 'mac', null, []);
        $this->assertNull($result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=NormalizeMacAddressTest`
Expected: FAIL — class doesn't exist

- [ ] **Step 3: Implement NormalizeMacAddress cast**

```php
// app/Casts/NormalizeMacAddress.php
<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<string|null, string|null>
 */
class NormalizeMacAddress implements CastsAttributes
{
    /**
     * @param  Model  $model
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value;
    }

    /**
     * @param  Model  $model
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        // Strip all delimiters (colons, dashes, dots)
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $value));

        // Insert colons every 2 characters
        return implode(':', str_split($hex, 2));
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=NormalizeMacAddressTest`
Expected: PASS

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Casts/NormalizeMacAddress.php tests/Unit/Casts/NormalizeMacAddressTest.php
git commit -m "feat: add NormalizeMacAddress Eloquent cast"
```

---

### Task 3: MacAddress Model & Schema

**Files:**
- Create: `database/migrations/xxxx_create_mac_addresses_table.php` (use `php artisan make:migration`)
- Create: `database/migrations/xxxx_add_mac_address_id_to_ip_addresses_table.php`
- Create: `app/Models/MacAddress.php` (use `php artisan make:model`)
- Create: `database/factories/MacAddressFactory.php`
- Create: `tests/Unit/Models/MacAddressTest.php`
- Modify: `app/Models/IpAddress.php` — add `macAddress()` relationship
- Modify: `app/Models/User.php` — add `macAddresses()` relationship

- [ ] **Step 1: Create migrations**

Run:
```bash
php artisan make:migration create_mac_addresses_table --no-interaction
php artisan make:migration add_mac_address_id_to_ip_addresses_table --no-interaction
```

- [ ] **Step 2: Write the mac_addresses migration**

```php
public function up(): void
{
    Schema::create('mac_addresses', function (Blueprint $table) {
        $table->id();
        $table->string('mac_address', 17)->unique();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->string('source'); // auth, xbox, admin
        $table->boolean('allowed')->default(false);
        $table->string('description')->nullable();
        $table->timestamp('allowed_at')->nullable();
        $table->timestamps();

        $table->index('allowed');
        $table->index('source');
    });
}

public function down(): void
{
    Schema::dropIfExists('mac_addresses');
}
```

- [ ] **Step 3: Write the ip_addresses FK migration**

```php
public function up(): void
{
    Schema::table('ip_addresses', function (Blueprint $table) {
        $table->foreignId('mac_address_id')->nullable()->constrained('mac_addresses')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('ip_addresses', function (Blueprint $table) {
        $table->dropForeign(['mac_address_id']);
        $table->dropColumn('mac_address_id');
    });
}
```

- [ ] **Step 4: Write failing tests for MacAddress model**

```php
// tests/Unit/Models/MacAddressTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MacAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_mac_address(): void
    {
        $mac = MacAddress::factory()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'source' => 'auth',
        ]);

        $this->assertDatabaseHas('mac_addresses', [
            'id' => $mac->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF', // normalized
            'source' => 'auth',
        ]);
    }

    public function test_mac_address_is_normalized_on_save(): void
    {
        $mac = MacAddress::factory()->create([
            'mac_address' => 'aabb.ccdd.eeff',
        ]);

        $this->assertEquals('AA:BB:CC:DD:EE:FF', $mac->mac_address);
    }

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($mac->user->is($user));
    }

    public function test_has_many_ip_addresses(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create(['mac_address_id' => $mac->id]);

        $this->assertTrue($mac->ipAddresses->contains($ip));
    }

    public function test_user_has_many_mac_addresses(): void
    {
        $user = User::factory()->create();
        $mac = MacAddress::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($user->macAddresses->contains($mac));
    }

    public function test_ip_address_belongs_to_mac_address(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create(['mac_address_id' => $mac->id]);

        $this->assertTrue($ip->macAddress->is($mac));
    }

    public function test_mac_address_without_user(): void
    {
        $mac = MacAddress::factory()->create([
            'user_id' => null,
            'source' => 'xbox',
        ]);

        $this->assertNull($mac->user);
        $this->assertEquals('xbox', $mac->source);
    }

    public function test_allowed_scope(): void
    {
        MacAddress::factory()->create(['allowed' => true]);
        MacAddress::factory()->create(['allowed' => false]);

        $this->assertEquals(1, MacAddress::whereAllowed(true)->count());
    }

    public function test_mac_address_unique_constraint(): void
    {
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    }
}
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MacAddressTest`
Expected: FAIL — model doesn't exist

- [ ] **Step 6: Create MacAddress model**

```php
// app/Models/MacAddress.php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\NormalizeMacAddress;
use Database\Factories\MacAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MacAddress extends Model
{
    /** @use HasFactory<MacAddressFactory> */
    use HasFactory;

    protected $fillable = [
        'mac_address',
        'user_id',
        'source',
        'allowed',
        'description',
        'allowed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mac_address' => NormalizeMacAddress::class,
            'allowed' => 'boolean',
            'allowed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<IpAddress, $this> */
    public function ipAddresses(): HasMany
    {
        return $this->hasMany(IpAddress::class);
    }
}
```

- [ ] **Step 7: Create MacAddressFactory**

```php
// database/factories/MacAddressFactory.php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MacAddress> */
class MacAddressFactory extends Factory
{
    protected $model = MacAddress::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'mac_address' => $this->faker->macAddress(),
            'source' => 'auth',
            'allowed' => false,
            'description' => null,
            'allowed_at' => null,
        ];
    }

    public function allowed(): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed' => true,
            'allowed_at' => now(),
        ]);
    }

    public function xbox(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'xbox',
            'allowed' => true,
            'allowed_at' => now(),
            'description' => 'Xbox Console',
        ]);
    }
}
```

- [ ] **Step 8: Add relationships to IpAddress and User**

In `app/Models/IpAddress.php`, add:

```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @return BelongsTo<MacAddress, $this> */
public function macAddress(): BelongsTo
{
    return $this->belongsTo(MacAddress::class);
}
```

In `app/Models/User.php`, add:

```php
/** @return HasMany<MacAddress, $this> */
public function macAddresses(): HasMany
{
    return $this->hasMany(MacAddress::class);
}
```

- [ ] **Step 9: Run migrations and tests**

Run: `php artisan migrate --no-interaction`
Run: `php artisan test --compact --filter=MacAddressTest`
Expected: PASS

- [ ] **Step 10: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 11: Commit**

```bash
git add database/migrations/ app/Models/MacAddress.php database/factories/MacAddressFactory.php app/Models/IpAddress.php app/Models/User.php tests/Unit/Models/MacAddressTest.php
git commit -m "feat: add MacAddress model, migrations, factory, and relationships"
```

---

### Task 4: MacAddressResolver Service

**Files:**
- Create: `app/Services/Interfaces/MacAddressResolverInterface.php`
- Create: `app/Services/MacAddressResolver.php`
- Create: `tests/Unit/Services/MacAddressResolverTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Write failing tests for MacAddressResolver**

```php
// tests/Unit/Services/MacAddressResolverTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\MacAddressResolver;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class MacAddressResolverTest extends TestCase
{
    public function test_resolves_mac_from_dhcp_lease(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('10.0.0.10')
            ->once()
            ->andReturn([
                'ip' => '10.0.0.10',
                'mac' => 'aa:bb:cc:dd:ee:ff',
                'hostname' => 'desktop-1',
                'expires' => '2026-04-13 00:00:00',
            ]);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('10.0.0.10');

        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_falls_back_to_arp_when_dhcp_returns_null(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('10.0.0.10')
            ->once()
            ->andReturn(null);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')
            ->once()
            ->andReturn(collect([
                ['ip' => '10.0.0.10', 'mac' => 'aa:bb:cc:dd:ee:ff'],
                ['ip' => '10.0.0.11', 'mac' => '11:22:33:44:55:66'],
            ]));

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('10.0.0.10');

        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_returns_null_when_both_sources_fail(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('10.0.0.99')
            ->once()
            ->andReturn(null);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')
            ->once()
            ->andReturn(collect([]));

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('10.0.0.99');

        $this->assertNull($result);
    }

    public function test_normalizes_mac_from_dhcp(): void
    {
        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLease')
            ->with('10.0.0.10')
            ->once()
            ->andReturn([
                'ip' => '10.0.0.10',
                'mac' => 'aabb.ccdd.eeff', // Cisco format
                'hostname' => 'desktop-1',
                'expires' => '2026-04-13 00:00:00',
            ]);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);

        $resolver = new MacAddressResolver($dhcp, $inventory);
        $result = $resolver->resolveIpToMac('10.0.0.10');

        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_is_bound_in_container(): void
    {
        $this->app->instance(DhcpInterface::class, Mockery::mock(DhcpInterface::class));
        $this->app->instance(NetworkInventoryInterface::class, Mockery::mock(NetworkInventoryInterface::class));

        $instance = $this->app->make(MacAddressResolverInterface::class);
        $this->assertInstanceOf(MacAddressResolver::class, $instance);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MacAddressResolverTest`
Expected: FAIL — class doesn't exist

- [ ] **Step 3: Create interface**

```php
// app/Services/Interfaces/MacAddressResolverInterface.php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

interface MacAddressResolverInterface
{
    /**
     * Resolve an IP address to its MAC address.
     * Returns normalized MAC (AA:BB:CC:DD:EE:FF) or null if not found.
     */
    public function resolveIpToMac(string $ipAddress): ?string;
}
```

- [ ] **Step 4: Implement MacAddressResolver**

```php
// app/Services/MacAddressResolver.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;

class MacAddressResolver implements MacAddressResolverInterface
{
    public function __construct(
        protected DhcpInterface $dhcp,
        protected NetworkInventoryInterface $inventory,
    ) {}

    public function resolveIpToMac(string $ipAddress): ?string
    {
        // Try DHCP lease first
        $lease = $this->dhcp->getLease($ipAddress);
        if ($lease !== null && ! empty($lease['mac'])) {
            return $this->normalizeMac($lease['mac']);
        }

        // Fall back to ARP table
        $arpEntry = $this->inventory->getArpTable()->firstWhere('ip', $ipAddress);
        if ($arpEntry !== null && ! empty($arpEntry['mac'])) {
            return $this->normalizeMac($arpEntry['mac']);
        }

        return null;
    }

    private function normalizeMac(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        return implode(':', str_split($hex, 2));
    }
}
```

- [ ] **Step 5: Add binding in AppServiceProvider**

In `AppServiceProvider::register()`, add:

```php
$this->app->bind(MacAddressResolverInterface::class, MacAddressResolver::class);
```

Add the import:
```php
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\MacAddressResolver;
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MacAddressResolverTest`
Expected: PASS

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Services/Interfaces/MacAddressResolverInterface.php app/Services/MacAddressResolver.php tests/Unit/Services/MacAddressResolverTest.php app/Providers/AppServiceProvider.php
git commit -m "feat: add MacAddressResolver service for IP-to-MAC resolution"
```

---

### Task 5: Wire MAC Resolution into Allow Flow

**Files:**
- Modify: `app/Models/IpAddress.php` — update `allow()` to resolve and link MAC
- Create: `tests/Feature/MacTrackingIntegrationTest.php`

- [ ] **Step 1: Write failing integration tests**

```php
// tests/Feature/MacTrackingIntegrationTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MacTrackingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_allow_links_mac_when_resolved(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $firewall->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(FirewallBackendInterface::class, $firewall);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')
            ->with('10.0.0.10')
            ->once()
            ->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $user = User::factory()->create();
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $user->addIp('10.0.0.10');

        $ip->allow();

        $ip->refresh();
        $this->assertNotNull($ip->mac_address_id);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $ip->macAddress->mac_address);
        $this->assertTrue($ip->macAddress->allowed);
        $this->assertEquals('auth', $ip->macAddress->source);
        $this->assertTrue($ip->macAddress->user->is($user));
    }

    public function test_allow_continues_when_mac_not_resolved(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $firewall->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(FirewallBackendInterface::class, $firewall);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')
            ->with('10.0.0.10')
            ->once()
            ->andReturn(null);
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $ip->allow();

        $ip->refresh();
        $this->assertNull($ip->mac_address_id);
        $this->assertTrue((bool) $ip->allowed);
    }

    public function test_allow_reuses_existing_mac_address_record(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $firewall->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(FirewallBackendInterface::class, $firewall);

        $existingMac = MacAddress::factory()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'source' => 'admin',
            'allowed' => false,
        ]);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')
            ->with('10.0.0.10')
            ->once()
            ->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
        $ip->allow();

        $ip->refresh();
        $existingMac->refresh();

        $this->assertEquals($existingMac->id, $ip->mac_address_id);
        $this->assertTrue($existingMac->allowed);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=MacTrackingIntegrationTest`
Expected: FAIL — IpAddress::allow() doesn't do MAC resolution yet

- [ ] **Step 3: Update IpAddress::allow() to resolve and link MAC**

In `app/Models/IpAddress.php`, update the `allow()` method. After the firewall `updateIp()` call and before saving, add MAC resolution:

```php
public function allow(bool $queue = false): void
{
    if ($queue) {
        IpAddressAction::dispatch($this, 'allow');

        return;
    }

    $description = $this->comment;
    /** @var UserIpAddress|null $userIp */
    $userIp = $this->users()->first();
    if ($userIp && $userIp->user) {
        $description = $userIp->user->nickname;
    }

    $opnsense = app(FirewallBackendInterface::class);
    $opnsense->updateIp($this->address, (string) $description);

    // Resolve and link MAC address
    $this->resolveAndLinkMac($userIp?->user);

    $this->allowed = true;
    $ttl = config('aperture.session.ttl');
    if ($ttl) {
        $this->expires_at = now()->addMinutes((int) $ttl);
    }

    $this->save();
}

protected function resolveAndLinkMac(?User $user): void
{
    try {
        $resolver = app(MacAddressResolverInterface::class);
        $macString = $resolver->resolveIpToMac($this->address);

        if ($macString === null) {
            return;
        }

        $mac = MacAddress::firstOrCreate(
            ['mac_address' => $macString],
            [
                'source' => 'auth',
                'allowed' => true,
                'allowed_at' => now(),
            ],
        );

        // Update the MAC record
        if (! $mac->allowed) {
            $mac->allowed = true;
            $mac->allowed_at = now();
        }
        if ($user !== null && $mac->user_id === null) {
            $mac->user_id = $user->id;
        }
        $mac->save();

        $this->mac_address_id = $mac->id;
    } catch (\Throwable $e) {
        // MAC resolution failure should not prevent allowing the IP
        \Illuminate\Support\Facades\Log::warning('MAC resolution failed', [
            'ip' => $this->address,
            'error' => $e->getMessage(),
        ]);
    }
}
```

Add imports at the top of `IpAddress.php`:
```php
use App\Services\Interfaces\MacAddressResolverInterface;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=MacTrackingIntegrationTest`
Expected: PASS

- [ ] **Step 5: Run existing IP tests to ensure no regressions**

Run: `php artisan test --compact --filter=IpAddress`
Expected: PASS — existing tests mock the firewall, MAC resolver is optional (caught by try/catch)

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Models/IpAddress.php tests/Feature/MacTrackingIntegrationTest.php
git commit -m "feat: resolve and link MAC address when allowing an IP"
```

---

### Task 6: ScanNetworkDevices Background Job

**Files:**
- Create: `app/Jobs/ScanNetworkDevices.php`
- Create: `tests/Feature/Jobs/ScanNetworkDevicesTest.php`
- Modify: `app/Console/Kernel.php`

- [ ] **Step 1: Write failing tests for ScanNetworkDevices**

```php
// tests/Feature/Jobs/ScanNetworkDevicesTest.php
<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ScanNetworkDevices;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScanNetworkDevicesTest extends TestCase
{
    use RefreshDatabase;

    private function mockFirewall(): void
    {
        $firewall = Mockery::mock(FirewallBackendInterface::class);
        $firewall->shouldReceive('updateIp')->andReturnSelf();
        $this->app->instance(FirewallBackendInterface::class, $firewall);
    }

    public function test_resolves_mac_for_unlinked_ips(): void
    {
        $this->mockFirewall();

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')
            ->with('10.0.0.10')
            ->once()
            ->andReturn('AA:BB:CC:DD:EE:FF');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        $ip = IpAddress::factory()->create([
            'address' => '10.0.0.10',
            'mac_address_id' => null,
        ]);

        config(['aperture.auto_allow.enabled' => true]);

        (new ScanNetworkDevices)->handle();

        $ip->refresh();
        $this->assertNotNull($ip->mac_address_id);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $ip->macAddress->mac_address);
    }

    public function test_auto_allows_new_ip_for_known_allowed_mac(): void
    {
        $this->mockFirewall();

        $mac = MacAddress::factory()->allowed()->create([
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldReceive('resolveIpToMac')->andReturn(null);
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        $dhcp = Mockery::mock(DhcpInterface::class);
        $dhcp->shouldReceive('getLeases')->andReturn(collect([
            ['ip' => '10.0.0.50', 'mac' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'new-device', 'expires' => '2026-04-13'],
        ]));
        $this->app->instance(DhcpInterface::class, $dhcp);

        $inventory = Mockery::mock(NetworkInventoryInterface::class);
        $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
        $this->app->instance(NetworkInventoryInterface::class, $inventory);

        config(['aperture.auto_allow.enabled' => true]);

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::whereAddress('10.0.0.50')->first();
        $this->assertNotNull($ip);
        $this->assertTrue((bool) $ip->allowed);
        $this->assertEquals($mac->id, $ip->mac_address_id);
    }

    public function test_does_nothing_when_disabled(): void
    {
        $resolver = Mockery::mock(MacAddressResolverInterface::class);
        $resolver->shouldNotReceive('resolveIpToMac');
        $this->app->instance(MacAddressResolverInterface::class, $resolver);

        config(['aperture.auto_allow.enabled' => false]);

        (new ScanNetworkDevices)->handle();

        // No assertions needed — the mock verifies no calls were made
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ScanNetworkDevicesTest`
Expected: FAIL — job doesn't exist

- [ ] **Step 3: Implement ScanNetworkDevices job**

```php
// app/Jobs/ScanNetworkDevices.php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\Interfaces\MacAddressResolverInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanNetworkDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        if (! config('aperture.auto_allow.enabled')) {
            return;
        }

        $this->resolveUnlinkedIps();
        $this->autoAllowKnownMacs();
    }

    private function resolveUnlinkedIps(): void
    {
        $resolver = app(MacAddressResolverInterface::class);

        $unlinkedIps = IpAddress::whereNull('mac_address_id')->get();

        foreach ($unlinkedIps as $ip) {
            try {
                $macString = $resolver->resolveIpToMac($ip->address);
                if ($macString === null) {
                    continue;
                }

                $mac = MacAddress::firstOrCreate(
                    ['mac_address' => $macString],
                    ['source' => 'auth', 'allowed' => false],
                );

                $ip->mac_address_id = $mac->id;
                $ip->save();
            } catch (Throwable $e) {
                Log::warning('Failed to resolve MAC for IP', [
                    'ip' => $ip->address,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function autoAllowKnownMacs(): void
    {
        $dhcp = app(DhcpInterface::class);
        $inventory = app(NetworkInventoryInterface::class);

        $allowedMacs = MacAddress::whereAllowed(true)
            ->pluck('mac_address', 'id')
            ->mapWithKeys(fn (string $mac, int $id) => [strtoupper(preg_replace('/[^0-9A-F]/', '', $mac)) => $id]);

        // Check DHCP leases
        $leases = $dhcp->getLeases();
        foreach ($leases as $lease) {
            $this->processNetworkEntry($lease['ip'], $lease['mac'], $allowedMacs);
        }

        // Check ARP table
        $arpEntries = $inventory->getArpTable();
        foreach ($arpEntries as $entry) {
            $this->processNetworkEntry($entry['ip'], $entry['mac'], $allowedMacs);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $allowedMacs
     */
    private function processNetworkEntry(string $ip, string $rawMac, $allowedMacs): void
    {
        $normalizedHex = strtoupper(preg_replace('/[^0-9A-F]/', '', strtoupper($rawMac)));
        $normalizedMac = implode(':', str_split($normalizedHex, 2));

        // Check Xbox OUI prefixes
        $ouiPrefixes = config('aperture.auto_allow.oui_prefixes', []);
        $prefix = substr($normalizedMac, 0, 8); // AA:BB:CC
        $isXbox = in_array($prefix, $ouiPrefixes, true);

        if ($isXbox) {
            $mac = MacAddress::firstOrCreate(
                ['mac_address' => $normalizedMac],
                [
                    'source' => 'xbox',
                    'allowed' => true,
                    'allowed_at' => now(),
                    'description' => 'Xbox Console',
                ],
            );

            if (! $mac->allowed) {
                $mac->allowed = true;
                $mac->allowed_at = now();
                $mac->save();
            }
        }

        // Check if MAC is in allowed list
        if (! isset($allowedMacs[$normalizedHex]) && ! $isXbox) {
            return;
        }

        $macId = $isXbox
            ? MacAddress::where('mac_address', $normalizedMac)->value('id')
            : $allowedMacs[$normalizedHex];

        $ipRecord = IpAddress::whereAddress($ip)->first();

        if ($ipRecord === null) {
            $ipRecord = new IpAddress;
            $ipRecord->address = $ip;
            $ipRecord->mac_address_id = $macId;
            $ipRecord->save();
        } elseif ($ipRecord->mac_address_id === null) {
            $ipRecord->mac_address_id = $macId;
            $ipRecord->save();
        }

        if (! $ipRecord->allowed) {
            try {
                $ipRecord->allow();
            } catch (Throwable $e) {
                Log::warning('Failed to auto-allow IP for known MAC', [
                    'ip' => $ip,
                    'mac' => $normalizedMac,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Schedule the job in Console\Kernel**

In `app/Console/Kernel.php`, in the `schedule()` method, add:

```php
$schedule->job(new \App\Jobs\ScanNetworkDevices)->everyFiveMinutes();
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ScanNetworkDevicesTest`
Expected: PASS

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --compact`
Expected: ALL pass

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/ScanNetworkDevices.php tests/Feature/Jobs/ScanNetworkDevicesTest.php app/Console/Kernel.php
git commit -m "feat: add ScanNetworkDevices background job for MAC tracking and auto-allow"
```

---

### Task 7: Xbox OUI Detection Tests

**Files:**
- Modify: `tests/Feature/Jobs/ScanNetworkDevicesTest.php` — add Xbox-specific tests

This task adds dedicated tests for Xbox OUI detection within the background scan. The implementation is already in `ScanNetworkDevices` from Task 6.

- [ ] **Step 1: Add Xbox detection tests**

Add to `tests/Feature/Jobs/ScanNetworkDevicesTest.php`:

```php
public function test_detects_xbox_by_oui_prefix_and_auto_allows(): void
{
    $this->mockFirewall();

    $resolver = Mockery::mock(MacAddressResolverInterface::class);
    $resolver->shouldReceive('resolveIpToMac')->andReturn(null);
    $this->app->instance(MacAddressResolverInterface::class, $resolver);

    $dhcp = Mockery::mock(DhcpInterface::class);
    $dhcp->shouldReceive('getLeases')->andReturn(collect([
        // MAC starts with Xbox OUI prefix 98:5F:D3
        ['ip' => '10.0.0.100', 'mac' => '98:5F:D3:AA:BB:CC', 'hostname' => 'XboxOne', 'expires' => '2026-04-13'],
    ]));
    $this->app->instance(DhcpInterface::class, $dhcp);

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    config([
        'aperture.auto_allow.enabled' => true,
        'aperture.auto_allow.oui_prefixes' => ['98:5F:D3'],
    ]);

    (new ScanNetworkDevices)->handle();

    // MacAddress record should be created with source=xbox
    $mac = MacAddress::where('mac_address', '98:5F:D3:AA:BB:CC')->first();
    $this->assertNotNull($mac);
    $this->assertEquals('xbox', $mac->source);
    $this->assertTrue($mac->allowed);
    $this->assertNull($mac->user_id);

    // IP should be created and allowed
    $ip = IpAddress::whereAddress('10.0.0.100')->first();
    $this->assertNotNull($ip);
    $this->assertTrue((bool) $ip->allowed);
    $this->assertEquals($mac->id, $ip->mac_address_id);
}

public function test_does_not_detect_xbox_for_non_matching_oui(): void
{
    $this->mockFirewall();

    $resolver = Mockery::mock(MacAddressResolverInterface::class);
    $resolver->shouldReceive('resolveIpToMac')->andReturn(null);
    $this->app->instance(MacAddressResolverInterface::class, $resolver);

    $dhcp = Mockery::mock(DhcpInterface::class);
    $dhcp->shouldReceive('getLeases')->andReturn(collect([
        ['ip' => '10.0.0.100', 'mac' => 'AA:BB:CC:DD:EE:FF', 'hostname' => 'desktop-1', 'expires' => '2026-04-13'],
    ]));
    $this->app->instance(DhcpInterface::class, $dhcp);

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    config([
        'aperture.auto_allow.enabled' => true,
        'aperture.auto_allow.oui_prefixes' => ['98:5F:D3'],
    ]);

    (new ScanNetworkDevices)->handle();

    // No MacAddress record should be created for this non-Xbox MAC
    $this->assertEquals(0, MacAddress::count());

    // IP should not be auto-created or allowed
    $this->assertNull(IpAddress::whereAddress('10.0.0.100')->first());
}

public function test_xbox_detection_uses_config_prefix_list(): void
{
    $this->mockFirewall();

    $resolver = Mockery::mock(MacAddressResolverInterface::class);
    $resolver->shouldReceive('resolveIpToMac')->andReturn(null);
    $this->app->instance(MacAddressResolverInterface::class, $resolver);

    $dhcp = Mockery::mock(DhcpInterface::class);
    $dhcp->shouldReceive('getLeases')->andReturn(collect([
        ['ip' => '10.0.0.101', 'mac' => 'FF:EE:DD:11:22:33', 'hostname' => 'custom-device', 'expires' => '2026-04-13'],
    ]));
    $this->app->instance(DhcpInterface::class, $dhcp);

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    // Custom prefix in config
    config([
        'aperture.auto_allow.enabled' => true,
        'aperture.auto_allow.oui_prefixes' => ['FF:EE:DD'],
    ]);

    (new ScanNetworkDevices)->handle();

    $mac = MacAddress::where('mac_address', 'FF:EE:DD:11:22:33')->first();
    $this->assertNotNull($mac);
    $this->assertEquals('xbox', $mac->source);
    $this->assertTrue($mac->allowed);
}
```

- [ ] **Step 2: Run Xbox tests**

Run: `php artisan test --compact --filter="test_detects_xbox|test_does_not_detect_xbox|test_xbox_detection"`
Expected: PASS

- [ ] **Step 3: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Jobs/ScanNetworkDevicesTest.php
git commit -m "test: add Xbox OUI detection tests for ScanNetworkDevices job"
```

---

### Task 8: IPv6 Auto-detection

**Files:**
- Modify: Portal Vue component — add IPv6 detection JS
- Create: `tests/js/ipv6-detection.spec.js`
- Modify: `app/Services/Interfaces/NetworkInventoryInterface.php` — add `getIpv6Neighbors()`
- Modify: `app/Services/LibreNmsService.php` — implement `getIpv6Neighbors()`
- Modify: `app/Services/MacAddressResolver.php` — optionally check IPv6 neighbors
- Modify: `tests/Unit/Services/LibreNmsServiceTest.php` — test IPv6 neighbors

- [ ] **Step 1: Add getIpv6Neighbors() to NetworkInventoryInterface**

In `app/Services/Interfaces/NetworkInventoryInterface.php`, add:

```php
/**
 * @return Collection<int, array{ip: string, mac: string}>
 */
public function getIpv6Neighbors(): Collection;
```

- [ ] **Step 2: Implement in LibreNmsService**

In `app/Services/LibreNmsService.php`, add:

```php
/**
 * @return Collection<int, array{ip: string, mac: string}>
 */
public function getIpv6Neighbors(): Collection
{
    $response = $this->client->get('/api/v0/resources/ip/arp');
    /** @var array<string, mixed> $data */
    $data = json_decode((string) $response->getBody(), true);

    /** @var array<int, array{ip: string, mac: string}> $mapped */
    $mapped = array_filter(
        array_map(fn (array $entry): array => [
            'ip' => (string) ($entry['ipv4_address'] ?? $entry['ipv6_address'] ?? ''),
            'mac' => (string) ($entry['mac_address'] ?? ''),
        ], $data['arp'] ?? []),
        fn (array $entry): bool => str_contains($entry['ip'], ':'), // IPv6 addresses contain colons
    );

    return collect(array_values($mapped));
}
```

- [ ] **Step 3: Write test for getIpv6Neighbors**

Add to the existing LibreNmsService test file:

```php
public function test_get_ipv6_neighbors_returns_collection(): void
{
    $service = $this->createServiceWithMock([
        new Response(200, [], (string) json_encode([
            'arp' => [
                ['ipv4_address' => '10.0.0.10', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
                ['ipv6_address' => '2001:db8::1', 'mac_address' => 'aa:bb:cc:dd:ee:ff'],
                ['ipv4_address' => '10.0.0.11', 'mac_address' => '11:22:33:44:55:66'],
            ],
        ])),
    ]);

    $result = $service->getIpv6Neighbors();

    $this->assertCount(1, $result);
    $this->assertEquals('2001:db8::1', $result->first()['ip']);
}
```

- [ ] **Step 4: Update CachedNetworkInventoryService to include ipv6 (done in Task 9)**

This will be addressed when we create the cache decorator.

- [ ] **Step 5: Write Vitest for IPv6 detection JS utility**

```js
// tests/js/ipv6-detection.spec.js
import { describe, it, expect, vi, beforeEach } from 'vitest';

// The utility function we'll create
// import { detectIpv6 } from '@/utils/ipv6-detection';

describe('IPv6 Detection', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('fetches IPv6 address from the configured endpoint', async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            text: () => Promise.resolve('2001:db8::1'),
        });
        global.fetch = mockFetch;

        // Import dynamically after mocking
        const { detectIpv6 } = await import('@/utils/ipv6-detection');

        const result = await detectIpv6('https://ipv6.test.example.com');
        expect(result).toBe('2001:db8::1');
        expect(mockFetch).toHaveBeenCalledWith(
            'https://ipv6.test.example.com',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
    });

    it('returns null when fetch fails', async () => {
        const mockFetch = vi.fn().mockRejectedValue(new Error('Network error'));
        global.fetch = mockFetch;

        const { detectIpv6 } = await import('@/utils/ipv6-detection');

        const result = await detectIpv6('https://ipv6.test.example.com');
        expect(result).toBeNull();
    });

    it('returns null when response is not ok', async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: false,
            text: () => Promise.resolve(''),
        });
        global.fetch = mockFetch;

        const { detectIpv6 } = await import('@/utils/ipv6-detection');

        const result = await detectIpv6('https://ipv6.test.example.com');
        expect(result).toBeNull();
    });

    it('trims whitespace from response', async () => {
        const mockFetch = vi.fn().mockResolvedValue({
            ok: true,
            text: () => Promise.resolve('  2001:db8::1  \n'),
        });
        global.fetch = mockFetch;

        const { detectIpv6 } = await import('@/utils/ipv6-detection');

        const result = await detectIpv6('https://ipv6.test.example.com');
        expect(result).toBe('2001:db8::1');
    });
});
```

- [ ] **Step 6: Create the IPv6 detection utility**

```js
// resources/js/utils/ipv6-detection.js
/**
 * Detect the user's IPv6 address by fetching an external endpoint.
 * Returns the IPv6 address string or null if detection fails.
 *
 * @param {string} endpoint - The URL to fetch (returns the client's IP as plain text)
 * @param {number} [timeout=5000] - Request timeout in milliseconds
 * @returns {Promise<string|null>}
 */
export async function detectIpv6(endpoint, timeout = 5000) {
    try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        const response = await fetch(endpoint, {
            signal: controller.signal,
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
            return null;
        }

        const text = (await response.text()).trim();
        // Basic IPv6 validation — must contain a colon
        return text.includes(':') ? text : null;
    } catch {
        return null;
    }
}
```

- [ ] **Step 7: Integrate IPv6 detection into portal page**

In the appropriate portal Vue component (e.g. `resources/js/Pages/Portal/Dashboard.vue`), add on mount:

```js
import { detectIpv6 } from '@/utils/ipv6-detection';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();

onMounted(async () => {
    const ipv6Endpoint = page.props.ipv6DetectionEndpoint;
    if (!ipv6Endpoint) return;

    const ipv6 = await detectIpv6(ipv6Endpoint);
    if (ipv6) {
        // POST to existing /ipv6 endpoint
        router.post('/ipv6', { ipv6 }, { preserveState: true, preserveScroll: true });
    }
});
```

The controller needs to pass `ipv6DetectionEndpoint` as a prop:

```php
// In the portal dashboard controller, add to Inertia props:
'ipv6DetectionEndpoint' => config('aperture.ipv6.detection_enabled')
    ? config('aperture.ipv6.detection_endpoint')
    : null,
```

- [ ] **Step 8: Run all tests**

Run: `npx vitest run tests/js/ipv6-detection.spec.js`
Run: `php artisan test --compact --filter=LibreNmsService`
Expected: PASS

- [ ] **Step 9: Run ESLint and Prettier**

Run: `npm run lint:fix && npm run format`

- [ ] **Step 10: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 11: Commit**

```bash
git add resources/js/utils/ipv6-detection.js tests/js/ipv6-detection.spec.js app/Services/Interfaces/NetworkInventoryInterface.php app/Services/LibreNmsService.php resources/js/Pages/Portal/Dashboard.vue
git commit -m "feat: add IPv6 auto-detection via external API and LibreNMS IPv6 neighbors"
```

---

### Task 9: CachedNetworkInventoryService Decorator

**Files:**
- Create: `app/Services/CachedNetworkInventoryService.php`
- Create: `tests/Unit/Services/CachedNetworkInventoryServiceTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Write failing tests for cache decorator**

```php
// tests/Unit/Services/CachedNetworkInventoryServiceTest.php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CachedNetworkInventoryService;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class CachedNetworkInventoryServiceTest extends TestCase
{
    public function test_caches_arp_table(): void
    {
        $inner = Mockery::mock(NetworkInventoryInterface::class);
        $inner->shouldReceive('getArpTable')
            ->once() // Should only be called once due to caching
            ->andReturn(collect([['ip' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff']]));

        $cache = app('cache.store');
        $cache->flush();

        $service = new CachedNetworkInventoryService($inner, $cache, 5);

        $result1 = $service->getArpTable();
        $result2 = $service->getArpTable();

        $this->assertCount(1, $result1);
        $this->assertCount(1, $result2);
        $this->assertEquals('10.0.0.1', $result1->first()['ip']);
    }

    public function test_caches_forwarding_database(): void
    {
        $inner = Mockery::mock(NetworkInventoryInterface::class);
        $inner->shouldReceive('getForwardingDatabase')
            ->once()
            ->andReturn(collect([['mac' => 'aa:bb:cc:dd:ee:ff', 'port' => '1', 'vlan' => 100]]));

        $cache = app('cache.store');
        $cache->flush();

        $service = new CachedNetworkInventoryService($inner, $cache, 5);

        $result1 = $service->getForwardingDatabase();
        $result2 = $service->getForwardingDatabase();

        $this->assertCount(1, $result1);
        $this->assertCount(1, $result2);
    }

    public function test_caches_ipv6_neighbors(): void
    {
        $inner = Mockery::mock(NetworkInventoryInterface::class);
        $inner->shouldReceive('getIpv6Neighbors')
            ->once()
            ->andReturn(collect([['ip' => '2001:db8::1', 'mac' => 'aa:bb:cc:dd:ee:ff']]));

        $cache = app('cache.store');
        $cache->flush();

        $service = new CachedNetworkInventoryService($inner, $cache, 5);

        $result1 = $service->getIpv6Neighbors();
        $result2 = $service->getIpv6Neighbors();

        $this->assertCount(1, $result1);
        $this->assertCount(1, $result2);
    }

    public function test_resolve_ip_to_port_uses_cached_data(): void
    {
        $inner = Mockery::mock(NetworkInventoryInterface::class);
        $inner->shouldReceive('resolveIpToPort')
            ->once()
            ->andReturn(['ip' => '10.0.0.1', 'mac' => 'aa:bb:cc:dd:ee:ff', 'port' => '1', 'switch' => 'sw1']);

        $cache = app('cache.store');
        $cache->flush();

        $service = new CachedNetworkInventoryService($inner, $cache, 5);

        $result1 = $service->resolveIpToPort('10.0.0.1');
        $result2 = $service->resolveIpToPort('10.0.0.1');

        $this->assertNotNull($result1);
        $this->assertEquals('10.0.0.1', $result1['ip']);
    }

    public function test_get_device_list_is_cached(): void
    {
        $inner = Mockery::mock(NetworkInventoryInterface::class);
        $inner->shouldReceive('getDeviceList')
            ->once()
            ->andReturn(collect([['hostname' => 'sw1', 'ip' => '10.0.0.1', 'type' => 'switch']]));

        $cache = app('cache.store');
        $cache->flush();

        $service = new CachedNetworkInventoryService($inner, $cache, 5);

        $result1 = $service->getDeviceList();
        $result2 = $service->getDeviceList();

        $this->assertCount(1, $result1);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=CachedNetworkInventoryServiceTest`
Expected: FAIL — class doesn't exist

- [ ] **Step 3: Implement CachedNetworkInventoryService**

```php
// app/Services/CachedNetworkInventoryService.php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Cache\Repository;
use Illuminate\Support\Collection;

class CachedNetworkInventoryService implements NetworkInventoryInterface
{
    public function __construct(
        protected NetworkInventoryInterface $inner,
        protected Repository $cache,
        protected int $ttlMinutes = 5,
    ) {}

    /**
     * @return Collection<int, array{mac: string, port: string, vlan: int}>
     */
    public function getForwardingDatabase(): Collection
    {
        return $this->cache->remember(
            'network_inventory.fdb',
            $this->ttlMinutes * 60,
            fn () => $this->inner->getForwardingDatabase(),
        );
    }

    /**
     * @return Collection<int, array{ip: string, mac: string}>
     */
    public function getArpTable(): Collection
    {
        return $this->cache->remember(
            'network_inventory.arp',
            $this->ttlMinutes * 60,
            fn () => $this->inner->getArpTable(),
        );
    }

    /**
     * @return array{ip: string, mac: string, port: string, switch: string}|null
     */
    public function resolveIpToPort(string $ipAddress): ?array
    {
        return $this->cache->remember(
            'network_inventory.resolve.' . $ipAddress,
            $this->ttlMinutes * 60,
            fn () => $this->inner->resolveIpToPort($ipAddress),
        );
    }

    /**
     * @return Collection<int, array{hostname: string, ip: string, type: string}>
     */
    public function getDeviceList(): Collection
    {
        return $this->cache->remember(
            'network_inventory.devices',
            $this->ttlMinutes * 60,
            fn () => $this->inner->getDeviceList(),
        );
    }

    /**
     * @return Collection<int, array{ip: string, mac: string}>
     */
    public function getIpv6Neighbors(): Collection
    {
        return $this->cache->remember(
            'network_inventory.ipv6_neighbors',
            $this->ttlMinutes * 60,
            fn () => $this->inner->getIpv6Neighbors(),
        );
    }
}
```

- [ ] **Step 4: Update AppServiceProvider to use cached decorator**

Replace the existing `LibreNmsService` singleton with:

```php
$this->app->singleton(NetworkInventoryInterface::class, function (Application $application): CachedNetworkInventoryService {
    $inner = new LibreNmsService(
        endpoint: config('aperture.librenms.endpoint', ''),
        apiToken: config('aperture.librenms.api_token', ''),
    );

    return new CachedNetworkInventoryService(
        $inner,
        app('cache.store'),
        (int) config('aperture.auto_allow.scan_interval', 5),
    );
});
```

Add import:
```php
use App\Services\CachedNetworkInventoryService;
use App\Services\Interfaces\NetworkInventoryInterface;
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=CachedNetworkInventoryServiceTest`
Expected: PASS

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Services/CachedNetworkInventoryService.php tests/Unit/Services/CachedNetworkInventoryServiceTest.php app/Providers/AppServiceProvider.php
git commit -m "feat: add CachedNetworkInventoryService decorator for LibreNMS API caching"
```

---

### Task 10: Replace IpAddress::getLNMSData() with Service Calls

**Files:**
- Modify: `app/Models/IpAddress.php`
- Modify: existing tests that depend on `getLNMSData()`

- [ ] **Step 1: Analyze current getLNMSData() consumers**

The `__get` magic method exposes `mac`, `port`, and `portUpdatedAt` via `getLNMSData()`. Find all places these are used:

```bash
grep -rn '\->mac\b\|\->port\b\|\->portUpdatedAt\b\|getLNMSData' app/ tests/ resources/ --include="*.php" --include="*.vue"
```

- [ ] **Step 2: Write tests for the new service-based approach**

Add to `tests/Feature/MacTrackingIntegrationTest.php` (or create new test file):

```php
public function test_ip_mac_accessor_returns_linked_mac(): void
{
    $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    $ip = IpAddress::factory()->create(['mac_address_id' => $mac->id]);

    $this->assertEquals('AA:BB:CC:DD:EE:FF', $ip->macAddress->mac_address);
}

public function test_ip_port_info_via_network_inventory(): void
{
    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('resolveIpToPort')
        ->with('10.0.0.10')
        ->andReturn([
            'ip' => '10.0.0.10',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'port' => 'Gi1/0/1',
            'switch' => 'switch1.local',
        ]);
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
    $portInfo = $ip->getPortInfo();

    $this->assertNotNull($portInfo);
    $this->assertEquals('Gi1/0/1', $portInfo['port']);
    $this->assertEquals('switch1.local', $portInfo['switch']);
}
```

- [ ] **Step 3: Replace getLNMSData() in IpAddress**

Replace the `getLNMSData()` method and the `__get` magic method with service-based resolution:

```php
/**
 * @return array{ip: string, mac: string, port: string, switch: string}|null
 */
public function getPortInfo(): ?array
{
    try {
        $inventory = app(NetworkInventoryInterface::class);

        return $inventory->resolveIpToPort($this->address);
    } catch (\Throwable $e) {
        return null;
    }
}
```

Update the `__get` method to use `macAddress` relationship for `mac` and `getPortInfo()` for `port`:

```php
public function __get($name)
{
    switch ($name) {
        case 'mac':
            return $this->macAddress?->mac_address;
        case 'port':
            $info = $this->getPortInfo();
            if ($info === null) {
                return null;
            }
            return (object) [
                'switch' => $info['switch'],
                'interface' => $info['port'],
                'status' => '', // Port details from switch if needed
                'adminStatus' => '',
                'speed' => 0,
            ];
        case 'portUpdatedAt':
            return null; // No longer available from direct query
        default:
            return parent::__get($name);
    }
}
```

Remove the `$lnms` property and the old `getLNMSData()` method. Remove the `DB` import and `lnms` connection usage.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=IpAddress`
Expected: PASS (update any failing tests to mock the new service)

- [ ] **Step 5: Run full test suite**

Run: `php artisan test --compact`
Expected: ALL pass — fix any tests that relied on `getLNMSData()` or the `lnms` DB connection

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Models/IpAddress.php tests/
git commit -m "feat: replace IpAddress::getLNMSData() with service-based resolution"
```

---

### Task 11: Fix shutPort/unshutPort DI

**Files:**
- Modify: `app/Models/IpAddress.php`
- Modify: existing tests

- [ ] **Step 1: Write/update tests for DI-based shut/unshut**

```php
public function test_shut_port_uses_network_switch_interface(): void
{
    $switch = Mockery::mock(NetworkSwitchInterface::class);
    $switch->shouldReceive('shutdownPort')
        ->with('Gi1/0/1')
        ->once()
        ->andReturn(true);
    $this->app->instance(NetworkSwitchInterface::class, $switch);

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('resolveIpToPort')
        ->with('10.0.0.10')
        ->andReturn([
            'ip' => '10.0.0.10',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'port' => 'Gi1/0/1',
            'switch' => 'switch1.local',
        ]);
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
    $ip->shutPort();

    // Mockery assertion handles verification
    $this->assertTrue(true);
}

public function test_unshut_port_uses_network_switch_interface(): void
{
    $switch = Mockery::mock(NetworkSwitchInterface::class);
    $switch->shouldReceive('enablePort')
        ->with('Gi1/0/1')
        ->once()
        ->andReturn(true);
    $this->app->instance(NetworkSwitchInterface::class, $switch);

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('resolveIpToPort')
        ->with('10.0.0.10')
        ->andReturn([
            'ip' => '10.0.0.10',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'port' => 'Gi1/0/1',
            'switch' => 'switch1.local',
        ]);
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);
    $ip->unshutPort();

    $this->assertTrue(true);
}
```

- [ ] **Step 2: Update shutPort/unshutPort to use DI**

```php
public function shutPort(bool $queue = false): void
{
    if ($queue) {
        IpAddressAction::dispatch($this, 'shutPort');

        return;
    }

    $portInfo = $this->getPortInfo();
    if ($portInfo === null) {
        return;
    }

    $switch = app(NetworkSwitchInterface::class);
    $switch->shutdownPort($portInfo['port']);
}

public function unshutPort(bool $queue = false): void
{
    if ($queue) {
        IpAddressAction::dispatch($this, 'unshutPort');

        return;
    }

    $portInfo = $this->getPortInfo();
    if ($portInfo === null) {
        return;
    }

    $switch = app(NetworkSwitchInterface::class);
    $switch->enablePort($portInfo['port']);
}
```

Remove the `use App\Services\CiscoService;` import if no longer needed.

- [ ] **Step 3: Run tests**

Run: `php artisan test --compact --filter=IpAddress`
Expected: PASS

- [ ] **Step 4: Run full test suite**

Run: `php artisan test --compact`
Expected: ALL pass

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 6: Commit**

```bash
git add app/Models/IpAddress.php tests/
git commit -m "feat: fix shutPort/unshutPort to use NetworkSwitchInterface via DI"
```

---

### Task 12: Quality Gate Verification

- [ ] **Step 1: Run full quality script**

Run: `bash bin/quality.sh`
Expected: ALL gates pass

- [ ] **Step 2: Fix any PHPStan issues**

Run: `vendor/bin/phpstan analyse`
Expected: 0 errors

If issues arise:
- New `MacAddress` model: ensure all types are documented
- `CachedNetworkInventoryService`: ensure generic types match interface
- `IpAddress` changes: ensure `__get` return types documented in PHPDoc

- [ ] **Step 3: Fix any Rector suggestions**

Run: `vendor/bin/rector process --dry-run`
Apply any suggestions, then: `vendor/bin/rector process`

- [ ] **Step 4: Run ESLint and Prettier**

Run: `npm run lint:fix && npm run format`

- [ ] **Step 5: Run full PHP test suite with coverage**

Run: `XDEBUG_MODE=coverage php artisan test --compact --coverage`
Expected: 100% coverage on new code

- [ ] **Step 6: Run full JS test suite**

Run: `npm run test`
Expected: ALL pass

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --format agent`

- [ ] **Step 8: Final commit**

```bash
git add -A
git commit -m "chore: Phase 3 quality gate fixes"
```

- [ ] **Step 9: Final summary commit**

```bash
git add -A
git commit -m "feat: Phase 3 — MAC tracking, Xbox auto-enable, IPv6 automation, cache layer

- Add MacAddress model with normalized MAC storage and OUI lookup
- Add MacAddressResolver service for IP-to-MAC resolution via DHCP/ARP
- Wire MAC resolution into IpAddress::allow() for auth-time linkage
- Add ScanNetworkDevices job: resolve unlinked IPs, auto-allow known MACs, Xbox OUI detection
- Add IPv6 auto-detection via external API endpoint
- Add CachedNetworkInventoryService decorator for LibreNMS API caching
- Replace IpAddress::getLNMSData() direct SQL with service-based resolution
- Fix shutPort/unshutPort to use NetworkSwitchInterface via DI
- Config: auto_allow (oui_prefixes, scan_interval), ipv6 (detection_enabled, detection_endpoint)
- All quality gates passing: PHPStan 8, Pint, Rector, ESLint, Prettier, 100% coverage"
```
