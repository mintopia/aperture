# Network Device Tracking — Data Layer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the unified data model for network device tracking — schema, models, pivot tables, DHCP lease persistence, audit log, ScanNetworkDevices refactor, user login cascade, OUI policy, Cisco bulk optimization, and consumer updates.

**Architecture:** Big-bang migration creates three new tables (`ip_address_mac_address`, `dhcp_leases`, `audit_logs`), modifies three existing tables (`mac_addresses`, `ip_addresses`, `switch_port_macs`), and migrates existing FK data into the new pivot. Models gain many-to-many relationships with convenience helpers (`currentMac()`, `currentIp()`, `currentHostname()`). ScanNetworkDevices is split into greedy discovery + OUI policy. User login gains MAC ownership cascade for IPv6 linking.

**Tech Stack:** Laravel 12, PHP 8.5, PHPUnit, SQLite (tests), Eloquent pivot models, `NetworkRangeService`

**Spec:** `docs/superpowers/specs/2026-04-23-network-device-tracking-design.md`

---

## File Structure

### New files
- `database/migrations/2026_04_23_300000_create_network_device_tracking_tables.php` — all schema changes
- `app/Models/IpAddressMacAddress.php` — pivot model
- `app/Models/DhcpLease.php` — persisted DHCP lease model (replaces the VO for DB storage)
- `app/Models/AuditLog.php` — polymorphic audit log model
- `database/factories/IpAddressMacAddressFactory.php`
- `database/factories/DhcpLeaseFactory.php`
- `database/factories/AuditLogFactory.php`
- `tests/Feature/NetworkDeviceTracking/MigrationTest.php`
- `tests/Feature/NetworkDeviceTracking/ModelRelationshipTest.php`
- `tests/Feature/NetworkDeviceTracking/AuditLogTest.php`
- `tests/Feature/NetworkDeviceTracking/ScanNetworkDevicesRefactorTest.php`
- `tests/Feature/NetworkDeviceTracking/UserLoginCascadeTest.php`
- `tests/Feature/NetworkDeviceTracking/OuiConfigurationTest.php`
- `tests/Feature/NetworkDeviceTracking/ConsumerUpdateTest.php`
- `tests/Unit/Services/NetworkSwitch/CiscoBulkCommandTest.php`

### Modified files
- `app/Models/IpAddress.php` — remove `macAddress()` BelongsTo, `mac()` accessor; add `macAddresses()` BelongsToMany, `dhcpLeases()`, `currentMac()`, `auditLogs()`
- `app/Models/MacAddress.php` — remove `allowed`/`allowed_at` from fillable/casts; add `ipAddresses()` BelongsToMany, `dhcpLeases()`, `switchPorts()`, `currentIp()`, `currentHostname()`, `auditLogs()`
- `app/Models/SwitchPortMac.php` — add `mac_address_id` to fillable, add `macAddressRecord()` BelongsTo
- `app/Models/User.php` — update `addIp()` for MAC ownership cascade
- `database/factories/MacAddressFactory.php` — remove `allowed`/`allowed_at` states
- `database/factories/IpAddressFactory.php` — remove `user_id` state if present
- `app/Jobs/ScanNetworkDevices.php` — full refactor
- `tests/Feature/Jobs/ScanNetworkDevicesTest.php` — full rewrite
- `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php` — update for new config keys
- `app/Http/Controllers/Admin/NetworkSettingsController.php` — add OUI prefixes field
- `resources/js/Pages/Admin/Settings/Network.vue` — add OUI textarea
- `app/Http/Controllers/Portal/DashboardController.php` — update `$ip->mac` to `$ip->currentMac()?->mac_address`
- `app/Http/Controllers/PortalController.php` — no MAC usage (already clean)
- `app/Http/Controllers/Admin/IpAddressController.php` — update show to include `currentMac`
- `resources/js/Pages/Admin/Ips/Show.vue` — update MAC display from `ip.mac` to `ip.current_mac`
- `app/Services/NetworkSwitch/CiscoSwitchAdapter.php` — bulk `show interface` / `show running-config | section ^interface`
- `app/Services/NetworkSwitch/IosOutputParser.php` — add `splitBulkShowInterface()` and `splitBulkRunningConfig()` methods
- `resources/views/portal.blade.php` — verify no MAC usage (already clean based on search)

---

## Task 1: Migration — Create new tables and modify existing

**Files:**
- Create: `database/migrations/2026_04_23_300000_create_network_device_tracking_tables.php`
- Test: `tests/Feature/NetworkDeviceTracking/MigrationTest.php`

- [ ] **Step 1: Create the migration file**

```bash
php artisan make:migration create_network_device_tracking_tables --no-interaction
```

Rename the generated file to `2026_04_23_300000_create_network_device_tracking_tables.php` if the timestamp differs.

