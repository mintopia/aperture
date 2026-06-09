# Cisco DHCP Integration + Async DHCP Polling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Cisco IOS DHCP server integration with async polling for all DHCP providers, DHCP snooping during switch polling, and remove legacy CiscoService.

**Architecture:** Follows existing bootstrapper/capability pattern. New `CiscoBootstrapper` binds `DhcpInterface` via `$app->extend()` when Cisco DHCP capability is active. A new `SyncDhcpData` job polls every minute, writing to DB tables (`dhcp_leases`, `dhcp_range_records`, `dhcp_pool_statuses`). Controller reads from DB instead of calling `DhcpInterface` live. DHCP snooping observations collected during `PortSyncService` runs via `SupportsDhcpSnooping` interface on `CiscoSwitchAdapter`.

**Tech Stack:** Laravel 11, PHP 8.3, PHPUnit, Inertia.js/Vue, BCMath for IPv6 arithmetic, phpseclib3 SSH via ssh-proxy transport.

**Reference:** Full design rationale in `PLAN.md` and `PLAN-REVIEW-LOG.md` (grill-with-docs-codex output).

---

## File Map

### New Files
| File | Responsibility |
|------|---------------|
| `database/migrations/2026_06_08_000001_create_dhcp_range_records_table.php` | Range records schema |
| `database/migrations/2026_06_08_000002_add_integration_to_dhcp_leases.php` | Add integration column to leases |
| `database/migrations/2026_06_08_000003_create_dhcp_pool_statuses_table.php` | Pool status schema |
| `database/migrations/2026_06_08_000004_create_dhcp_sync_states_table.php` | Sync state tracking schema |
| `database/migrations/2026_06_08_000005_create_dhcp_snooping_observations_table.php` | Snooping observations schema |
| `app/Models/DhcpRangeRecord.php` | Eloquent model for DHCP ranges |
| `app/Models/DhcpPoolStatusRecord.php` | Eloquent model for pool status |
| `app/Models/DhcpSyncState.php` | Eloquent model for sync state |
| `app/Models/DhcpSnoopingObservation.php` | Eloquent model for snooping |
| `database/factories/DhcpRangeRecordFactory.php` | Factory for range records |
| `database/factories/DhcpPoolStatusRecordFactory.php` | Factory for pool status |
| `database/factories/DhcpSyncStateFactory.php` | Factory for sync state |
| `database/factories/DhcpSnoopingObservationFactory.php` | Factory for snooping |
| `app/Integration/CiscoBootstrapper.php` | Cisco capability bindings |
| `app/Services/Cisco/CiscoDhcpService.php` | Cisco DHCP implementation |
| `app/Services/Interfaces/SupportsDhcpSnooping.php` | Optional adapter interface |
| `app/Services/NetworkScan/DhcpSnoopingResolver.php` | Resolver for IP/MAC from snooping |
| `app/Jobs/SyncDhcpData.php` | Async DHCP polling job |
| `app/Console/Commands/SyncDhcpOnce.php` | Artisan command for deploy-time sync |
| Tests (see each task) | |

### Modified Files
| File | Change |
|------|--------|
| `app/Enums/Integration.php` | Add `Cisco` case |
| `config/integrations.php` | Add cisco config definition |
| `app/Providers/IntegrationServiceProvider.php` | Register `CiscoBootstrapper` |
| `app/Services/NetworkSwitch/IosOutputParser.php` | Add DHCP parsing methods |
| `app/Services/NetworkSwitch/CiscoSwitchAdapter.php` | Implement `SupportsDhcpSnooping` |
| `app/Services/NetworkSwitch/PortSyncService.php` | Process snooping bindings |
| `app/Http/Controllers/Admin/DhcpController.php` | Read from DB instead of live fetch |
| `app/Events/DhcpPoolThresholdReached.php` | Add `addressFamily` to payload |
| `app/Console/Kernel.php` | Register `SyncDhcpData` job |
| `app/Services/ValueObjects/DhcpLease.php` | Make `mac` nullable for DHCPv6 |

### Deleted Files
| File | Reason |
|------|--------|
| `app/Services/CiscoService.php` | Legacy direct SSH — replaced by SwitchCommandTransportInterface |
| `tests/Unit/Services/CiscoServiceTest.php` | Tests for removed class |
| `tests/Unit/Services/CiscoServiceConstructorTest.php` | Tests for removed class |

---

## Task 1: Database Migrations & Models — Range Records

**Files:**
- Create: `database/migrations/2026_06_08_000001_create_dhcp_range_records_table.php`
- Create: `app/Models/DhcpRangeRecord.php`
- Create: `database/factories/DhcpRangeRecordFactory.php`
- Test: `tests/Unit/Models/DhcpRangeRecordTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_range_records', function (Blueprint $table): void {
            $table->id();
            $table->string('integration')->nullable()->index();
            $table->string('interface')->default('');
            $table->string('type');
            $table->string('subnet');
            $table->string('range_from');
            $table->string('range_to');
            $table->string('prefix')->nullable();
            $table->string('gateway')->nullable();
            $table->string('description')->nullable();
            $table->decimal('total_addresses', 39, 0)->nullable();
            $table->decimal('used_addresses', 39, 0)->nullable();
            $table->decimal('utilisation', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['integration', 'type', 'subnet', 'range_from', 'range_to'], 'dhcp_range_records_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_range_records');
    }
};
```