- [ ] **Step 2: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create ip_address_mac_address pivot table
        Schema::create('ip_address_mac_address', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();
            $table->foreignId('mac_address_id')->constrained('mac_addresses')->cascadeOnDelete();
            $table->string('source');
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();
            $table->unique(['ip_address_id', 'mac_address_id']);
        });

        // 2. Create dhcp_leases table
        Schema::create('dhcp_leases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ip_address_id')->constrained('ip_addresses')->cascadeOnDelete();
            $table->foreignId('mac_address_id')->constrained('mac_addresses')->cascadeOnDelete();
            $table->string('hostname')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['ip_address_id', 'mac_address_id']);
        });

        // 3. Create audit_logs table
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('process');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');
            $table->index('created_at');
        });

        // 4. Add mac_address_id FK to switch_port_macs
        Schema::table('switch_port_macs', function (Blueprint $table) {
            $table->foreignId('mac_address_id')->nullable()->after('mac_address')->constrained('mac_addresses')->nullOnDelete();
        });

        // 5. Data migration: copy ip_addresses.mac_address_id into pivot
        $ipMacRows = DB::table('ip_addresses')
            ->whereNotNull('mac_address_id')
            ->select('id as ip_address_id', 'mac_address_id', 'last_seen_at')
            ->get();

        foreach ($ipMacRows->chunk(500) as $chunk) {
            $pivotRows = $chunk->map(fn ($row) => [
                'ip_address_id' => $row->ip_address_id,
                'mac_address_id' => $row->mac_address_id,
                'source' => 'auth',
                'last_seen_at' => $row->last_seen_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            DB::table('ip_address_mac_address')->insert($pivotRows);
        }

        // 6. Populate switch_port_macs.mac_address_id by matching mac_address string
        $macLookup = DB::table('mac_addresses')->pluck('id', 'mac_address');
        DB::table('switch_port_macs')->orderBy('id')->chunk(500, function ($rows) use ($macLookup) {
            foreach ($rows as $row) {
                $macId = $macLookup[$row->mac_address] ?? null;
                if ($macId !== null) {
                    DB::table('switch_port_macs')
                        ->where('id', $row->id)
                        ->update(['mac_address_id' => $macId]);
                }
            }
        });

        // 7. Drop old columns from ip_addresses
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->dropForeign(['mac_address_id']);
            $table->dropColumn('mac_address_id');
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        // 8. Drop old columns from mac_addresses
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->dropColumn(['allowed', 'allowed_at']);
        });
    }

    public function down(): void
    {
        // Restore ip_addresses columns
        Schema::table('ip_addresses', function (Blueprint $table) {
            $table->foreignId('mac_address_id')->nullable()->constrained('mac_addresses')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // Restore mac_addresses columns
        Schema::table('mac_addresses', function (Blueprint $table) {
            $table->boolean('allowed')->default(false);
            $table->timestamp('allowed_at')->nullable();
        });

        // Drop mac_address_id from switch_port_macs
        Schema::table('switch_port_macs', function (Blueprint $table) {
            $table->dropForeign(['mac_address_id']);
            $table->dropColumn('mac_address_id');
        });

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('dhcp_leases');
        Schema::dropIfExists('ip_address_mac_address');
    }
};
```

- [ ] **Step 3: Write the migration test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_address_mac_address_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('ip_address_mac_address'));
        $this->assertTrue(Schema::hasColumns('ip_address_mac_address', [
            'id', 'ip_address_id', 'mac_address_id', 'source', 'last_seen_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_dhcp_leases_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('dhcp_leases'));
        $this->assertTrue(Schema::hasColumns('dhcp_leases', [
            'id', 'ip_address_id', 'mac_address_id', 'hostname', 'expires_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_audit_logs_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'));
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'id', 'action', 'subject_type', 'subject_id', 'related_type', 'related_id',
            'actor_type', 'actor_id', 'process', 'metadata', 'created_at',
        ]));
    }

    public function test_switch_port_macs_has_mac_address_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('switch_port_macs', 'mac_address_id'));
    }

    public function test_ip_addresses_no_longer_has_mac_address_id(): void
    {
        $this->assertFalse(Schema::hasColumn('ip_addresses', 'mac_address_id'));
    }

    public function test_ip_addresses_no_longer_has_user_id(): void
    {
        $this->assertFalse(Schema::hasColumn('ip_addresses', 'user_id'));
    }

    public function test_mac_addresses_no_longer_has_allowed_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('mac_addresses', 'allowed'));
        $this->assertFalse(Schema::hasColumn('mac_addresses', 'allowed_at'));
    }
}
```

- [ ] **Step 4: Run the migration test**

```bash
php artisan test --compact --filter=MigrationTest
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_300000_create_network_device_tracking_tables.php tests/Feature/NetworkDeviceTracking/MigrationTest.php
git commit -m "feat: add network device tracking migration

Create ip_address_mac_address pivot, dhcp_leases, audit_logs tables.
Add mac_address_id FK to switch_port_macs. Migrate existing data.
Drop ip_addresses.mac_address_id, ip_addresses.user_id,
mac_addresses.allowed, mac_addresses.allowed_at."
```

---

## Task 2: New Models — IpAddressMacAddress, DhcpLease, AuditLog

**Files:**
- Create: `app/Models/IpAddressMacAddress.php`
- Create: `app/Models/DhcpLease.php`
- Create: `app/Models/AuditLog.php`
- Create: `database/factories/IpAddressMacAddressFactory.php`
- Create: `database/factories/DhcpLeaseFactory.php`
- Create: `database/factories/AuditLogFactory.php`
- Test: `tests/Feature/NetworkDeviceTracking/AuditLogTest.php`

- [ ] **Step 1: Write the AuditLog test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_creates_audit_log(): void
    {
        $ip = IpAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip.created',
            subject: $ip,
            process: 'scan_network',
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'ip.created',
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ]);
    }

    public function test_record_with_related_entity(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip_mac.linked',
            subject: $ip,
            related: $mac,
            process: 'scan_network',
        );

        $this->assertEquals($mac->getMorphClass(), $log->related_type);
        $this->assertEquals($mac->id, $log->related_id);
    }

    public function test_record_with_actor(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();

        $log = AuditLog::record(
            action: 'ip.internet_enabled',
            subject: $ip,
            actor: $user,
            process: 'admin',
        );

        $this->assertEquals($user->getMorphClass(), $log->actor_type);
        $this->assertEquals($user->id, $log->actor_id);
    }

    public function test_record_with_metadata(): void
    {
        $ip = IpAddress::factory()->create();

        $log = AuditLog::record(
            action: 'ip.created',
            subject: $ip,
            process: 'scan_network',
            metadata: ['source' => 'dhcp'],
        );

        $this->assertEquals(['source' => 'dhcp'], $log->metadata);
    }

    public function test_subject_morph_resolves(): void
    {
        $ip = IpAddress::factory()->create();
        $log = AuditLog::record(action: 'ip.created', subject: $ip, process: 'test');

        $fresh = AuditLog::find($log->id);
        $this->assertTrue($fresh->subject->is($ip));
    }

    public function test_related_morph_resolves(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $log = AuditLog::record(action: 'ip_mac.linked', subject: $ip, related: $mac, process: 'test');

        $fresh = AuditLog::find($log->id);
        $this->assertTrue($fresh->related->is($mac));
    }

    public function test_actor_morph_resolves(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();

        $log = AuditLog::record(action: 'ip.created', subject: $ip, actor: $user, process: 'test');

        $fresh = AuditLog::find($log->id);
        $this->assertTrue($fresh->actor->is($user));
    }

    public function test_audit_log_has_no_updated_at(): void
    {
        $ip = IpAddress::factory()->create();
        $log = AuditLog::record(action: 'ip.created', subject: $ip, process: 'test');

        $this->assertNull($log->updated_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=AuditLogTest
```

Expected: FAIL — class `AuditLog` not found.

- [ ] **Step 3: Create the IpAddressMacAddress pivot model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IpAddressMacAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $ip_address_id
 * @property int $mac_address_id
 * @property string $source
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class IpAddressMacAddress extends Pivot
{
    /** @use HasFactory<IpAddressMacAddressFactory> */
    use HasFactory;

    public $incrementing = true;

    protected $table = 'ip_address_mac_address';

    /** @var list<string> */
    protected $fillable = [
        'ip_address_id',
        'mac_address_id',
        'source',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IpAddress, $this> */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }

    /** @return BelongsTo<MacAddress, $this> */
    public function macAddress(): BelongsTo
    {
        return $this->belongsTo(MacAddress::class);
    }
}
```

- [ ] **Step 4: Create the DhcpLease model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DhcpLeaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $ip_address_id
 * @property int $mac_address_id
 * @property string|null $hostname
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DhcpLease extends Model
{
    /** @use HasFactory<DhcpLeaseFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'ip_address_id',
        'mac_address_id',
        'hostname',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IpAddress, $this> */
    public function ipAddress(): BelongsTo
    {
        return $this->belongsTo(IpAddress::class);
    }

    /** @return BelongsTo<MacAddress, $this> */
    public function macAddress(): BelongsTo
    {
        return $this->belongsTo(MacAddress::class);
    }
}
```

- [ ] **Step 5: Create the AuditLog model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $action
 * @property string $subject_type
 * @property int $subject_id
 * @property string|null $related_type
 * @property int|null $related_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $process
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'action',
        'subject_type',
        'subject_id',
        'related_type',
        'related_id',
        'actor_type',
        'actor_id',
        'process',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function record(
        string $action,
        Model $subject,
        ?Model $related = null,
        ?Model $actor = null,
        string $process = 'system',
        ?array $metadata = null,
    ): self {
        return self::create([
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'process' => $process,
            'metadata' => $metadata,
        ]);
    }
}
```

- [ ] **Step 6: Create factories**

`database/factories/IpAddressMacAddressFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\IpAddress;
use App\Models\IpAddressMacAddress;
use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IpAddressMacAddress> */
class IpAddressMacAddressFactory extends Factory
{
    protected $model = IpAddressMacAddress::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory(),
            'mac_address_id' => MacAddress::factory(),
            'source' => 'dhcp',
            'last_seen_at' => now(),
        ];
    }
}
```

`database/factories/DhcpLeaseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DhcpLease> */
class DhcpLeaseFactory extends Factory
{
    protected $model = DhcpLease::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ip_address_id' => IpAddress::factory(),
            'mac_address_id' => MacAddress::factory(),
            'hostname' => fake()->domainWord(),
            'expires_at' => now()->addHours(24),
        ];
    }
}
```

`database/factories/AuditLogFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\IpAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $ip = IpAddress::factory()->create();

        return [
            'action' => 'ip.created',
            'subject_type' => $ip->getMorphClass(),
            'subject_id' => $ip->id,
            'process' => 'scan_network',
        ];
    }
}
```

- [ ] **Step 7: Run the AuditLog test**

```bash
php artisan test --compact --filter=AuditLogTest
```

Expected: All tests pass.

- [ ] **Step 8: Commit**

```bash
git add app/Models/IpAddressMacAddress.php app/Models/DhcpLease.php app/Models/AuditLog.php database/factories/IpAddressMacAddressFactory.php database/factories/DhcpLeaseFactory.php database/factories/AuditLogFactory.php tests/Feature/NetworkDeviceTracking/AuditLogTest.php
git commit -m "feat: add IpAddressMacAddress pivot, DhcpLease, and AuditLog models

New models with factories and tests. AuditLog::record() provides
a static helper for polymorphic audit entries."
```

---

## Task 3: Update Existing Models — IpAddress, MacAddress, SwitchPortMac relationships

**Files:**
- Modify: `app/Models/IpAddress.php`
- Modify: `app/Models/MacAddress.php`
- Modify: `app/Models/SwitchPortMac.php`
- Modify: `database/factories/MacAddressFactory.php`
- Modify: `database/factories/IpAddressFactory.php`
- Test: `tests/Feature/NetworkDeviceTracking/ModelRelationshipTest.php`

- [ ] **Step 1: Write the model relationship test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\IpAddressMacAddress;
use App\Models\MacAddress;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_ip_has_many_mac_addresses_via_pivot(): void
    {
        $ip = IpAddress::factory()->create();
        $mac1 = MacAddress::factory()->create();
        $mac2 = MacAddress::factory()->create();

        $ip->macAddresses()->attach($mac1, ['source' => 'dhcp', 'last_seen_at' => now()]);
        $ip->macAddresses()->attach($mac2, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->assertCount(2, $ip->fresh()->macAddresses);
    }

    public function test_mac_has_many_ip_addresses_via_pivot(): void
    {
        $mac = MacAddress::factory()->create();
        $ip1 = IpAddress::factory()->create();
        $ip2 = IpAddress::factory()->create();

        $mac->ipAddresses()->attach($ip1, ['source' => 'dhcp', 'last_seen_at' => now()]);
        $mac->ipAddresses()->attach($ip2, ['source' => 'arp', 'last_seen_at' => now()]);

        $this->assertCount(2, $mac->fresh()->ipAddresses);
    }

    public function test_pivot_includes_source_and_last_seen_at(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()->subMinutes(5)]);

        $pivot = $ip->macAddresses()->first()->pivot;
        $this->assertEquals('dhcp', $pivot->source);
        $this->assertNotNull($pivot->last_seen_at);
    }

    public function test_current_mac_returns_latest_by_last_seen_at(): void
    {
        $ip = IpAddress::factory()->create();
        $oldMac = MacAddress::factory()->create();
        $newMac = MacAddress::factory()->create();

        $ip->macAddresses()->attach($oldMac, ['source' => 'arp', 'last_seen_at' => now()->subHours(2)]);
        $ip->macAddresses()->attach($newMac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $current = $ip->currentMac();
        $this->assertTrue($current->is($newMac));
    }

    public function test_current_mac_returns_null_when_no_macs(): void
    {
        $ip = IpAddress::factory()->create();

        $this->assertNull($ip->currentMac());
    }

    public function test_current_ip_returns_latest_by_last_seen_at(): void
    {
        $mac = MacAddress::factory()->create();
        $oldIp = IpAddress::factory()->create();
        $newIp = IpAddress::factory()->create();

        $mac->ipAddresses()->attach($oldIp, ['source' => 'arp', 'last_seen_at' => now()->subHours(2)]);
        $mac->ipAddresses()->attach($newIp, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $current = $mac->currentIp();
        $this->assertTrue($current->is($newIp));
    }

    public function test_current_ip_returns_null_when_no_ips(): void
    {
        $mac = MacAddress::factory()->create();

        $this->assertNull($mac->currentIp());
    }

    public function test_current_hostname_from_latest_dhcp_lease(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $ip->id,
            'hostname' => 'old-hostname',
            'created_at' => now()->subHour(),
        ]);
        DhcpLease::factory()->create([
            'mac_address_id' => $mac->id,
            'ip_address_id' => $ip->id,
            'hostname' => 'new-hostname',
            'created_at' => now(),
        ]);

        $this->assertEquals('new-hostname', $mac->currentHostname());
    }

    public function test_current_hostname_returns_null_when_no_leases(): void
    {
        $mac = MacAddress::factory()->create();

        $this->assertNull($mac->currentHostname());
    }

    public function test_ip_has_many_dhcp_leases(): void
    {
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();

        DhcpLease::factory()->create(['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id]);

        $this->assertCount(1, $ip->fresh()->dhcpLeases);
    }

    public function test_mac_has_many_dhcp_leases(): void
    {
        $mac = MacAddress::factory()->create();
        $ip = IpAddress::factory()->create();

        DhcpLease::factory()->create(['mac_address_id' => $mac->id, 'ip_address_id' => $ip->id]);

        $this->assertCount(1, $mac->fresh()->dhcpLeases);
    }

    public function test_switch_port_mac_belongs_to_mac_address_record(): void
    {
        $mac = MacAddress::factory()->create();
        $switchPort = SwitchPort::factory()->create();
        $spm = SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        $this->assertTrue($spm->macAddressRecord->is($mac));
    }

    public function test_ip_audit_logs_morph_many(): void
    {
        $ip = IpAddress::factory()->create();
        \App\Models\AuditLog::record(action: 'ip.created', subject: $ip, process: 'test');

        $this->assertCount(1, $ip->fresh()->auditLogs);
    }

    public function test_mac_audit_logs_morph_many(): void
    {
        $mac = MacAddress::factory()->create();
        \App\Models\AuditLog::record(action: 'mac.created', subject: $mac, process: 'test');

        $this->assertCount(1, $mac->fresh()->auditLogs);
    }

    public function test_mac_switch_ports_via_switch_port_macs(): void
    {
        $mac = MacAddress::factory()->create();
        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => $mac->mac_address,
            'mac_address_id' => $mac->id,
        ]);

        $this->assertCount(1, $mac->fresh()->switchPorts);
        $this->assertTrue($mac->switchPorts->first()->is($switchPort));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ModelRelationshipTest
```

Expected: FAIL — `macAddresses()` method not found on IpAddress.

- [ ] **Step 3: Update IpAddress model**

In `app/Models/IpAddress.php`:

Remove the `macAddress()` BelongsTo relationship and the `mac()` Attribute accessor. Add the new relationships:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

// Remove these methods:
// protected function mac(): Attribute { ... }
// public function macAddress(): BelongsTo { ... }

// Add these methods:

/** @return BelongsToMany<MacAddress, $this> */
public function macAddresses(): BelongsToMany
{
    return $this->belongsToMany(MacAddress::class, 'ip_address_mac_address')
        ->using(IpAddressMacAddress::class)
        ->withPivot('source', 'last_seen_at')
        ->withTimestamps();
}

/** @return HasMany<DhcpLease, $this> */
public function dhcpLeases(): HasMany
{
    return $this->hasMany(DhcpLease::class);
}

/** @return MorphMany<AuditLog, $this> */
public function auditLogs(): MorphMany
{
    return $this->morphMany(AuditLog::class, 'subject');
}

public function currentMac(): ?MacAddress
{
    return $this->macAddresses()
        ->orderByPivot('last_seen_at', 'desc')
        ->first();
}
```

Also remove `mac_address_id` and `user_id` references from the docblock, and remove the `@property-read string|null $mac` and `@property-read MacAddress|null $macAddress` annotations. Add:
- `@property-read \Illuminate\Database\Eloquent\Collection<int, MacAddress> $macAddresses`
- `@property-read \Illuminate\Database\Eloquent\Collection<int, DhcpLease> $dhcpLeases`
- `@property-read \Illuminate\Database\Eloquent\Collection<int, AuditLog> $auditLogs`

- [ ] **Step 4: Update MacAddress model**

In `app/Models/MacAddress.php`:

Remove `'allowed'`, `'allowed_at'` from `$fillable`. Remove `'allowed' => 'boolean'` and `'allowed_at' => 'datetime'` from `casts()`. Change `ipAddresses()` from `HasMany` to `BelongsToMany`. Add new relationships:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** @var list<string> */
protected $fillable = [
    'mac_address',
    'user_id',
    'source',
    'description',
];

protected function casts(): array
{
    return [
        'mac_address' => NormalizeMacAddress::class,
    ];
}

/** @return BelongsToMany<IpAddress, $this> */
public function ipAddresses(): BelongsToMany
{
    return $this->belongsToMany(IpAddress::class, 'ip_address_mac_address')
        ->using(IpAddressMacAddress::class)
        ->withPivot('source', 'last_seen_at')
        ->withTimestamps();
}

/** @return HasMany<DhcpLease, $this> */
public function dhcpLeases(): HasMany
{
    return $this->hasMany(DhcpLease::class);
}

/** @return BelongsToMany<SwitchPort, $this> */
public function switchPorts(): BelongsToMany
{
    return $this->belongsToMany(SwitchPort::class, 'switch_port_macs')
        ->withPivot('vlan', 'last_seen_at', 'mac_address');
}

/** @return MorphMany<AuditLog, $this> */
public function auditLogs(): MorphMany
{
    return $this->morphMany(AuditLog::class, 'subject');
}

public function currentIp(): ?IpAddress
{
    return $this->ipAddresses()
        ->orderByPivot('last_seen_at', 'desc')
        ->first();
}

public function currentHostname(): ?string
{
    $lease = $this->dhcpLeases()
        ->whereNotNull('hostname')
        ->latest()
        ->first();

    return $lease?->hostname;
}
```

Update the docblock to remove `@property bool $allowed`, `@property Carbon|null $allowed_at`, `@method static ... whereAllowed(...)`, `@method static ... whereAllowedAt(...)`. Update `@property-read Collection<int, IpAddress> $ipAddresses` (relationship type changed but annotation stays).

- [ ] **Step 5: Update SwitchPortMac model**

In `app/Models/SwitchPortMac.php`, add `'mac_address_id'` to `$fillable` and add the relationship:

```php
/** @var list<string> */
protected $fillable = [
    'switch_port_id',
    'mac_address',
    'mac_address_id',
    'vlan',
    'last_seen_at',
];

/** @return BelongsTo<MacAddress, $this> */
public function macAddressRecord(): BelongsTo
{
    return $this->belongsTo(MacAddress::class, 'mac_address_id');
}
```

- [ ] **Step 6: Update MacAddressFactory**

In `database/factories/MacAddressFactory.php`, remove `'allowed' => false` from `definition()`, remove the `allowed()` state, update the `xbox()` state to remove `'allowed'` and `'allowed_at'`:

```php
public function definition(): array
{
    return [
        'mac_address' => fake()->macAddress(),
        'source' => 'auth',
    ];
}

public function xbox(): static
{
    return $this->state(fn (array $attributes) => [
        'source' => 'xbox',
        'description' => 'Xbox Console',
    ]);
}
```

- [ ] **Step 7: Update IpAddressFactory**

In `database/factories/IpAddressFactory.php`, remove the `withUser()` state (it sets `user_id` which no longer exists on the table):

Remove:
```php
public function withUser(User $user): static
{
    return $this->state(fn (array $attributes) => [
        'user_id' => $user->id,
    ]);
}
```

- [ ] **Step 8: Run the model relationship test**

```bash
php artisan test --compact --filter=ModelRelationshipTest
```

Expected: All tests pass.

- [ ] **Step 9: Commit**

```bash
git add app/Models/IpAddress.php app/Models/MacAddress.php app/Models/SwitchPortMac.php database/factories/MacAddressFactory.php database/factories/IpAddressFactory.php tests/Feature/NetworkDeviceTracking/ModelRelationshipTest.php
git commit -m "feat: update IpAddress, MacAddress, SwitchPortMac relationships

IpAddress: remove macAddress() BelongsTo and mac() accessor, add
macAddresses() BelongsToMany, dhcpLeases(), auditLogs(), currentMac().
MacAddress: remove allowed/allowed_at, add ipAddresses() BelongsToMany,
dhcpLeases(), switchPorts(), auditLogs(), currentIp(), currentHostname().
SwitchPortMac: add macAddressRecord() BelongsTo."
```

---

## Task 4: Fix All Existing Tests Broken by Model Changes

**Files:**
- Modify: Various test files referencing `allowed`, `mac_address_id`, `user_id` on IpAddress, `$ip->mac`, `$ip->macAddress`
- Modify: `tests/Feature/Jobs/ScanNetworkDevicesTest.php`
- Modify: `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php`

- [ ] **Step 1: Run the full test suite to identify all failures**

```bash
php artisan test --compact 2>&1 | tail -40
```

- [ ] **Step 2: Fix each failing test**

The failures will fall into these categories:

1. **Tests referencing `$ip->mac` or `$ip->macAddress`** — change to `$ip->currentMac()` or `$ip->macAddresses`
2. **Tests referencing `mac_addresses.allowed` or `mac_addresses.allowed_at`** — remove assertions on these columns
3. **Tests referencing `ip_addresses.mac_address_id`** — change to use pivot table
4. **Tests referencing `ip_addresses.user_id`** — remove or change to use `user_ip_addresses`
5. **Tests using `MacAddress::factory()->allowed()`** — remove `allowed()` state usage
6. **Tests using `IpAddress::factory()->withUser()`** — remove `withUser()` state usage
7. **ScanNetworkDevicesTest** — will need complete rewrite (handled in Task 6)
8. **ScanNetworkDevicesConfigTest** — will need updates for new config keys

For each failing test file, read the file, understand the failure, and make the minimal change to fix it. Do NOT rewrite ScanNetworkDevicesTest yet — just skip/disable it if it fails entirely (it will be rewritten in Task 6).

- [ ] **Step 3: Run the test suite again to confirm all fixes**

```bash
php artisan test --compact
```

Expected: All tests pass (except ScanNetworkDevices tests which may be temporarily skipped).

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "fix: update existing tests for new model relationships

Fix references to removed columns (allowed, mac_address_id, user_id)
and removed accessors (mac). Update factory usage."
```

---

## Task 5: User Login Cascade — MAC Ownership + IP Association

**Files:**
- Modify: `app/Models/User.php:166-193` (the `addIp` method)
- Test: `tests/Feature/NetworkDeviceTracking/UserLoginCascadeTest.php`

- [ ] **Step 1: Write the cascade test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\IpPolicyService;
use App\Services\NetworkRangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserLoginCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_login_assigns_mac_ownership_when_unowned(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertEquals($user->id, $mac->fresh()->user_id);
    }

    public function test_login_does_not_steal_mac_from_another_user(): void
    {
        $otherUser = User::factory()->create();
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['user_id' => $otherUser->id]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertEquals($otherUser->id, $mac->fresh()->user_id);
    }

    public function test_login_cascades_ip_via_shared_mac(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ipv4 = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ipv6 = IpAddress::factory()->create(['address' => '::1']);

        $ipv4->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $ipv6->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertTrue(
            UserIpAddress::where('user_id', $user->id)
                ->where('ip_address_id', $ipv6->id)
                ->exists()
        );
    }

    public function test_login_does_not_cascade_ip_owned_by_different_user(): void
    {
        $otherUser = User::factory()->create();
        $user = User::factory()->create(['internet_blocked' => false]);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ipv4 = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ipv6 = IpAddress::factory()->create(['address' => '::1']);

        $ipv4->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $ipv6->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        // Other user already owns the IPv6
        UserIpAddress::create([
            'user_id' => $otherUser->id,
            'ip_address_id' => $ipv6->id,
            'last_seen_at' => now(),
        ]);

        $user->addIp('127.0.0.1');

        // IPv6 should NOT be associated with this user
        $this->assertFalse(
            UserIpAddress::where('user_id', $user->id)
                ->where('ip_address_id', $ipv6->id)
                ->exists()
        );
    }

    public function test_login_cascade_is_depth_limited(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $mac1 = MacAddress::factory()->create(['user_id' => null]);
        $mac2 = MacAddress::factory()->create(['user_id' => null]);
        $ip1 = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ip2 = IpAddress::factory()->create(['address' => '127.0.0.2']);
        $ip3 = IpAddress::factory()->create(['address' => '127.0.0.3']);

        // ip1 -> mac1 -> ip2 -> mac2 -> ip3 (two hops)
        $ip1->macAddresses()->attach($mac1, ['source' => 'arp', 'last_seen_at' => now()]);
        $ip2->macAddresses()->attach($mac1, ['source' => 'arp', 'last_seen_at' => now()]);
        $ip2->macAddresses()->attach($mac2, ['source' => 'arp', 'last_seen_at' => now()]);
        $ip3->macAddresses()->attach($mac2, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        // ip2 should be cascaded (one hop)
        $this->assertTrue(
            UserIpAddress::where('user_id', $user->id)->where('ip_address_id', $ip2->id)->exists()
        );
        // ip3 should NOT be cascaded (two hops)
        $this->assertFalse(
            UserIpAddress::where('user_id', $user->id)->where('ip_address_id', $ip3->id)->exists()
        );
    }

    public function test_login_cascade_creates_audit_logs(): void
    {
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mac.user_assigned',
            'subject_type' => (new MacAddress)->getMorphClass(),
            'subject_id' => $mac->id,
            'process' => 'portal_login',
        ]);
    }

    public function test_login_cascade_respects_managed_ranges(): void
    {
        // Set managed range to 127.0.0.0/8 only
        \App\Models\Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['127.0.0.0/8']));

        $user = User::factory()->create(['internet_blocked' => false]);
        $mac = MacAddress::factory()->create(['user_id' => null]);
        $ipManaged = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $ipUnmanaged = IpAddress::factory()->create(['address' => '192.168.1.1']);

        $ipManaged->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);
        $ipUnmanaged->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $user->addIp('127.0.0.1');

        // The unmanaged IP should not be cascaded because addIp checks managed ranges
        $this->assertFalse(
            UserIpAddress::where('user_id', $user->id)->where('ip_address_id', $ipUnmanaged->id)->exists()
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=UserLoginCascadeTest
```

Expected: FAIL — cascade logic doesn't exist yet.

- [ ] **Step 3: Update `User::addIp()` with cascade logic**

In `app/Models/User.php`, update the `addIp` method:

```php
public function addIp(string $clientIp, bool $cascade = true): ?IpAddress
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

    if ($cascade) {
        $this->cascadeMacOwnership($ip);
    }

    return $ip;
}

/**
 * Assign MAC ownership and cascade IP associations via shared MACs.
 * Depth-limited to one hop (IP -> MAC -> sibling IPs).
 */
private function cascadeMacOwnership(IpAddress $ip): void
{
    $macs = $ip->macAddresses()->get();

    foreach ($macs as $mac) {
        // Assign MAC ownership if unowned
        if ($mac->user_id === null) {
            $mac->user_id = $this->id;
            $mac->save();

            AuditLog::record(
                action: 'mac.user_assigned',
                subject: $mac,
                related: $ip,
                actor: $this,
                process: 'portal_login',
                metadata: ['user_id' => $this->id],
            );
        }

        // Only cascade sibling IPs for MACs we own
        if ((int) $mac->user_id !== (int) $this->id) {
            continue;
        }

        // Find sibling IPs on this MAC (one hop)
        $siblingIps = $mac->ipAddresses()->where('ip_addresses.id', '!=', $ip->id)->get();

        foreach ($siblingIps as $siblingIp) {
            // Skip if another user already owns this IP
            $existingOwner = UserIpAddress::where('ip_address_id', $siblingIp->id)->first();
            if ($existingOwner !== null && (int) $existingOwner->user_id !== (int) $this->id) {
                continue;
            }

            // Use addIp with cascade=false to prevent recursion
            $cascaded = $this->addIp($siblingIp->address, cascade: false);

            if ($cascaded !== null) {
                AuditLog::record(
                    action: 'ip.user_cascaded',
                    subject: $siblingIp,
                    related: $mac,
                    actor: $this,
                    process: 'portal_login',
                    metadata: ['source_ip' => $ip->address],
                );
            }
        }
    }
}
```

Add the import at the top: `use App\Models\AuditLog;`

- [ ] **Step 4: Run the cascade test**

```bash
php artisan test --compact --filter=UserLoginCascadeTest
```

Expected: All tests pass.

- [ ] **Step 5: Run existing User tests to ensure no regressions**

```bash
php artisan test --compact --filter=UserTest
php artisan test --compact --filter=UserAddIpManagedRangeTest
php artisan test --compact --filter=PortalControllerTest
```

Expected: All pass.

- [ ] **Step 6: Commit**

```bash
git add app/Models/User.php tests/Feature/NetworkDeviceTracking/UserLoginCascadeTest.php
git commit -m "feat: add MAC ownership cascade on user login