- [ ] **Step 2: Create the Eloquent model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpRangeRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $integration
 * @property string $interface
 * @property string $type
 * @property string $subnet
 * @property string $range_from
 * @property string $range_to
 * @property string|null $prefix
 * @property string|null $gateway
 * @property string|null $description
 * @property string|null $total_addresses
 * @property string|null $used_addresses
 * @property string|null $utilisation
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DhcpRangeRecord extends Model
{
    /** @use HasFactory<DhcpRangeRecordFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'integration',
        'interface',
        'type',
        'subnet',
        'range_from',
        'range_to',
        'prefix',
        'gateway',
        'description',
        'total_addresses',
        'used_addresses',
        'utilisation',
    ];
}
```

- [ ] **Step 3: Create the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpRangeRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpRangeRecord> */
class DhcpRangeRecordFactory extends Factory
{
    protected $model = DhcpRangeRecord::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'integration' => 'cisco',
            'interface' => 'Vlan100',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
            'prefix' => null,
            'gateway' => '10.0.0.1',
            'description' => 'Main LAN',
            'total_addresses' => '191',
            'used_addresses' => '50',
            'utilisation' => '0.2618',
        ];
    }

    public function ipv6(): static
    {
        return $this->state([
            'type' => 'ipv6',
            'subnet' => '2001:db8::/64',
            'range_from' => '2001:db8::10',
            'range_to' => '2001:db8::ff',
            'prefix' => '2001:db8::/64',
            'gateway' => null,
        ]);
    }
}
```

- [ ] **Step 4: Write model test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpRangeRecord;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpRangeRecordTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_can_create_range_record(): void
    {
        $record = DhcpRangeRecord::factory()->create();

        $this->assertDatabaseHas('dhcp_range_records', [
            'id' => $record->id,
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
        ]);
    }

    public function test_can_create_ipv6_range_record(): void
    {
        $record = DhcpRangeRecord::factory()->ipv6()->create();

        $this->assertDatabaseHas('dhcp_range_records', [
            'id' => $record->id,
            'type' => 'ipv6',
        ]);
    }

    public function test_unique_constraint_prevents_duplicates(): void
    {
        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DhcpRangeRecord::factory()->create([
            'integration' => 'cisco',
            'type' => 'ipv4',
            'subnet' => '10.0.0.0/24',
            'range_from' => '10.0.0.10',
            'range_to' => '10.0.0.200',
        ]);
    }

    public function test_different_integrations_can_have_same_range(): void
    {
        DhcpRangeRecord::factory()->create(['integration' => 'cisco']);
        $second = DhcpRangeRecord::factory()->create(['integration' => 'vyos']);

        $this->assertDatabaseCount('dhcp_range_records', 2);
    }
}
```

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Unit/Models/DhcpRangeRecordTest.php --parallel`
Expected: All 4 tests PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_08_000001_create_dhcp_range_records_table.php app/Models/DhcpRangeRecord.php database/factories/DhcpRangeRecordFactory.php tests/Unit/Models/DhcpRangeRecordTest.php
git commit -m "feat(dhcp): add dhcp_range_records table and model"
```

---

## Task 2: Database Migrations & Models — Integration Column on Leases

**Files:**
- Create: `database/migrations/2026_06_08_000002_add_integration_to_dhcp_leases.php`
- Modify: `app/Models/DhcpLease.php`
- Test: `tests/Unit/Models/DhcpLeaseIntegrationColumnTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use App\Models\CapabilityAssignment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->string('integration')->nullable()->after('id');
        });

        $activeProvider = null;
        try {
            $row = DB::table('capability_assignments')
                ->where('capability', 'dhcp')
                ->first();
            $activeProvider = $row?->integration;
        } catch (\Throwable) {
            // Table may not exist in fresh installs
        }

        if ($activeProvider !== null) {
            DB::table('dhcp_leases')
                ->whereNull('integration')
                ->update(['integration' => $activeProvider]);
        }

        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->dropUnique(['ip_address_id', 'mac_address_id']);
            $table->unique(['integration', 'ip_address_id'], 'dhcp_leases_integration_ip_unique');
        });
    }

    public function down(): void
    {
        Schema::table('dhcp_leases', function (Blueprint $table): void {
            $table->dropUnique('dhcp_leases_integration_ip_unique');
            $table->unique(['ip_address_id', 'mac_address_id']);
            $table->dropColumn('integration');
        });
    }
};
```

- [ ] **Step 2: Update DhcpLease model fillable**

In `app/Models/DhcpLease.php`, add `'integration'` to the `$fillable` array:

```php
protected $fillable = [
    'integration',
    'ip_address_id',
    'mac_address_id',
    'hostname',
    'expires_at',
];
```

- [ ] **Step 3: Write model test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DhcpLeaseIntegrationColumnTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dhcp_lease_can_have_integration(): void
    {
        $lease = DhcpLease::factory()->create(['integration' => 'cisco']);

        $this->assertSame('cisco', $lease->integration);
    }

    public function test_dhcp_lease_integration_is_nullable(): void
    {
        $lease = DhcpLease::factory()->create(['integration' => null]);

        $this->assertNull($lease->integration);
    }

    public function test_unique_constraint_on_integration_and_ip(): void
    {
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);
    }

    public function test_different_integrations_can_have_same_ip(): void
    {
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'integration' => 'cisco',
            'ip_address_id' => $ip->id,
        ]);

        $lease2 = DhcpLease::factory()->create([
            'integration' => 'vyos',
            'ip_address_id' => $ip->id,
        ]);

        $this->assertDatabaseCount('dhcp_leases', 2);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Models/DhcpLeaseIntegrationColumnTest.php --parallel`
Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_08_000002_add_integration_to_dhcp_leases.php app/Models/DhcpLease.php tests/Unit/Models/DhcpLeaseIntegrationColumnTest.php
git commit -m "feat(dhcp): add integration column to dhcp_leases"
```

---

## Task 3: Database Migrations & Models — Pool Status, Sync State, Snooping

**Files:**
- Create: `database/migrations/2026_06_08_000003_create_dhcp_pool_statuses_table.php`
- Create: `database/migrations/2026_06_08_000004_create_dhcp_sync_states_table.php`
- Create: `database/migrations/2026_06_08_000005_create_dhcp_snooping_observations_table.php`
- Create: `app/Models/DhcpPoolStatusRecord.php`
- Create: `app/Models/DhcpSyncState.php`
- Create: `app/Models/DhcpSnoopingObservation.php`
- Create: `database/factories/DhcpPoolStatusRecordFactory.php`
- Create: `database/factories/DhcpSyncStateFactory.php`
- Create: `database/factories/DhcpSnoopingObservationFactory.php`
- Test: `tests/Unit/Models/DhcpPoolStatusRecordTest.php`
- Test: `tests/Unit/Models/DhcpSyncStateTest.php`
- Test: `tests/Unit/Models/DhcpSnoopingObservationTest.php`

- [ ] **Step 1: Write pool statuses migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_pool_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('integration');
            $table->string('address_family');
            $table->decimal('total', 39, 0)->default(0);
            $table->decimal('used', 39, 0)->default(0);
            $table->decimal('available', 39, 0)->default(0);
            $table->decimal('utilisation', 5, 4)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration', 'address_family']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_pool_statuses');
    }
};
```

- [ ] **Step 2: Write sync states migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->string('integration');
            $table->string('address_family');
            $table->string('dataset');
            $table->unsignedInteger('empty_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['integration', 'address_family', 'dataset']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_sync_states');
    }
};
```