When a user logs in, unowned MACs on their IP get assigned to them.
Sibling IPs sharing those MACs are associated with the user (one hop).
Respects managed ranges and doesn't steal from other users."
```

---

## Task 6: ScanNetworkDevices Refactor — Greedy Discovery + OUI Policy

**Files:**
- Modify: `app/Jobs/ScanNetworkDevices.php` — full rewrite
- Rewrite: `tests/Feature/Jobs/ScanNetworkDevicesTest.php`
- Modify: `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php`
- Test: `tests/Feature/NetworkDeviceTracking/ScanNetworkDevicesRefactorTest.php`

- [ ] **Step 1: Write the refactored scan test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Jobs\ScanNetworkDevices;
use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\SwitchPort;
use App\Models\SwitchPortMac;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\ValueObjects\ArpEntry;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class ScanNetworkDevicesRefactorTest extends TestCase
{
    use RefreshDatabase;

    private function mockDhcp(array $leases = []): void
    {
        $mock = Mockery::mock(DhcpInterface::class);
        $mock->shouldReceive('getLeases')->andReturn(collect($leases));
        $this->app->instance(DhcpInterface::class, $mock);
    }

    private function mockInventory(array $arp = [], array $fdb = []): void
    {
        $mock = Mockery::mock(NetworkInventoryInterface::class);
        $mock->shouldReceive('getArpTable')->andReturn(collect($arp));
        $mock->shouldReceive('getForwardingDatabase')->andReturn(collect($fdb));
        $this->app->instance(NetworkInventoryInterface::class, $mock);
    }

    public function test_discovery_persists_all_dhcp_macs(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    }

    public function test_discovery_persists_all_arp_macs(): void
    {
        $this->mockDhcp();
        $this->mockInventory([new ArpEntry('127.0.0.1', 'AA:BB:CC:DD:EE:02')]);

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:02']);
    }

    public function test_discovery_persists_in_range_ips(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('ip_addresses', ['address' => '127.0.0.1']);
    }

    public function test_discovery_skips_out_of_range_ips(): void
    {
        Setting::set('network.managed_ranges_v4', 'Managed IPv4 Ranges', json_encode(['10.0.0.0/8']));

        $this->mockDhcp([new DhcpLeaseVO('192.168.1.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseMissing('ip_addresses', ['address' => '192.168.1.1']);
        // MAC should still be stored regardless
        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    }

    public function test_discovery_creates_ip_mac_pivot_with_correct_source(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host1', '2026-05-01')]);
        $this->mockInventory([new ArpEntry('127.0.0.2', 'AA:BB:CC:DD:EE:02')]);

        (new ScanNetworkDevices)->handle();

        $ip1 = IpAddress::where('address', '127.0.0.1')->first();
        $mac1 = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:01')->first();
        $this->assertDatabaseHas('ip_address_mac_address', [
            'ip_address_id' => $ip1->id,
            'mac_address_id' => $mac1->id,
            'source' => 'dhcp',
        ]);

        $ip2 = IpAddress::where('address', '127.0.0.2')->first();
        $mac2 = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:02')->first();
        $this->assertDatabaseHas('ip_address_mac_address', [
            'ip_address_id' => $ip2->id,
            'mac_address_id' => $mac2->id,
            'source' => 'arp',
        ]);
    }

    public function test_discovery_persists_dhcp_leases_with_hostname(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'my-laptop', '2026-05-01 12:00:00')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $mac = MacAddress::where('mac_address', 'AA:BB:CC:DD:EE:01')->first();

        $this->assertDatabaseHas('dhcp_leases', [
            'ip_address_id' => $ip->id,
            'mac_address_id' => $mac->id,
            'hostname' => 'my-laptop',
        ]);
    }

    public function test_discovery_links_switch_port_mac_fk(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:03']);
        $switchPort = SwitchPort::factory()->create();
        SwitchPortMac::factory()->create([
            'switch_port_id' => $switchPort->id,
            'mac_address' => 'AA:BB:CC:DD:EE:03',
        ]);

        $this->mockDhcp();
        $this->mockInventory([], [new ForwardingEntry('aabb.ccdd.ee03', $switchPort->port_name, 100)]);

        (new ScanNetworkDevices)->handle();

        $spm = SwitchPortMac::where('mac_address', 'AA:BB:CC:DD:EE:03')->first();
        $this->assertEquals($mac->id, $spm->mac_address_id);
    }

    public function test_discovery_touches_last_seen_at_on_existing_records(): void
    {
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1', 'last_seen_at' => now()->subDays(7)]);

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertTrue($ip->fresh()->last_seen_at->isToday());
    }

    public function test_oui_policy_enables_internet_for_matching_mac(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'xbox', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertTrue($ip->internet_enabled);
    }

    public function test_oui_policy_does_not_enable_for_non_matching_mac(): void
    {
        Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode(['AA:BB:CC']));

        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', '11:22:33:44:55:66', 'laptop', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertFalse($ip->internet_enabled);
    }

    public function test_oui_policy_skipped_when_no_prefixes_configured(): void
    {
        // No OUI setting configured
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $ip = IpAddress::where('address', '127.0.0.1')->first();
        $this->assertFalse($ip->internet_enabled);
    }

    public function test_discovery_creates_audit_logs(): void
    {
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('audit_logs', ['action' => 'ip.created', 'process' => 'scan_network']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'mac.created', 'process' => 'scan_network']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ip_mac.linked', 'process' => 'scan_network']);
    }

    public function test_discovery_runs_without_enabled_toggle(): void
    {
        // Discovery should run unconditionally now (no auto_allow enabled check)
        $this->mockDhcp([new DhcpLeaseVO('127.0.0.1', 'AA:BB:CC:DD:EE:01', 'host', '2026-05-01')]);
        $this->mockInventory();

        (new ScanNetworkDevices)->handle();

        $this->assertDatabaseHas('mac_addresses', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ScanNetworkDevicesRefactorTest
```