- [ ] **Step 3: Write snooping observations migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_snooping_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('switch_config_id')->constrained('switch_configs')->cascadeOnDelete();
            $table->unsignedInteger('vlan')->default(0);
            $table->string('ip');
            $table->string('mac');
            $table->string('interface')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['switch_config_id', 'vlan', 'ip', 'mac'], 'dhcp_snooping_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_snooping_observations');
    }
};
```

- [ ] **Step 4: Create models and factories**

Create all three models (`DhcpPoolStatusRecord`, `DhcpSyncState`, `DhcpSnoopingObservation`) following the same pattern as `DhcpRangeRecord` from Task 1. Each with `$fillable` matching columns, `HasFactory` trait, and appropriate docblock `@property` annotations. `DhcpSnoopingObservation` should have a `belongsTo(SwitchConfig::class)` relationship and casts for `expires_at` and `observed_at` as `datetime`.

- [ ] **Step 5: Create factories for all three models**

Each factory should produce valid default records. `DhcpPoolStatusRecordFactory` defaults to `integration=cisco, address_family=ipv4, total=254, used=50, available=204, utilisation=0.1969, synced_at=now()`. `DhcpSyncStateFactory` defaults to `integration=cisco, address_family=ipv4, dataset=leases, empty_count=0`. `DhcpSnoopingObservationFactory` needs a `SwitchConfig::factory()` for `switch_config_id`, plus `vlan=100, ip=10.0.0.50, mac=AA:BB:CC:DD:EE:FF, observed_at=now()`.

- [ ] **Step 6: Write unit tests for each model**

Test creation, unique constraints, relationships (snooping → SwitchConfig), and factory states. Follow the pattern from Task 1's `DhcpRangeRecordTest`.

- [ ] **Step 7: Run all tests**

Run: `php artisan test tests/Unit/Models/DhcpPoolStatusRecordTest.php tests/Unit/Models/DhcpSyncStateTest.php tests/Unit/Models/DhcpSnoopingObservationTest.php --parallel`
Expected: All PASS.

- [ ] **Step 8: Run full migration test**

Run: `php artisan migrate:fresh --env=testing`
Expected: All migrations run without error.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_06_08_00000{3,4,5}_*.php app/Models/Dhcp{PoolStatusRecord,SyncState,SnoopingObservation}.php database/factories/Dhcp{PoolStatusRecord,SyncState,SnoopingObservation}Factory.php tests/Unit/Models/Dhcp{PoolStatusRecord,SyncState,SnoopingObservation}Test.php
git commit -m "feat(dhcp): add pool status, sync state, and snooping observation tables"
```

---

## Task 4: Integration Enum + Config

**Files:**
- Modify: `app/Enums/Integration.php`
- Modify: `config/integrations.php`
- Test: `tests/Unit/Enums/IntegrationEnumTest.php` (add test)
- Test: `tests/Feature/Admin/IntegrationFieldDefinitionsTest.php` (existing — verify no regression)

- [ ] **Step 1: Add Cisco to Integration enum**

In `app/Enums/Integration.php`, add after `case VyOs = 'vyos';`:

```php
case Cisco = 'cisco';
```

- [ ] **Step 2: Add Cisco config to `config/integrations.php`**

Add a new `'cisco'` key to the returned array, after the `'vyos'` entry. Follow the existing pattern — `fields` array with field definitions, `capabilities` array, `validation` rules:

```php
'cisco' => [
    'name' => 'Cisco',
    'description' => 'Cisco IOS switch providing DHCP server capabilities.',
    'fields' => [
        'switch_id' => [
            'label' => 'DHCP Switch',
            'type' => 'select',
            'options' => 'switch_configs',
            'help' => 'Select the switch running the DHCP server.',
        ],
        'pool_size' => [
            'label' => 'Pool Size Override',
            'type' => 'number',
            'help' => 'Optional total pool size override. When empty, derived from switch.',
        ],
        'ipv6_enabled' => [
            'label' => 'DHCPv6 Enabled',
            'type' => 'checkbox',
            'default' => true,
            'help' => 'Enable DHCPv6 data collection. Disable for older IOS without IPv6 DHCP.',
        ],
    ],
    'capabilities' => [
        'dhcp',
    ],
    'validation' => [
        'switch_id' => 'nullable|integer|exists:switch_configs,id',
        'pool_size' => 'nullable|integer|min:0',
        'ipv6_enabled' => 'nullable|boolean',
    ],
],
```

- [ ] **Step 3: Write test for enum**

Add to existing enum tests or create new:

```php
public function test_cisco_integration_exists(): void
{
    $this->assertSame('cisco', Integration::Cisco->value);
}
```

- [ ] **Step 4: Run integration field definitions test**

Run: `php artisan test tests/Feature/Admin/IntegrationFieldDefinitionsTest.php --parallel`
Expected: PASS (no regression — existing test validates field structures).

- [ ] **Step 5: Commit**

```bash
git add app/Enums/Integration.php config/integrations.php tests/
git commit -m "feat(cisco): add Cisco to Integration enum and config"
```

---

## Task 5: CiscoBootstrapper

**Files:**
- Create: `app/Integration/CiscoBootstrapper.php`
- Modify: `app/Providers/IntegrationServiceProvider.php`
- Test: `tests/Unit/Integration/CiscoBootstrapperTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\CiscoBootstrapper;
use App\Integration\IntegrationBootstrapper;
use Tests\TestCase;

class CiscoBootstrapperTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(IntegrationBootstrapper::class, new CiscoBootstrapper);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Integration/CiscoBootstrapperTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create CiscoBootstrapper**

```php
<?php

declare(strict_types=1);

namespace App\Integration;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Models\SwitchConfig;
use App\Services\Cisco\CiscoDhcpService;
use App\Services\Interfaces\DhcpInterface;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class CiscoBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        $app->extend(DhcpInterface::class, function (DhcpInterface $service, Application $app): DhcpInterface {
            if ($this->isActive(Capability::Dhcp->value)) {
                return $this->buildDhcpService($app);
            }

            return $service;
        });
    }

    private function buildDhcpService(Application $app): DhcpInterface
    {
        $switchId = IntegrationConfig::getValue(Integration::Cisco->value, 'switch_id');
        if ($switchId === null) {
            return $app->make(DhcpInterface::class);
        }

        $switchConfig = SwitchConfig::find((int) $switchId);
        if ($switchConfig === null) {
            return $app->make(DhcpInterface::class);
        }

        $factory = $app->make(SwitchServiceFactory::class);
        $transport = $factory->createTransport($switchConfig);

        return new CiscoDhcpService(
            transport: $transport,
            parser: new IosOutputParser,
            poolSize: (string) IntegrationConfig::getValue(Integration::Cisco->value, 'pool_size', '0'),
            ipv6Enabled: (bool) IntegrationConfig::getValue(Integration::Cisco->value, 'ipv6_enabled', true),
        );
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider(Integration::Cisco->value, $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
```

Note: `SwitchServiceFactory::createTransport()` may be private. If so, the implementation task will need to extract it to a public or protected method, or create the transport directly. Check during implementation — the factory's `createTransport()` builds the appropriate `SshProxyTransport` or `DirectSshTransport` from a `SwitchConfig`.

- [ ] **Step 4: Register in IntegrationServiceProvider**

In `app/Providers/IntegrationServiceProvider.php`, method `registerCapabilityBindings()`, add:

```php
(new CiscoBootstrapper)->register($this->app);
```

Add the import: `use App\Integration\CiscoBootstrapper;`

- [ ] **Step 5: Run test**

Run: `php artisan test tests/Unit/Integration/CiscoBootstrapperTest.php`
Expected: PASS.

- [ ] **Step 6: Run existing integration tests for regression**

Run: `php artisan test tests/Unit/Providers/IntegrationServiceProviderTest.php --parallel`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Integration/CiscoBootstrapper.php app/Providers/IntegrationServiceProvider.php tests/Unit/Integration/CiscoBootstrapperTest.php
git commit -m "feat(cisco): add CiscoBootstrapper for DHCP capability binding"
```

---

## Task 6: IOS Output Parser — DHCP Binding Table

**Files:**
- Modify: `app/Services/NetworkSwitch/IosOutputParser.php`
- Test: `tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`

- [ ] **Step 1: Write failing tests for IPv4 DHCP binding parsing**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\NetworkSwitch\IosOutputParser;
use PHPUnit\Framework\TestCase;

class IosOutputParserDhcpTest extends TestCase
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IosOutputParser;
    }

    public function test_parse_dhcp_binding_table(): void
    {
        $output = implode("\r\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
            '10.0.0.50           0100.1122.3344.55       Jun 08 2026 12:00 AM    Automatic  Active     Vlan100',
            '10.0.0.51           0100.aabb.ccdd.ee       Jun 08 2026 01:00 AM    Automatic  Active     Vlan100',
        ]);

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertCount(2, $result);
        $this->assertSame('10.0.0.50', $result[0]['ip']);
        $this->assertSame('00:11:22:33:44:55', $result[0]['mac']);
        $this->assertStringContains('Jun 08 2026', $result[0]['expires']);
        $this->assertSame('10.0.0.51', $result[1]['ip']);
    }

    public function test_parse_dhcp_binding_table_rejects_error_output(): void
    {
        $output = "% Invalid input detected at '^' marker.";

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_binding_table_empty_output(): void
    {
        $output = '';

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertSame([], $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`
Expected: FAIL — method not found.

- [ ] **Step 3: Implement `parseDhcpBindingTable()` in IosOutputParser**

Add to `app/Services/NetworkSwitch/IosOutputParser.php`:

```php
/**
 * @return list<array{ip: string, mac: string|null, expires: string, type: string, state: string, interface: string}>
 */
public function parseDhcpBindingTable(string $output): array
{
    if ($this->isErrorOutput($output)) {
        return [];
    }

    $lines = explode("\n", str_replace("\r\n", "\n", $output));
    $results = [];

    foreach ($lines as $line) {
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+(\S+)\s+(.+?)\s+(Automatic|Manual)\s+(\S+)\s+(\S+)\s*$/', trim($line), $matches)) {
            $results[] = [
                'ip' => $matches[1],
                'mac' => $this->extractMacFromClientId($matches[2]),
                'expires' => trim($matches[3]),
                'type' => $matches[4],
                'state' => $matches[5],
                'interface' => $matches[6],
            ];
        }
    }

    return $results;
}

private function extractMacFromClientId(string $clientId): ?string
{
    $hex = str_replace('.', '', $clientId);
    if (strlen($hex) === 14 && str_starts_with($hex, '01')) {
        $mac = substr($hex, 2);
        return strtoupper(implode(':', str_split($mac, 2)));
    }
    if (strlen($hex) === 12 && ctype_xdigit($hex)) {
        return strtoupper(implode(':', str_split($hex, 2)));
    }
    return null;
}

private function isErrorOutput(string $output): bool
{
    return $output === '' || preg_match('/^%\s+(Invalid|Incomplete|Ambiguous)/m', $output) === 1;
}
```

- [ ] **Step 4: Run tests**

Run: `php artisan test tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`
Expected: All PASS.

- [ ] **Step 5: Run existing parser tests for regression**

Run: `php artisan test tests/Unit/Services/NetworkSwitch/IosOutputParserTest.php --parallel`
Expected: All PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/NetworkSwitch/IosOutputParser.php tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php
git commit -m "feat(cisco): add DHCP binding table parser to IosOutputParser"
```

---

## Task 7: IOS Output Parser — Pool Stats + Pool Config + Effective Ranges

**Files:**
- Modify: `app/Services/NetworkSwitch/IosOutputParser.php`
- Test: `tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php` (add more tests)

- [ ] **Step 1: Write failing tests for pool stats parsing**

Add to `IosOutputParserDhcpTest`:

```php
public function test_parse_dhcp_pool_stats(): void
{
    $output = implode("\r\n", [
        'Pool LAN :',
        ' Utilization mark (high/low)    : 100 / 0',
        ' Subnet size (first/next)       : 0 / 0',
        ' Total addresses                : 254',
        ' Leased addresses               : 50',
        ' Pending event                  : none',
        ' 1 subnet is currently in the pool :',
        ' Current index        IP address range                    Leased addresses',
        ' 10.0.0.1             10.0.0.1     - 10.0.0.254           50',
    ]);

    $result = $this->parser->parseDhcpPoolStats($output);

    $this->assertCount(1, $result);
    $this->assertSame('LAN', $result[0]['name']);
    $this->assertSame('254', $result[0]['total']);
    $this->assertSame('50', $result[0]['leased']);
}
```

- [ ] **Step 2: Write failing tests for pool config parsing**

```php
public function test_parse_dhcp_pool_config(): void
{
    $output = implode("\r\n", [
        'ip dhcp excluded-address 10.0.0.1 10.0.0.9',
        'ip dhcp excluded-address 10.0.0.250 10.0.0.254',
        '!',
        'ip dhcp pool LAN',
        ' network 10.0.0.0 255.255.255.0',
        ' default-router 10.0.0.1',
        ' dns-server 8.8.8.8',
    ]);

    $result = $this->parser->parseDhcpPoolConfig($output);

    $this->assertCount(1, $result['pools']);
    $this->assertSame('LAN', $result['pools'][0]['name']);
    $this->assertSame('10.0.0.0', $result['pools'][0]['network']);
    $this->assertSame('255.255.255.0', $result['pools'][0]['mask']);
    $this->assertCount(2, $result['excluded']);
}
```

- [ ] **Step 3: Write failing tests for effective range computation**

```php
public function test_compute_effective_ranges_with_exclusions(): void
{
    $config = [
        'pools' => [
            [
                'name' => 'LAN',
                'network' => '10.0.0.0',
                'mask' => '255.255.255.0',
                'gateway' => '10.0.0.1',
            ],
        ],
        'excluded' => [
            ['start' => '10.0.0.1', 'end' => '10.0.0.9'],
            ['start' => '10.0.0.250', 'end' => '10.0.0.254'],
        ],
    ];

    $ranges = $this->parser->computeEffectiveRanges($config);

    $this->assertCount(1, $ranges);
    $this->assertSame('10.0.0.10', $ranges[0]['range_from']);
    $this->assertSame('10.0.0.249', $ranges[0]['range_to']);
    $this->assertSame('240', $ranges[0]['total_addresses']);
}
```

- [ ] **Step 4: Run tests to verify they fail**

Run: `php artisan test tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`
Expected: FAIL — methods not found.

- [ ] **Step 5: Implement all three methods**

Implement `parseDhcpPoolStats()`, `parseDhcpPoolConfig()`, and `computeEffectiveRanges()` in `IosOutputParser`. Use regex to parse the IOS output formats. `computeEffectiveRanges()` uses `ip2long()`/`long2ip()` to do integer arithmetic on IPv4 addresses, subtracting excluded ranges from the pool network to produce discrete valid ranges. Use BCMath (`bcadd`, `bcsub`) for address counts.

- [ ] **Step 6: Run tests**

Run: `php artisan test tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php`
Expected: All PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/NetworkSwitch/IosOutputParser.php tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php
git commit -m "feat(cisco): add pool stats, pool config, and effective range parsers"
```

---

## Task 8: IOS Output Parser — DHCPv6 + Snooping

**Files:**
- Modify: `app/Services/NetworkSwitch/IosOutputParser.php`
- Test: `tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php` (add DHCPv6 + snooping tests)

- [ ] **Step 1: Write failing tests for DHCPv6 binding, pool, config, and snooping table parsing**

Add tests for `parseDhcpv6BindingTable()`, `parseDhcpv6PoolStats()`, `parseDhcpv6PoolConfig()`, and `parseDhcpSnoopingTable()` with representative IOS output fixtures. DHCPv6 bindings use DUID/IAID — test that MAC is extracted from DUID-LL/DUID-LLT types and is null for DUID-EN/DUID-UUID types.

- [ ] **Step 2: Run to verify failure**

- [ ] **Step 3: Implement all four methods**

- [ ] **Step 4: Run tests — all pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/NetworkSwitch/IosOutputParser.php tests/Unit/Services/NetworkSwitch/IosOutputParserDhcpTest.php
git commit -m "feat(cisco): add DHCPv6 and snooping table parsers to IosOutputParser"
```

---

## Task 9: DhcpLease Value Object — Nullable MAC

**Files:**
- Modify: `app/Services/ValueObjects/DhcpLease.php`
- Test: `tests/Unit/Services/ValueObjects/DhcpLeaseTest.php`

- [ ] **Step 1: Write failing test**

```php
public function test_dhcp_lease_allows_nullable_mac(): void
{
    $lease = new DhcpLease(ip: '2001:db8::1', mac: null, hostname: 'host', expires: '2026-06-08');

    $this->assertNull($lease->mac);
}
```

- [ ] **Step 2: Update value object**

In `app/Services/ValueObjects/DhcpLease.php`, change `public string $mac` to `public ?string $mac`:

```php
readonly class DhcpLease
{
    public function __construct(
        public string $ip,
        public ?string $mac,
        public string $hostname,
        public string $expires,
    ) {}
}
```

- [ ] **Step 3: Run test + full test suite for regressions**

Run: `php artisan test --parallel`
Expected: All PASS. Check that existing code using `$lease->mac` handles the null case (DhcpController serializes it, ScanNetworkDevices persists it — both may need null checks).

- [ ] **Step 4: Fix any regressions from nullable mac**

Update `PersistMacsStep`, `PersistDhcpLeasesStep`, `LinkIpMacStep` to skip entries with null MAC. Update `DhcpController::leases()` serialization to handle null mac.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ValueObjects/DhcpLease.php tests/
git commit -m "feat(dhcp): make DhcpLease mac nullable for DHCPv6 DUID bindings"
```

---

## Task 10: CiscoDhcpService

**Files:**
- Create: `app/Services/Cisco/CiscoDhcpService.php`
- Test: `tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php`

- [ ] **Step 1: Write failing tests**

Test all `DhcpInterface` methods. Mock `SwitchCommandTransportInterface` to return fixture CLI output. Mock `IosOutputParser` (or use real parser with fixture output). Verify:
- `getLeases()` returns correct `DhcpLease` VOs (IPv4 + IPv6 when enabled)
- `getRanges()` returns correct `DhcpRange` VOs with computed effective ranges
- `getPoolStatus()` returns aggregated `DhcpPoolStatus`
- `getLease('10.0.0.50')` returns matching lease
- `getLease('10.0.0.99')` returns null
- `resetSnapshot()` clears cached data
- IPv6 failure doesn't block IPv4 data
- `getFetchStatus()` returns per-family success/failure
- Transport `disconnect()` called even on exception

- [ ] **Step 2: Run tests — all fail**

- [ ] **Step 3: Implement CiscoDhcpService**

```php
<?php

declare(strict_types=1);

namespace App\Services\Cisco;

use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\IosOutputParser;
use App\Services\ValueObjects\DhcpLease;
use App\Services\ValueObjects\DhcpPoolStatus;
use App\Services\ValueObjects\DhcpRange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CiscoDhcpService implements DhcpInterface
{
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    /** @var array{ipv4: bool, ipv6: bool} */
    private array $fetchStatus = ['ipv4' => false, 'ipv6' => false];

    public function __construct(
        private SwitchCommandTransportInterface $transport,
        private IosOutputParser $parser,
        private string $poolSize = '0',
        private bool $ipv6Enabled = true,
    ) {}

    public function getPoolStatus(): DhcpPoolStatus
    {
        $this->ensureSnapshot();
        // Aggregate pool stats from snapshot, use poolSize override if set
        // Implementation uses BCMath for all arithmetic
    }

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection
    {
        $this->ensureSnapshot();
        // Combine IPv4 and IPv6 leases from snapshot
    }

    public function getLease(string $ipAddress): ?DhcpLease
    {
        return $this->getLeases()->first(fn (DhcpLease $lease): bool => $lease->ip === $ipAddress);
    }

    /** @return Collection<int, DhcpRange> */
    public function getRanges(): Collection
    {
        $this->ensureSnapshot();
        // Build DhcpRange VOs from parsed config + stats
    }

    /** @return array{ipv4: bool, ipv6: bool} */
    public function getFetchStatus(): array
    {
        return $this->fetchStatus;
    }

    public function resetSnapshot(): void
    {
        $this->snapshot = null;
        $this->fetchStatus = ['ipv4' => false, 'ipv6' => false];
    }

    private function ensureSnapshot(): void
    {
        if ($this->snapshot !== null) {
            return;
        }
        $this->fetchSnapshot();
    }

    private function fetchSnapshot(): void
    {
        $commands = [
            'show ip dhcp binding',
            'show ip dhcp pool',
            'show running-config | section ip dhcp',
        ];

        if ($this->ipv6Enabled) {
            $commands[] = 'show ipv6 dhcp binding';
            $commands[] = 'show ipv6 dhcp pool';
            $commands[] = 'show running-config | section ipv6 dhcp pool';
        }

        try {
            $outputs = $this->transport->executeMultiple($commands);

            $this->snapshot = [
                'ipv4_bindings' => $this->parser->parseDhcpBindingTable($outputs['show ip dhcp binding'] ?? ''),
                'ipv4_pools' => $this->parser->parseDhcpPoolStats($outputs['show ip dhcp pool'] ?? ''),
                'ipv4_config' => $this->parser->parseDhcpPoolConfig($outputs['show running-config | section ip dhcp'] ?? ''),
            ];
            $this->fetchStatus['ipv4'] = true;

            if ($this->ipv6Enabled) {
                try {
                    $this->snapshot['ipv6_bindings'] = $this->parser->parseDhcpv6BindingTable($outputs['show ipv6 dhcp binding'] ?? '');
                    $this->snapshot['ipv6_pools'] = $this->parser->parseDhcpv6PoolStats($outputs['show ipv6 dhcp pool'] ?? '');
                    $this->snapshot['ipv6_config'] = $this->parser->parseDhcpv6PoolConfig($outputs['show running-config | section ipv6 dhcp pool'] ?? '');
                    $this->fetchStatus['ipv6'] = true;
                } catch (Throwable $e) {
                    Log::warning('Failed to fetch Cisco DHCPv6 data', ['error' => $e->getMessage()]);
                    $this->snapshot['ipv6_bindings'] = [];
                    $this->snapshot['ipv6_pools'] = [];
                    $this->snapshot['ipv6_config'] = [];
                }
            }
        } finally {
            try {
                $this->transport->disconnect();
            } catch (Throwable) {
                // Best-effort disconnect
            }
        }
    }
}
```

- [ ] **Step 4: Run tests — all pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Cisco/CiscoDhcpService.php tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php
git commit -m "feat(cisco): implement CiscoDhcpService with snapshot-based fetching"
```

---

## Task 11: DhcpPoolThresholdReached — Add Address Family

**Files:**
- Modify: `app/Events/DhcpPoolThresholdReached.php`
- Test: `tests/Unit/Events/DhcpPoolThresholdReachedTest.php` (existing — add test)

- [ ] **Step 1: Write failing test**

```php
public function test_event_includes_address_family(): void
{
    $event = new DhcpPoolThresholdReached('LAN', 0.9, 0.8, 'ipv4');

    $this->assertSame('ipv4', $event->addressFamily);
}
```

- [ ] **Step 2: Add `addressFamily` parameter to constructor**

In `app/Events/DhcpPoolThresholdReached.php`, add `public string $addressFamily = 'ipv4'` as the fourth constructor parameter with a default value for backward compatibility.

- [ ] **Step 3: Run tests — all pass including existing**

- [ ] **Step 4: Commit**

```bash
git add app/Events/DhcpPoolThresholdReached.php tests/Unit/Events/DhcpPoolThresholdReachedTest.php
git commit -m "feat(dhcp): add addressFamily to DhcpPoolThresholdReached event"
```

---

## Task 12: SyncDhcpData Job

**Files:**
- Create: `app/Jobs/SyncDhcpData.php`
- Test: `tests/Feature/Jobs/SyncDhcpDataTest.php`

- [ ] **Step 1: Write failing tests**

Test scenarios:
- Job resolves `DhcpInterface`, fetches data, persists to DB tables
- `NullDhcpService` → job skips sync (no records created)
- Leases upserted by `(integration, ip_address_id)`, missing leases deleted
- Ranges upserted by unique key, missing ranges deleted
- Pool status upserted by `(integration, address_family)`
- Sync state updated with timestamps
- Empty-result guard: skip deletion when previous had data and current is empty
- Empty-result guard: proceed with deletion after 3 consecutive empty syncs
- Per-family reconciliation: IPv6 failure doesn't delete IPv4 data
- Threshold event fired only on crossing (not sustained)
- Cache populated after commit
- `ShouldBeUnique` interface implemented
- IpAddress/MacAddress records created via `firstOrCreate`
- Nullable MAC (DHCPv6) → `mac_address_id` nullable on lease

- [ ] **Step 2: Run tests — all fail**

- [ ] **Step 3: Implement SyncDhcpData**

Key implementation details:
- Implements `ShouldQueue`, `ShouldBeUnique`
- `$timeout = 120`, `$tries = 3`, `$backoff = [30, 60]`
- `handle(DhcpInterface $dhcp)` method
- Use `DB::transaction()` for all persistence
- Use `DB::afterCommit()` for cache + events
- All count arithmetic via BCMath
- Structured logging with `Log::info('dhcp.sync.*', [...])`

- [ ] **Step 4: Run tests — all pass**

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SyncDhcpData.php tests/Feature/Jobs/SyncDhcpDataTest.php
git commit -m "feat(dhcp): implement SyncDhcpData async polling job"
```

---

## Task 13: Schedule Job + Artisan Command

**Files:**
- Create: `app/Console/Commands/SyncDhcpOnce.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Unit/Console/SyncDhcpOnceTest.php`

- [ ] **Step 1: Write Artisan command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncDhcpData;
use Illuminate\Console\Command;

class SyncDhcpOnce extends Command
{
    protected $signature = 'dhcp:sync {--once : Run a single sync immediately}';

    protected $description = 'Dispatch a DHCP data sync job immediately';

    public function handle(): int
    {
        SyncDhcpData::dispatchSync();
        $this->info('DHCP sync completed.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Register in Kernel scheduler**

In `app/Console/Kernel.php`, add to `schedule()`:

```php
$schedule->job(new \App\Jobs\SyncDhcpData)
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();
```

- [ ] **Step 3: Write test + run**

- [ ] **Step 4: Run Kernel test for regression**

Run: `php artisan test tests/Unit/Console/KernelTest.php --parallel`

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SyncDhcpOnce.php app/Console/Kernel.php tests/
git commit -m "feat(dhcp): schedule SyncDhcpData job and add dhcp:sync command"
```

---

## Task 14: Refactor DhcpController to Read from DB

**Files:**
- Modify: `app/Http/Controllers/Admin/DhcpController.php`
- Test: `tests/Feature/Admin/DhcpControllerTest.php` (rewrite to use DB fixtures)

- [ ] **Step 1: Write new controller tests using DB-backed data**

Replace mock-based tests with tests that create `DhcpRangeRecord`, `DhcpLease`, `DhcpPoolStatusRecord` factory records and assert the controller returns them correctly. Test scoping by integration — records from other integrations should not appear. Test freshness indicator from `DhcpSyncState`. Test rollout fallback when no sync data exists.

- [ ] **Step 2: Run tests — fail (controller still injects DhcpInterface)**

- [ ] **Step 3: Rewrite DhcpController**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CapabilityAssignment;
use App\Models\DhcpLease;
use App\Models\DhcpPoolStatusRecord;
use App\Models\DhcpRangeRecord;
use App\Models\DhcpSyncState;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DhcpController extends Controller
{
    public function index(): Response
    {
        $integration = $this->activeIntegration();

        $ranges = DhcpRangeRecord::where('integration', $integration)
            ->get()
            ->map(fn (DhcpRangeRecord $range): array => [
                'name' => $range->interface ?: ($range->description ?: 'Default'),
                'ip_version' => $range->type === 'ipv4' ? 'IPv4' : 'IPv6',
                'network' => $range->subnet ?: $range->prefix,
                'start' => $range->range_from,
                'end' => $range->range_to,
                'used' => (int) ($range->used_addresses ?? 0),
                'total' => (int) ($range->total_addresses ?? 0),
                'percentage' => $range->utilisation !== null ? round((float) $range->utilisation * 100, 1) : 0,
            ]);

        $syncState = DhcpSyncState::where('integration', $integration)
            ->where('dataset', 'ranges')
            ->first();

        return Inertia::render('Admin/Dhcp/Index', [
            'ranges' => $ranges,
            'lastSyncedAt' => $syncState?->last_success_at?->toIso8601String(),
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'DHCP'],
            ],
        ]);
    }

    public function leases(): Response
    {
        $integration = $this->activeIntegration();

        $leases = DhcpLease::where('integration', $integration)
            ->with(['ipAddress', 'macAddress'])
            ->get()
            ->map(fn (DhcpLease $lease): array => [
                'ip' => $lease->ipAddress?->address ?? '',
                'mac' => $lease->macAddress?->mac_address ?? '',
                'hostname' => $lease->hostname ?? '',
                'expires' => $lease->expires_at?->toIso8601String() ?? '',
            ])
            ->values()
            ->all();

        $ranges = DhcpRangeRecord::where('integration', $integration)
            ->get()
            ->map(fn (DhcpRangeRecord $range): array => [
                'name' => $range->interface ?: ($range->description ?: 'Default'),
                'network' => $range->subnet ?: $range->prefix,
                'start' => $range->range_from,
                'end' => $range->range_to,
            ]);

        return Inertia::render('Admin/Dhcp/Leases', [
            'leases' => $leases,
            'ranges' => $ranges,
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'DHCP', 'href' => route('admin.dhcp.index')],
                ['label' => 'Leases'],
            ],
        ]);
    }

    private function activeIntegration(): ?string
    {
        try {
            $assignment = CapabilityAssignment::where('capability', 'dhcp')->first();

            return $assignment?->integration;
        } catch (\Throwable) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run tests — all pass**

- [ ] **Step 5: Run full test suite for regressions**

Run: `php artisan test --parallel`

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/DhcpController.php tests/Feature/Admin/DhcpControllerTest.php
git commit -m "refactor(dhcp): controller reads from DB instead of live DhcpInterface"
```

---

## Task 15: DHCP Snooping — Interface + Adapter + Parser

**Files:**
- Create: `app/Services/Interfaces/SupportsDhcpSnooping.php`
- Modify: `app/Services/NetworkSwitch/CiscoSwitchAdapter.php`
- Modify: `app/Services/NetworkSwitch/IosOutputParser.php` (already done in Task 8)
- Test: `tests/Unit/Services/NetworkSwitch/CiscoSwitchAdapterSnoopingTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\Interfaces\SupportsDhcpSnooping;
use App\Services\Interfaces\SwitchCommandTransportInterface;
use App\Services\NetworkSwitch\CiscoSwitchAdapter;
use App\Services\NetworkSwitch\IosOutputParser;
use PHPUnit\Framework\TestCase;

class CiscoSwitchAdapterSnoopingTest extends TestCase
{
    public function test_implements_supports_dhcp_snooping(): void
    {
        $transport = $this->createMock(SwitchCommandTransportInterface::class);
        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);

        $this->assertInstanceOf(SupportsDhcpSnooping::class, $adapter);
    }

    public function test_get_dhcp_snooping_bindings_returns_collection(): void
    {
        $transport = $this->createMock(SwitchCommandTransportInterface::class);
        $transport->method('execute')
            ->willReturn(implode("\r\n", [
                'MacAddress          IpAddress        Lease(sec)  Type           VLAN  Interface',
                '-----------------   ---------------  ----------  -------------  ----  --------------------',
                '00:11:22:33:44:55   10.0.0.50        86400       dhcp-snooping   100   GigabitEthernet1/0/1',
                'AA:BB:CC:DD:EE:FF   10.0.0.51        86400       dhcp-snooping   100   GigabitEthernet1/0/2',
            ]));

        $adapter = new CiscoSwitchAdapter($transport, new IosOutputParser);
        $bindings = $adapter->getDhcpSnoopingBindings();

        $this->assertCount(2, $bindings);
    }
}
```

- [ ] **Step 2: Run test — fails**

- [ ] **Step 3: Create SupportsDhcpSnooping interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use Illuminate\Support\Collection;

interface SupportsDhcpSnooping
{
    /** @return Collection<int, array{ip: string, mac: string, vlan: int, interface: string, lease_seconds: int}> */
    public function getDhcpSnoopingBindings(): Collection;
}
```