Expected: FAIL — old job logic doesn't match.

- [ ] **Step 3: Rewrite `ScanNetworkDevices` job**

Replace the entire contents of `app/Jobs/ScanNetworkDevices.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\DhcpLease;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\Setting;
use App\Models\SwitchPortMac;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\NetworkInventoryInterface;
use App\Services\NetworkRangeService;
use App\Services\ValueObjects\DhcpLease as DhcpLeaseVO;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class ScanNetworkDevices implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        $dhcp = app(DhcpInterface::class);
        $inventory = app(NetworkInventoryInterface::class);
        $rangeService = app(NetworkRangeService::class);

        $leases = $dhcp->getLeases();
        $arpEntries = $inventory->getArpTable();
        $forwardingEntries = $inventory->getForwardingDatabase();

        // Phase 1: Discovery
        $this->persistMacs($leases, $arpEntries, $forwardingEntries);
        $this->persistIps($leases, $arpEntries, $rangeService);
        $this->linkIpMac($leases, $arpEntries, $rangeService);
        $this->persistDhcpLeases($leases, $rangeService);
        $this->linkSwitchPortMacs($forwardingEntries);

        // Phase 2: OUI Policy
        $this->applyOuiPolicy();
    }

    /**
     * @param  Collection<int, DhcpLeaseVO>  $leases
     * @param  Collection<int, \App\Services\ValueObjects\ArpEntry>  $arpEntries
     * @param  Collection<int, \App\Services\ValueObjects\ForwardingEntry>  $forwardingEntries
     */
    private function persistMacs(Collection $leases, Collection $arpEntries, Collection $forwardingEntries): void
    {
        $allMacs = collect();

        foreach ($leases as $lease) {
            $allMacs->put($this->normalizeMac($lease->mac), 'dhcp');
        }
        foreach ($arpEntries as $arp) {
            $allMacs->putIfAbsent($this->normalizeMac($arp->mac), 'arp');
        }
        foreach ($forwardingEntries as $fwd) {
            $allMacs->putIfAbsent($this->normalizeMac($fwd->mac), 'switch');
        }

        $existingMacs = MacAddress::whereIn('mac_address', $allMacs->keys())->pluck('id', 'mac_address');

        foreach ($allMacs as $mac => $source) {
            if (! $existingMacs->has($mac)) {
                $record = MacAddress::create([
                    'mac_address' => $mac,
                    'source' => $source,
                ]);
                $existingMacs->put($mac, $record->id);

                AuditLog::record(
                    action: 'mac.created',
                    subject: $record,
                    process: 'scan_network',
                    metadata: ['source' => $source],
                );
            }
        }
    }

    /**
     * @param  Collection<int, DhcpLeaseVO>  $leases
     * @param  Collection<int, \App\Services\ValueObjects\ArpEntry>  $arpEntries
     */
    private function persistIps(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
    {
        $allIps = collect();

        foreach ($leases as $lease) {
            $allIps->putIfAbsent($lease->ip, 'dhcp');
        }
        foreach ($arpEntries as $arp) {
            $allIps->putIfAbsent($arp->ip, 'arp');
        }

        $existingIps = IpAddress::whereIn('address', $allIps->keys())->get()->keyBy('address');

        foreach ($allIps as $ipAddress => $source) {
            if (! $rangeService->isManaged($ipAddress)) {
                continue;
            }

            $existing = $existingIps->get($ipAddress);
            if ($existing !== null) {
                $existing->last_seen_at = now();
                $existing->save();
            } else {
                $ip = IpAddress::create([
                    'address' => $ipAddress,
                    'last_seen_at' => now(),
                ]);

                AuditLog::record(
                    action: 'ip.created',
                    subject: $ip,
                    process: 'scan_network',
                    metadata: ['source' => $source],
                );
            }
        }
    }

    /**
     * @param  Collection<int, DhcpLeaseVO>  $leases
     * @param  Collection<int, \App\Services\ValueObjects\ArpEntry>  $arpEntries
     */
    private function linkIpMac(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
    {
        /** @var Collection<int, array{ip: string, mac: string, source: string}> $pairs */
        $pairs = collect();

        foreach ($leases as $lease) {
            $pairs->push(['ip' => $lease->ip, 'mac' => $this->normalizeMac($lease->mac), 'source' => 'dhcp']);
        }
        foreach ($arpEntries as $arp) {
            $pairs->push(['ip' => $arp->ip, 'mac' => $this->normalizeMac($arp->mac), 'source' => 'arp']);
        }

        $ips = IpAddress::whereIn('address', $pairs->pluck('ip')->unique())->get()->keyBy('address');
        $macs = MacAddress::whereIn('mac_address', $pairs->pluck('mac')->unique())->get()->keyBy('mac_address');

        foreach ($pairs as $pair) {
            if (! $rangeService->isManaged($pair['ip'])) {
                continue;
            }

            $ip = $ips->get($pair['ip']);
            $mac = $macs->get($pair['mac']);

            if ($ip === null || $mac === null) {
                continue;
            }

            $existing = $ip->macAddresses()->where('mac_addresses.id', $mac->id)->first();
            if ($existing !== null) {
                $ip->macAddresses()->updateExistingPivot($mac->id, [
                    'last_seen_at' => now(),
                ]);
            } else {
                $ip->macAddresses()->attach($mac, [
                    'source' => $pair['source'],
                    'last_seen_at' => now(),
                ]);

                AuditLog::record(
                    action: 'ip_mac.linked',
                    subject: $ip,
                    related: $mac,
                    process: 'scan_network',
                    metadata: ['source' => $pair['source']],
                );
            }
        }
    }

    /**
     * @param  Collection<int, DhcpLeaseVO>  $leases
     */
    private function persistDhcpLeases(Collection $leases, NetworkRangeService $rangeService): void
    {
        $ips = IpAddress::whereIn('address', $leases->map->ip)->get()->keyBy('address');
        $macs = MacAddress::whereIn('mac_address', $leases->map(fn ($l) => $this->normalizeMac($l->mac)))->get()->keyBy('mac_address');

        foreach ($leases as $lease) {
            if (! $rangeService->isManaged($lease->ip)) {
                continue;
            }

            $ip = $ips->get($lease->ip);
            $mac = $macs->get($this->normalizeMac($lease->mac));

            if ($ip === null || $mac === null) {
                continue;
            }

            DhcpLease::updateOrCreate(
                ['ip_address_id' => $ip->id, 'mac_address_id' => $mac->id],
                [
                    'hostname' => $lease->hostname !== '' ? $lease->hostname : null,
                    'expires_at' => $lease->expires !== '' ? $lease->expires : null,
                ],
            );
        }
    }

    /**
     * @param  Collection<int, \App\Services\ValueObjects\ForwardingEntry>  $forwardingEntries
     */
    private function linkSwitchPortMacs(Collection $forwardingEntries): void
    {
        $normalizedFwdMacs = $forwardingEntries->mapWithKeys(fn ($fwd) => [$this->normalizeMac($fwd->mac) => $fwd]);
        $macRecords = MacAddress::whereIn('mac_address', $normalizedFwdMacs->keys())->get()->keyBy('mac_address');

        SwitchPortMac::whereIn('mac_address', $normalizedFwdMacs->keys())
            ->whereNull('mac_address_id')
            ->each(function (SwitchPortMac $spm) use ($macRecords) {
                $macRecord = $macRecords->get($spm->mac_address);
                if ($macRecord !== null) {
                    $spm->mac_address_id = $macRecord->id;
                    $spm->save();
                }
            });
    }

    private function applyOuiPolicy(): void
    {
        $raw = Setting::get('network.oui_auto_allow');
        if ($raw === null) {
            return;
        }

        $prefixes = json_decode((string) $raw, true);
        if (! is_array($prefixes) || $prefixes === []) {
            return;
        }

        // Normalize prefixes to uppercase
        $prefixes = array_map('strtoupper', $prefixes);

        $allMacs = MacAddress::all();

        foreach ($allMacs as $mac) {
            $normalized = strtoupper($mac->mac_address);
            $matched = false;

            foreach ($prefixes as $prefix) {
                if (str_starts_with($normalized, $prefix)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                continue;
            }

            // Enable internet for all associated IPs
            $ips = $mac->ipAddresses()->get();
            foreach ($ips as $ip) {
                if (! $ip->internet_enabled) {
                    $ip->internet_enabled = true;
                    $ip->save();

                    AuditLog::record(
                        action: 'oui.auto_allowed',
                        subject: $ip,
                        related: $mac,
                        process: 'oui_policy',
                        metadata: ['mac' => $mac->mac_address],
                    );
                }
            }
        }
    }

    private function normalizeMac(string $mac): string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        return implode(':', str_split($hex, 2));
    }
}
```