- [ ] **Step 4: Implement on CiscoSwitchAdapter**

Add `SupportsDhcpSnooping` to the implements list and add:

```php
public function getDhcpSnoopingBindings(): Collection
{
    $output = $this->transport->execute('show ip dhcp snooping binding');

    return collect($this->parser->parseDhcpSnoopingTable($output));
}
```

- [ ] **Step 5: Run tests — pass**

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/SupportsDhcpSnooping.php app/Services/NetworkSwitch/CiscoSwitchAdapter.php tests/Unit/Services/NetworkSwitch/CiscoSwitchAdapterSnoopingTest.php
git commit -m "feat(snooping): add SupportsDhcpSnooping interface and implement on CiscoSwitchAdapter"
```

---

## Task 16: Snooping in PortSyncService

**Files:**
- Modify: `app/Services/NetworkSwitch/PortSyncService.php`
- Test: `tests/Unit/Services/NetworkSwitch/PortSyncServiceSnoopingTest.php`

- [ ] **Step 1: Write failing tests**

Test that `PortSyncService` checks `instanceof SupportsDhcpSnooping`, calls `getDhcpSnoopingBindings()`, upserts `DhcpSnoopingObservation` records with canonicalised IP/MAC/VLAN, and deletes stale observations for the switch.

- [ ] **Step 2: Run — fail**

- [ ] **Step 3: Implement snooping processing in PortSyncService**

Add a method that runs after port sync, checks adapter type, processes bindings. Use `MacAddress::normalize()` for MAC, `inet_ntop(inet_pton($ip))` for IP canonicalisation.

- [ ] **Step 4: Run tests — pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/NetworkSwitch/PortSyncService.php tests/Unit/Services/NetworkSwitch/PortSyncServiceSnoopingTest.php
git commit -m "feat(snooping): process DHCP snooping bindings during port sync"
```

---

## Task 17: DhcpSnoopingResolver

**Files:**
- Create: `app/Services/NetworkScan/DhcpSnoopingResolver.php`
- Test: `tests/Unit/Services/NetworkScan/DhcpSnoopingResolverTest.php`

- [ ] **Step 1: Write failing tests**

Test that the resolver queries `dhcp_snooping_observations` and returns IP/MAC mappings. Test filtering by expiry. Test canonical format of returned data.

- [ ] **Step 2: Implement resolver**

```php
<?php

declare(strict_types=1);

namespace App\Services\NetworkScan;

use App\Models\DhcpSnoopingObservation;
use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;

class DhcpSnoopingResolver
{
    /** @return Collection<int, ArpEntry> */
    public function getObservedMappings(): Collection
    {
        return DhcpSnoopingObservation::query()
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get()
            ->map(fn (DhcpSnoopingObservation $obs): ArpEntry => new ArpEntry(
                ip: $obs->ip,
                mac: $obs->mac,
            ))
            ->unique(fn (ArpEntry $entry): string => $entry->ip . '|' . $entry->mac)
            ->values();
    }
}
```

- [ ] **Step 3: Run tests — pass**

- [ ] **Step 4: Commit**

```bash
git add app/Services/NetworkScan/DhcpSnoopingResolver.php tests/Unit/Services/NetworkScan/DhcpSnoopingResolverTest.php
git commit -m "feat(snooping): add DhcpSnoopingResolver for IP/MAC enrichment"
```