- [ ] **Step 4: Run the refactored scan test**

```bash
php artisan test --compact --filter=ScanNetworkDevicesRefactorTest
```

Expected: All tests pass.

- [ ] **Step 5: Delete or rewrite old ScanNetworkDevices tests**

The old `tests/Feature/Jobs/ScanNetworkDevicesTest.php` and `tests/Feature/Jobs/ScanNetworkDevicesConfigTest.php` test the old behavior (auto_allow toggle, `allowed` column, etc.). These need to be rewritten or deleted since the new test file covers all the new behavior. Read the files and decide what to keep/rewrite.

- [ ] **Step 6: Run full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/ScanNetworkDevices.php tests/Feature/NetworkDeviceTracking/ScanNetworkDevicesRefactorTest.php tests/Feature/Jobs/
git commit -m "feat: refactor ScanNetworkDevices to greedy discovery + OUI policy

Phase 1 (discovery): persist all MACs, managed IPs, IP-MAC pivots,
DHCP leases, and SwitchPort-MAC FKs. Phase 2 (OUI policy): enable
internet for IPs on MACs matching configured OUI prefixes.
Old auto_allow toggle removed — discovery always runs."
```

---

## Task 7: OUI Configuration in Network Settings

**Files:**
- Modify: `app/Http/Controllers/Admin/NetworkSettingsController.php`
- Modify: `resources/js/Pages/Admin/Settings/Network.vue`
- Test: `tests/Feature/NetworkDeviceTracking/OuiConfigurationTest.php`

- [ ] **Step 1: Write the OUI configuration test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OuiConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_settings_shows_oui_field(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('admin.settings.network'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Network')
            ->has('settings.oui_auto_allow')
        );
    }

    public function test_valid_oui_prefixes_save_correctly(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => "AA:BB:CC\n00:50:F2",
        ]);

        $response->assertRedirect();
        $raw = Setting::get('network.oui_auto_allow');
        $this->assertEquals(['AA:BB:CC', '00:50:F2'], json_decode($raw, true));
    }

    public function test_invalid_oui_format_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => 'ZZZZ',
        ]);

        $response->assertSessionHasErrors('oui_auto_allow');
    }

    public function test_empty_oui_clears_setting(): void
    {
        $user = User::factory()->admin()->create();
        Setting::set('network.oui_auto_allow', 'OUI', json_encode(['AA:BB:CC']));

        $response = $this->actingAs($user)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => '',
        ]);

        $response->assertRedirect();
        $raw = Setting::get('network.oui_auto_allow');
        $this->assertEquals([], json_decode($raw, true));
    }

    public function test_single_octet_oui_accepted(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => 'AA',
        ]);

        $response->assertRedirect();
    }

    public function test_six_octet_full_mac_oui_accepted(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->put(route('admin.settings.network.update'), [
            'managed_ranges_v4' => '0.0.0.0/0',
            'managed_ranges_v6' => '::/0',
            'dns_filter_default' => false,
            'oui_auto_allow' => 'AA:BB:CC:DD:EE:FF',
        ]);

        $response->assertRedirect();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=OuiConfigurationTest
```