---

## Task 18: Remove Legacy CiscoService

**Files:**
- Delete: `app/Services/CiscoService.php`
- Delete: `tests/Unit/Services/CiscoServiceTest.php`
- Delete: `tests/Unit/Services/CiscoServiceConstructorTest.php`
- Modify: any files referencing CiscoService

- [ ] **Step 1: Verify no remaining references**

Run: `grep -rn 'CiscoService' app/ config/ routes/ tests/ --include='*.php' | grep -v '.claude/'`

Expected: Only hits in `app/Services/CiscoService.php` and its test files. If other files reference it, update them first.

- [ ] **Step 2: Delete files**

```bash
rm app/Services/CiscoService.php
rm tests/Unit/Services/CiscoServiceTest.php
rm tests/Unit/Services/CiscoServiceConstructorTest.php
```

- [ ] **Step 3: Run full test suite**

Run: `php artisan test --parallel`
Expected: All PASS — nothing depended on the removed class.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: remove legacy CiscoService (replaced by SwitchCommandTransportInterface)"
```

---

## Task 19: Final Integration Tests + Quality Gates

**Files:**
- Test: `tests/Feature/Dhcp/DhcpIntegrationTest.php`

- [ ] **Step 1: Write end-to-end integration test**

Test the full flow: configure Cisco integration with capability assignment, mock transport returning fixture output, run `SyncDhcpData` job, verify DB state, verify controller returns correct data.

- [ ] **Step 2: Run Laravel Pint**

Run: `./vendor/bin/pint`
Expected: No formatting issues (or fix them).

- [ ] **Step 3: Run PHPStan Level 8**

Run: `./vendor/bin/phpstan analyse --level=8`
Expected: No new errors.

- [ ] **Step 4: Run Rector**

Run: `./vendor/bin/rector process --dry-run`
Expected: No suggestions (or apply them).

- [ ] **Step 5: Run full test suite with coverage**

Run: `XDEBUG_MODE=coverage php artisan test --parallel --coverage`
Expected: All new code at 100% coverage.

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "test(dhcp): add full integration tests for Cisco DHCP flow"
```

---

## Execution Notes

- Tasks 1-3 (database) have no dependencies and can run in parallel.
- Tasks 4-5 (enum + bootstrapper) depend on each other sequentially.
- Tasks 6-8 (parser) depend on each other sequentially but are independent of Tasks 4-5.
- Task 9 (nullable MAC) should run before Task 10 (CiscoDhcpService).
- Task 10 depends on Tasks 5, 7, 8, 9.
- Task 11 (event) is independent.
- Task 12 (sync job) depends on Tasks 1-3, 10, 11.
- Task 13 (scheduler) depends on Task 12.
- Task 14 (controller) depends on Tasks 1-3.
- Tasks 15-17 (snooping) depend on Task 8 (parser) and Task 3 (snooping table).
- Task 18 (cleanup) can run anytime after Task 10.
- Task 19 (integration tests) depends on everything.