Expected: FAIL — OUI field not present in controller/view.

- [ ] **Step 3: Update NetworkSettingsController**

In `app/Http/Controllers/Admin/NetworkSettingsController.php`:

In `show()`, add OUI prefixes to the settings:

```php
$ouiRaw = Setting::get('network.oui_auto_allow');
$ouiDecoded = $ouiRaw !== null ? json_decode((string) $ouiRaw, true) : null;
$ouiPrefixes = is_array($ouiDecoded) ? $ouiDecoded : [];
```

And add to the Inertia response settings:
```php
'oui_auto_allow' => implode("\n", $ouiPrefixes),
```

In `update()`, add to validation:
```php
'oui_auto_allow' => ['nullable', 'string', $this->ouiValidationRule()],
```

Add the OUI parsing and storage after the existing settings saves:
```php
$ouiLines = $this->parseLines($validated['oui_auto_allow'] ?? '');
$ouiNormalized = array_map('strtoupper', $ouiLines);
Setting::set('network.oui_auto_allow', 'OUI Auto-Allow Prefixes', json_encode($ouiNormalized));
```

Add the validation rule method:
```php
private function ouiValidationRule(): Closure
{
    return function (string $attribute, mixed $value, Closure $fail): void {
        if ($value === null || $value === '') {
            return;
        }

        $lines = $this->parseLines((string) $value);
        foreach ($lines as $line) {
            if (! preg_match('/^([0-9A-Fa-f]{2}:){0,5}[0-9A-Fa-f]{2}$/', $line)) {
                $fail("Invalid OUI prefix: {$line}");
                return;
            }
        }
    };
}
```

- [ ] **Step 4: Update Network.vue**

Add `oui_auto_allow` to the form:

```javascript
const form = useForm({
    managed_ranges_v4: props.settings?.managed_ranges_v4 ?? '',
    managed_ranges_v6: props.settings?.managed_ranges_v6 ?? '',
    dns_filter_default: props.settings?.dns_filter_default ?? false,
    oui_auto_allow: props.settings?.oui_auto_allow ?? '',
});
```

Add a new section after "Network Defaults" in the template:

```html
<!-- OUI Auto-Allow -->
<h2
    data-testid="section-heading-oui"
    class="font-heading mt-8 mb-4 text-[10px] font-bold tracking-[1.5px] text-[var(--color-text-muted)] uppercase"
>
    OUI Auto-Allow
</h2>

<p class="text-[13px] text-[var(--color-text-secondary)]">
    MAC addresses matching these OUI prefixes will automatically receive internet access when discovered on a
    managed IP. One prefix per line.
</p>

<FormField label="OUI Prefixes" name="oui_auto_allow" :error="form.errors.oui_auto_allow">
    <textarea
        id="oui_auto_allow"
        v-model="form.oui_auto_allow"
        rows="4"
        data-testid="input-oui-auto-allow"
        placeholder="e.g. 00:50:F2"
        class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] px-3 py-2 font-mono text-[13px] text-[var(--color-text)] transition outline-none focus:border-[var(--color-primary)]"
    />
</FormField>
```

- [ ] **Step 5: Remove old IntegrationConfig auto_allow keys**

The old OUI/auto-allow configuration lived in `IntegrationConfig` under the `auto_allow` group (keys: `enabled`, `oui_prefixes`). Since OUI config now lives in `Setting::get('network.oui_auto_allow')`, remove the old keys.

In the migration file (`database/migrations/2026_04_23_300000_create_network_device_tracking_tables.php`), add at the end of `up()`:

```php
// 9. Remove old IntegrationConfig auto_allow keys
DB::table('integration_configs')->where('group', 'auto_allow')->delete();
```

Also check if the DHCP integration settings page (`/admin/integrations/dhcp` or similar) renders the old auto_allow toggle/prefixes fields. If so, remove those form fields from the controller and Vue page. Search for `auto_allow` in the DHCP controller/view and remove any references.

- [ ] **Step 6: Run the OUI configuration test**

```bash
php artisan test --compact --filter=OuiConfigurationTest
```

Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/NetworkSettingsController.php resources/js/Pages/Admin/Settings/Network.vue tests/Feature/NetworkDeviceTracking/OuiConfigurationTest.php database/migrations/2026_04_23_300000_create_network_device_tracking_tables.php
git commit -m "feat: add OUI auto-allow configuration to network settings

Textarea for OUI prefixes with validation. Stored in
network.oui_auto_allow setting as JSON array. Removes old
IntegrationConfig auto_allow keys."
```

---

## Task 8: Consumer Updates — DashboardController, IpAddressController, Show.vue

**Files:**
- Modify: `app/Http/Controllers/Portal/DashboardController.php`
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`
- Modify: `resources/js/Pages/Admin/Ips/Show.vue`
- Test: `tests/Feature/NetworkDeviceTracking/ConsumerUpdateTest.php`

- [ ] **Step 1: Write the consumer update test**

```php
<?php

namespace Tests\Feature\NetworkDeviceTracking;

use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Services\Interfaces\NetworkInventoryInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ConsumerUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_current_mac(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create(['address' => '127.0.0.1']);
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip->macAddresses()->attach($mac, ['source' => 'arp', 'last_seen_at' => now()]);

        $networkInv = Mockery::mock(NetworkInventoryInterface::class);
        $networkInv->shouldReceive('getIpv6Neighbors')->andReturn(collect());
        $this->app->instance(NetworkInventoryInterface::class, $networkInv);

        $response = $this->actingAs($user)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('blockContext.macAddress', 'AA:BB:CC:DD:EE:FF')
        );
    }

    public function test_dashboard_mac_is_null_when_no_mac_linked(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);

        $networkInv = Mockery::mock(NetworkInventoryInterface::class);
        $networkInv->shouldReceive('getIpv6Neighbors')->andReturn(collect());
        $this->app->instance(NetworkInventoryInterface::class, $networkInv);

        $response = $this->actingAs($user)->get(route('portal.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('blockContext.macAddress', null)
        );
    }

    public function test_ip_show_includes_current_mac(): void
    {
        Queue::fake();
        $user = User::factory()->admin()->create();
        $ip = IpAddress::factory()->create();
        $mac = MacAddress::factory()->create();
        $ip->macAddresses()->attach($mac, ['source' => 'dhcp', 'last_seen_at' => now()]);

        $response = $this->actingAs($user)->get(route('admin.ips.show', $ip));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('ip.current_mac')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ConsumerUpdateTest
```

Expected: FAIL — `$ip->mac` no longer exists.

- [ ] **Step 3: Update DashboardController**

In `app/Http/Controllers/Portal/DashboardController.php`:

Change line 35 from:
```php
$ipv6 = $ip !== null ? $this->resolveIpv6ForMac($ip->mac) : null;
```
To:
```php
$currentMac = $ip?->currentMac();
$ipv6 = $currentMac !== null ? $this->resolveIpv6ForMac($currentMac->mac_address) : null;
```

Change line 45 from:
```php
'macAddress' => $ip?->mac,
```
To:
```php
'macAddress' => $currentMac?->mac_address,
```

Update `resolveIpv6ForMac` signature (already takes `?string $mac`, no change needed).

- [ ] **Step 4: Update IpAddressController show method**

In `app/Http/Controllers/Admin/IpAddressController.php`, in the `show()` method, add `current_mac` to the IP data passed to the view. After loading $ip, add:

```php
$ip->loadMissing('macAddresses');
$currentMac = $ip->currentMac();
```

In the Inertia render, change:
```php
'ip' => $ip,
```
To:
```php
'ip' => array_merge($ip->toArray(), [
    'current_mac' => $currentMac ? [
        'id' => $currentMac->id,
        'mac_address' => $currentMac->mac_address,
    ] : null,
]),
```

- [ ] **Step 5: Update Show.vue**

In `resources/js/Pages/Admin/Ips/Show.vue`, find any reference to `ip.mac` and replace with `ip.current_mac?.mac_address`. This is a display-only change — find the exact usage by reading the file.

- [ ] **Step 6: Verify portal.blade.php**

In `resources/views/portal.blade.php`, search for any reference to `$ip->mac` or `$ip?->mac`. If found, replace with `$ip?->currentMac()?->mac_address`. The spec lists this file as needing the change. If no MAC references exist, no changes needed.

- [ ] **Step 7: Run the consumer update test**

```bash
php artisan test --compact --filter=ConsumerUpdateTest
```

Expected: All tests pass.

- [ ] **Step 7: Run the full test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Portal/DashboardController.php app/Http/Controllers/Admin/IpAddressController.php resources/js/Pages/Admin/Ips/Show.vue tests/Feature/NetworkDeviceTracking/ConsumerUpdateTest.php
git commit -m "feat: update consumers to use currentMac() instead of removed mac accessor

DashboardController, IpAddressController, and Ips/Show.vue now use
the new many-to-many relationship via currentMac()."
```

---

## Task 9: Cisco Switch Bulk Command Optimization

**Files:**
- Modify: `app/Services/NetworkSwitch/CiscoSwitchAdapter.php`
- Modify: `app/Services/NetworkSwitch/IosOutputParser.php`
- Test: `tests/Unit/Services/NetworkSwitch/CiscoBulkCommandTest.php`

- [ ] **Step 1: Write the bulk command test**

```php
<?php

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\NetworkSwitch\IosOutputParser;
use Tests\TestCase;

class CiscoBulkCommandTest extends TestCase
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IosOutputParser;
    }

    public function test_split_bulk_show_interface_separates_by_interface(): void
    {
        $output = <<<'OUTPUT'
GigabitEthernet0/1 is up, line protocol is up (connected)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0001
  Description: Server 1
  5 minute input rate 1000 bits/sec
GigabitEthernet0/2 is down, line protocol is down (notconnect)
  Hardware is Gigabit Ethernet, address is aabb.ccdd.0002
  Description: Server 2
  5 minute input rate 0 bits/sec
OUTPUT;

        $result = $this->parser->splitBulkShowInterface($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('GigabitEthernet0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertStringContains('Server 1', $result['GigabitEthernet0/1']);
        $this->assertStringContains('Server 2', $result['GigabitEthernet0/2']);
    }

    public function test_split_bulk_show_interface_handles_ten_gigabit(): void
    {
        $output = <<<'OUTPUT'
TenGigabitEthernet1/0/1 is up, line protocol is up (connected)
  Hardware is Ten Gigabit Ethernet
TenGigabitEthernet1/0/2 is down, line protocol is down (notconnect)
  Hardware is Ten Gigabit Ethernet
OUTPUT;

        $result = $this->parser->splitBulkShowInterface($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('TenGigabitEthernet1/0/1', $result);
        $this->assertArrayHasKey('TenGigabitEthernet1/0/2', $result);
    }

    public function test_split_bulk_running_config_separates_by_interface(): void
    {
        $output = <<<'OUTPUT'
interface GigabitEthernet0/1
 description Server 1
 switchport mode access
 switchport access vlan 100
!
interface GigabitEthernet0/2
 description Server 2
 switchport mode trunk
!
OUTPUT;

        $result = $this->parser->splitBulkRunningConfig($output);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('GigabitEthernet0/1', $result);
        $this->assertArrayHasKey('GigabitEthernet0/2', $result);
        $this->assertStringContains('switchport mode access', $result['GigabitEthernet0/1']);
        $this->assertStringContains('switchport mode trunk', $result['GigabitEthernet0/2']);
    }

    public function test_split_bulk_running_config_handles_empty_output(): void
    {
        $result = $this->parser->splitBulkRunningConfig('');

        $this->assertCount(0, $result);
    }

    public function test_split_bulk_show_interface_handles_empty_output(): void
    {
        $result = $this->parser->splitBulkShowInterface('');

        $this->assertCount(0, $result);
    }

    /**
     * Custom assertion since assertStringContainsString is verbose.
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=CiscoBulkCommandTest
```

Expected: FAIL — methods don't exist.

- [ ] **Step 3: Add parsing methods to IosOutputParser**

In `app/Services/NetworkSwitch/IosOutputParser.php`, add:

```php
/**
 * Split bulk `show interface` output into per-interface blocks.
 *
 * @return array<string, string>  interface name => output block
 */
public function splitBulkShowInterface(string $output): array
{
    if (trim($output) === '') {
        return [];
    }

    $pattern = '/^((?:GigabitEthernet|FastEthernet|TenGigabitEthernet|TwentyFiveGigE|FortyGigabitEthernet|HundredGigE|Vlan|Loopback|Port-channel)\S+)\s+is\s+/m';

    $parts = preg_split($pattern, $output, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return [];
    }

    $result = [];
    for ($i = 0; $i < count($parts) - 1; $i += 2) {
        $interfaceName = $parts[$i];
        $block = $interfaceName . ' is ' . $parts[$i + 1];
        $result[$interfaceName] = trim($block);
    }

    return $result;
}

/**
 * Split bulk `show running-config | section ^interface` output into per-interface blocks.
 *
 * @return array<string, string>  interface name => config block
 */
public function splitBulkRunningConfig(string $output): array
{
    if (trim($output) === '') {
        return [];
    }

    $lines = preg_split('/\r?\n/', $output) ?: [];
    $result = [];
    $currentInterface = null;
    $currentBlock = '';

    foreach ($lines as $line) {
        if (preg_match('/^interface\s+(\S+)/', $line, $matches)) {
            if ($currentInterface !== null) {
                $result[$currentInterface] = trim($currentBlock);
            }
            $currentInterface = $matches[1];
            $currentBlock = $line;
        } elseif ($currentInterface !== null) {
            if ($line === '!') {
                $result[$currentInterface] = trim($currentBlock);
                $currentInterface = null;
                $currentBlock = '';
            } else {
                $currentBlock .= "\n" . $line;
            }
        }
    }

    if ($currentInterface !== null) {
        $result[$currentInterface] = trim($currentBlock);
    }

    return $result;
}
```

- [ ] **Step 4: Update CiscoSwitchAdapter to use bulk commands**

In `app/Services/NetworkSwitch/CiscoSwitchAdapter.php`:

Add a cached bulk output property and methods:

```php
/** @var array<string, string>|null */
private ?array $bulkInterfaceCache = null;

/** @var array<string, string>|null */
private ?array $bulkConfigCache = null;

private function getBulkInterfaceOutputs(): array
{
    if ($this->bulkInterfaceCache === null) {
        $output = $this->transport->execute('show interface');
        $this->bulkInterfaceCache = $this->parser->splitBulkShowInterface($output);
    }

    return $this->bulkInterfaceCache;
}

private function getBulkRunningConfigs(): array
{
    if ($this->bulkConfigCache === null) {
        $output = $this->transport->execute('show running-config | section ^interface');
        $this->bulkConfigCache = $this->parser->splitBulkRunningConfig($output);
    }

    return $this->bulkConfigCache;
}
```

Update `getPortStatus()` to use bulk cache:

```php
public function getPortStatus(string $portId): PortStatus
{
    $output = $this->getBulkInterfaceOutputs()[$portId] ?? $this->getPortInterfaceOutput($portId);
    $parsed = $this->parser->parseShowInterface($output);

    return new PortStatus(
        interface: $parsed->interface,
        status: $parsed->status,
        speed: $parsed->speed,
        duplex: $parsed->duplex,
        vlan: $parsed->vlan,
        description: $output,
        switchportMode: $parsed->switchportMode,
    );
}
```

Update `getPortInterfaceOutput()` to use bulk cache:

```php
public function getPortInterfaceOutput(string $portId): string
{
    return $this->getBulkInterfaceOutputs()[$portId]
        ?? $this->transport->execute('show interface ' . $portId);
}
```

Update `getPortRunningConfig()` to use bulk cache:

```php
public function getPortRunningConfig(string $portId): string
{
    return $this->getBulkRunningConfigs()[$portId]
        ?? $this->transport->execute('show run interface ' . $portId);
}
```

Update `getPortStatistics()` to use bulk cache:

```php
public function getPortStatistics(string $portId): PortStatistics
{
    $output = $this->getBulkInterfaceOutputs()[$portId]
        ?? $this->transport->execute('show interface ' . $portId);

    return $this->parser->parseInterfaceCounters($output);
}
```

- [ ] **Step 5: Run the bulk command test**

```bash
php artisan test --compact --filter=CiscoBulkCommandTest
```

Expected: All tests pass.

- [ ] **Step 6: Run existing Cisco adapter tests**

```bash
php artisan test --compact --filter=CiscoSwitchAdapterTest
```

Expected: All pass (the adapter still falls back to per-port commands when cache doesn't have the port).

- [ ] **Step 7: Commit**

```bash
git add app/Services/NetworkSwitch/CiscoSwitchAdapter.php app/Services/NetworkSwitch/IosOutputParser.php tests/Unit/Services/NetworkSwitch/CiscoBulkCommandTest.php
git commit -m "feat: optimize Cisco adapter with bulk show interface/running-config

Fetch all interface data in two SSH calls instead of 2N. Cache results
per adapter instance. Falls back to per-port commands if bulk output
doesn't contain the requested port."
```

---

## Task 10: Quality Checks and Full Test Suite

**Files:** All modified files

- [ ] **Step 1: Run Laravel Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 2: Run PHPStan**

```bash
vendor/bin/phpstan analyse
```

Fix any errors. Common issues:
- Missing return types on new methods
- Incorrect generic types on relationships
- Unused imports

- [ ] **Step 3: Run Rector**

```bash
vendor/bin/rector process --dry-run
```

Apply any suggestions.

- [ ] **Step 4: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: All tests pass.

- [ ] **Step 5: Run JS linting**

```bash
npm run lint
npm run format:check
```

Fix any issues in Network.vue or Show.vue.

- [ ] **Step 6: Commit any formatting/quality fixes**

```bash
git add -A
git commit -m "fix: code quality and formatting fixes for network device tracking"
```
