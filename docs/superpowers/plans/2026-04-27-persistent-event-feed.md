# Persistent Event Feed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the client-side-only EventFeed with a persistent, database-backed event feed — a dedicated admin page with server-side filtering, live WebSocket updates, and a dashboard widget preloaded from the database.

**Architecture:** New `SystemEvent` model stores broadcast events via a queued wildcard subscriber. `EventController` serves a paginated, filterable Inertia page. The dashboard widget receives preloaded events as a prop. Live WebSocket events appear as a "new events" banner on the full page, and prepend directly on the dashboard widget.

**Tech Stack:** Laravel 12 (L10 structure), Vue 3 + Inertia, Laravel Echo/Reverb, PHPUnit, Vitest

---

### Task 1: Config, Migration, Model & Factory

**Files:**
- Create: `config/events.php`
- Create: `database/migrations/xxxx_xx_xx_create_system_events_table.php`
- Create: `app/Models/SystemEvent.php`
- Create: `database/factories/SystemEventFactory.php`
- Test: `tests/Unit/Models/SystemEventTest.php`

- [ ] **Step 1: Write the failing test for SystemEvent model**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SystemEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SystemEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_system_event_can_be_created_with_all_fields(): void
    {
        $event = SystemEvent::create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'alice connected from 10.0.0.42',
            'data' => ['user_id' => 1, 'ip_address' => '10.0.0.42'],
        ]);

        $this->assertDatabaseHas('system_events', [
            'id' => $event->id,
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'alice connected from 10.0.0.42',
        ]);
    }

    public function test_data_is_cast_to_array(): void
    {
        $event = SystemEvent::create([
            'type' => 'DeviceDiscovered',
            'level' => 'info',
            'message' => 'New device discovered',
            'data' => ['mac_address' => 'AA:BB:CC:DD:EE:FF'],
        ]);

        $event->refresh();

        $this->assertIsArray($event->data);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $event->data['mac_address']);
    }

    public function test_data_is_nullable(): void
    {
        $event = SystemEvent::create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'test',
            'data' => null,
        ]);

        $event->refresh();

        $this->assertNull($event->data);
    }

    public function test_has_no_updated_at_column(): void
    {
        $event = SystemEvent::create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'test',
        ]);

        $this->assertNull($event->updated_at);
    }

    public function test_factory_creates_valid_model(): void
    {
        $event = SystemEvent::factory()->create();

        $this->assertNotNull($event->id);
        $this->assertNotNull($event->type);
        $this->assertNotNull($event->level);
        $this->assertNotNull($event->message);
    }

    public function test_factory_info_state(): void
    {
        $event = SystemEvent::factory()->info()->create();

        $this->assertSame('info', $event->level);
    }

    public function test_factory_warning_state(): void
    {
        $event = SystemEvent::factory()->warning()->create();

        $this->assertSame('warning', $event->level);
    }

    public function test_factory_critical_state(): void
    {
        $event = SystemEvent::factory()->critical()->create();

        $this->assertSame('critical', $event->level);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SystemEventTest`
Expected: FAIL — table/model doesn't exist yet

- [ ] **Step 3: Create config file**

Create `config/events.php`:

```php
<?php

declare(strict_types=1);

return [
    'retention_days' => env('EVENT_RETENTION_DAYS', 30),
];
```

- [ ] **Step 4: Create migration**

Run: `php artisan make:migration create_system_events_table --no-interaction`

Replace the generated migration content with:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 100)->index();
            $table->string('level', 20)->index();
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
    }
};
```

- [ ] **Step 5: Create SystemEvent model**

Create `app/Models/SystemEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SystemEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string $level
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
class SystemEvent extends Model
{
    /** @use HasFactory<SystemEventFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'level',
        'message',
        'data',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
```

- [ ] **Step 6: Create SystemEventFactory**

Create `database/factories/SystemEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SystemEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SystemEvent> */
class SystemEventFactory extends Factory
{
    protected $model = SystemEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => $this->faker->sentence(),
            'data' => ['ip_address' => $this->faker->ipv4()],
        ];
    }

    public function info(): static
    {
        return $this->state(['level' => 'info']);
    }

    public function warning(): static
    {
        return $this->state(['level' => 'warning']);
    }

    public function critical(): static
    {
        return $this->state(['level' => 'critical']);
    }

    public function userConnected(): static
    {
        return $this->state([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'User connected from ' . $this->faker->ipv4(),
        ]);
    }

    public function switchUnreachable(): static
    {
        return $this->state([
            'type' => 'SwitchUnreachable',
            'level' => 'critical',
            'message' => 'Switch ' . $this->faker->domainWord() . ' unreachable after 3 failures',
        ]);
    }

    public function dhcpPoolThreshold(): static
    {
        return $this->state([
            'type' => 'DhcpPoolThresholdReached',
            'level' => 'warning',
            'message' => 'DHCP pool LAN reached 90% utilization',
        ]);
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact --filter=SystemEventTest`
Expected: 8 tests PASS

- [ ] **Step 8: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 9: Commit**

```bash
git add config/events.php database/migrations/*create_system_events_table* app/Models/SystemEvent.php database/factories/SystemEventFactory.php tests/Unit/Models/SystemEventTest.php
git commit -m "feat: add SystemEvent model, migration, factory and config"
```

---

### Task 2: RecordBroadcastEvent Subscriber

**Files:**
- Create: `app/Listeners/RecordBroadcastEvent.php`
- Modify: `app/Providers/EventServiceProvider.php`
- Test: `tests/Feature/Listeners/RecordBroadcastEventTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DeviceDiscovered;
use App\Events\DhcpPoolThresholdReached;
use App\Events\DnsFilterChanged;
use App\Events\InternetAccessChanged;
use App\Events\PortStateChanged;
use App\Events\RateLimitChanged;
use App\Events\SwitchSyncCompleted;
use App\Events\SwitchUnreachable;
use App\Events\UserBlocked;
use App\Events\UserConnected;
use App\Listeners\RecordBroadcastEvent;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecordBroadcastEventTest extends TestCase
{
    use LazilyRefreshDatabase;

    private RecordBroadcastEvent $listener;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->listener = new RecordBroadcastEvent;
    }

    public function test_records_user_connected_event(): void
    {
        $user = User::factory()->create(['nickname' => 'alice']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.42']);
        $mac = MacAddress::factory()->create();

        $event = new UserConnected($user, $ip, $mac);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'alice connected from 10.0.0.42',
        ]);
    }

    public function test_records_device_discovered_event(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.50']);

        $event = new DeviceDiscovered($mac, $ip, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DeviceDiscovered',
            'level' => 'info',
            'message' => 'New device AA:BB:CC:DD:EE:FF discovered on 10.0.0.50',
        ]);
    }

    public function test_records_device_discovered_without_ip(): void
    {
        $mac = MacAddress::factory()->create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);

        $event = new DeviceDiscovered($mac, null, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DeviceDiscovered',
            'message' => 'New device AA:BB:CC:DD:EE:FF discovered',
        ]);
    }

    public function test_records_switch_sync_completed_event(): void
    {
        $switch = SwitchConfig::factory()->create(['hostname' => 'core-sw-01']);

        $event = new SwitchSyncCompleted($switch, 5, []);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'SwitchSyncCompleted',
            'level' => 'info',
            'message' => 'Switch core-sw-01 sync completed (5 ports updated)',
        ]);
    }

    public function test_records_switch_sync_completed_with_errors(): void
    {
        $switch = SwitchConfig::factory()->create(['hostname' => 'core-sw-01']);

        $event = new SwitchSyncCompleted($switch, 5, ['error1', 'error2']);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'SwitchSyncCompleted',
            'message' => 'Switch core-sw-01 sync completed (5 ports updated) - 2 errors',
        ]);
    }

    public function test_records_dhcp_pool_threshold_reached_event(): void
    {
        $event = new DhcpPoolThresholdReached('LAN', 92.0, 90.0);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DhcpPoolThresholdReached',
            'level' => 'warning',
            'message' => 'DHCP pool LAN reached 92% utilization',
        ]);
    }

    public function test_records_internet_access_changed_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new InternetAccessChanged($ip, true, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'InternetAccessChanged',
            'level' => 'info',
            'message' => 'Internet access enabled for 10.0.0.10',
        ]);
    }

    public function test_records_internet_access_disabled_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new InternetAccessChanged($ip, false, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'message' => 'Internet access disabled for 10.0.0.10',
        ]);
    }

    public function test_records_rate_limit_changed_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new RateLimitChanged($ip, 100, 200, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'RateLimitChanged',
            'level' => 'info',
            'message' => 'Rate limit changed for 10.0.0.10 from 100 to 200',
        ]);
    }

    public function test_records_user_blocked_event(): void
    {
        $user = User::factory()->create(['nickname' => 'bob']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.5']);

        $event = new UserBlocked($user, $ip, 'Terms violation');
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'UserBlocked',
            'level' => 'critical',
            'message' => 'bob blocked on 10.0.0.5: Terms violation',
        ]);
    }

    public function test_records_dns_filter_changed_event(): void
    {
        $ip = IpAddress::factory()->create(['address' => '10.0.0.10']);

        $event = new DnsFilterChanged($ip, true, null);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'DnsFilterChanged',
            'level' => 'info',
            'message' => 'DNS filter enabled for 10.0.0.10',
        ]);
    }

    public function test_records_switch_unreachable_event(): void
    {
        $switch = SwitchConfig::factory()->create(['hostname' => 'edge-sw-03']);

        $event = new SwitchUnreachable($switch, 3);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'SwitchUnreachable',
            'level' => 'critical',
            'message' => 'Switch edge-sw-03 unreachable after 3 failures',
        ]);
    }

    public function test_records_bandwidth_anomaly_detected_event(): void
    {
        $event = new BandwidthAnomalyDetected('10.0.0.42', 'alice', 1, 5000.0, 1000.0, 5.0, 3.0);
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'BandwidthAnomalyDetected',
            'level' => 'warning',
            'message' => 'Bandwidth anomaly detected on 10.0.0.42',
        ]);
    }

    public function test_records_port_state_changed_event(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $port = SwitchPort::factory()->for($switchConfig)->create(['port_name' => 'Gi1/0/1']);

        $event = new PortStateChanged($port, 'up', 'down');
        $this->listener->handleBroadcastEvent($event);

        $this->assertDatabaseHas('system_events', [
            'type' => 'PortStateChanged',
            'level' => 'info',
            'message' => 'Port Gi1/0/1 changed to down',
        ]);
    }

    public function test_stores_broadcast_data_as_json(): void
    {
        $user = User::factory()->create(['nickname' => 'alice']);
        $ip = IpAddress::factory()->create(['address' => '10.0.0.42']);
        $mac = MacAddress::factory()->create();

        $event = new UserConnected($user, $ip, $mac);
        $this->listener->handleBroadcastEvent($event);

        $stored = SystemEvent::where('type', 'UserConnected')->first();
        $this->assertNotNull($stored);
        $this->assertIsArray($stored->data);
        $this->assertSame($user->id, $stored->data['user_id']);
        $this->assertSame('10.0.0.42', $stored->data['ip_address']);
    }

    public function test_ignores_non_broadcast_events(): void
    {
        $this->listener->handleWildcard('eloquent.created: App\Models\User', [new \stdClass]);

        $this->assertDatabaseCount('system_events', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RecordBroadcastEventTest`
Expected: FAIL — class doesn't exist

- [ ] **Step 3: Create the subscriber**

Create `app/Listeners/RecordBroadcastEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\SystemEvent;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;

class RecordBroadcastEvent implements ShouldQueue
{
    public string $queue = 'event-recording';

    private const LEVEL_MAP = [
        'UserConnected' => 'info',
        'DeviceDiscovered' => 'info',
        'SwitchSyncCompleted' => 'info',
        'InternetAccessChanged' => 'info',
        'RateLimitChanged' => 'info',
        'DnsFilterChanged' => 'info',
        'PortStateChanged' => 'info',
        'DhcpPoolThresholdReached' => 'warning',
        'BandwidthAnomalyDetected' => 'warning',
        'SwitchUnreachable' => 'critical',
        'UserBlocked' => 'critical',
    ];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('*', [self::class, 'handleWildcard']);
    }

    /**
     * @param  list<mixed>  $payload
     */
    public function handleWildcard(string $eventName, array $payload): void
    {
        $event = $payload[0] ?? null;

        if (! $event instanceof ShouldBroadcast) {
            return;
        }

        $this->handleBroadcastEvent($event);
    }

    public function handleBroadcastEvent(ShouldBroadcast $event): void
    {
        $type = class_basename($event);
        $data = method_exists($event, 'broadcastWith') ? $event->broadcastWith() : [];

        SystemEvent::create([
            'type' => $type,
            'level' => self::LEVEL_MAP[$type] ?? 'info',
            'message' => $this->formatMessage($type, $data),
            'data' => $data ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function formatMessage(string $type, array $data): string
    {
        return match ($type) {
            'UserConnected' => sprintf('%s connected from %s', $data['user_name'] ?? 'Unknown', $data['ip_address'] ?? 'unknown'),
            'DeviceDiscovered' => $this->formatDeviceDiscovered($data),
            'PortStateChanged' => sprintf('Port %s changed to %s', $data['port_name'] ?? 'unknown', $data['new_status'] ?? 'unknown'),
            'SwitchSyncCompleted' => $this->formatSwitchSyncCompleted($data),
            'DhcpPoolThresholdReached' => sprintf('DHCP pool %s reached %s%% utilization', $data['pool'] ?? 'unknown', (int) ($data['usage'] ?? 0)),
            'InternetAccessChanged' => sprintf('Internet access %s for %s', ($data['enabled'] ?? false) ? 'enabled' : 'disabled', $data['ip_address'] ?? 'unknown'),
            'RateLimitChanged' => sprintf('Rate limit changed for %s from %s to %s', $data['ip_address'] ?? 'unknown', $data['old_limit'] ?? '?', $data['new_limit'] ?? '?'),
            'UserBlocked' => sprintf('%s blocked on %s: %s', $data['user_name'] ?? 'Unknown', $data['ip_address'] ?? 'unknown', $data['reason'] ?? 'no reason'),
            'DnsFilterChanged' => sprintf('DNS filter %s for %s', ($data['enabled'] ?? false) ? 'enabled' : 'disabled', $data['ip_address'] ?? 'unknown'),
            'SwitchUnreachable' => sprintf('Switch %s unreachable after %s failures', $data['hostname'] ?? 'unknown', $data['failure_count'] ?? '?'),
            'BandwidthAnomalyDetected' => $this->formatBandwidthAnomaly($data),
            default => sprintf('%s event received', $type),
        };
    }

    /** @param array<string, mixed> $data */
    private function formatDeviceDiscovered(array $data): string
    {
        $msg = sprintf('New device %s discovered', $data['mac_address'] ?? 'unknown');
        if (! empty($data['ip_address'])) {
            $msg .= sprintf(' on %s', $data['ip_address']);
        }

        return $msg;
    }

    /** @param array<string, mixed> $data */
    private function formatSwitchSyncCompleted(array $data): string
    {
        $msg = sprintf('Switch %s sync completed (%s ports updated)', $data['hostname'] ?? 'unknown', $data['ports_updated'] ?? 0);
        $errorCount = is_array($data['errors'] ?? null) ? count($data['errors']) : 0;
        if ($errorCount > 0) {
            $msg .= sprintf(' - %d errors', $errorCount);
        }

        return $msg;
    }

    /** @param array<string, mixed> $data */
    private function formatBandwidthAnomaly(array $data): string
    {
        $msg = 'Bandwidth anomaly detected';
        if (! empty($data['ip_address'])) {
            $msg .= sprintf(' on %s', $data['ip_address']);
        }

        return $msg;
    }
}
```

- [ ] **Step 4: Register subscriber in EventServiceProvider**

Modify `app/Providers/EventServiceProvider.php` — add the `$subscribe` property:

```php
// Add after the $listen property:

    /**
     * The subscriber classes to register.
     *
     * @var array<int, class-string>
     */
    protected $subscribe = [
        \App\Listeners\RecordBroadcastEvent::class,
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=RecordBroadcastEventTest`
Expected: All 15 tests PASS

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Listeners/RecordBroadcastEvent.php app/Providers/EventServiceProvider.php tests/Feature/Listeners/RecordBroadcastEventTest.php
git commit -m "feat: add RecordBroadcastEvent subscriber to persist broadcast events"
```

---

### Task 3: Pruning Command

**Files:**
- Create: `app/Console/Commands/PruneSystemEventsCommand.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Feature/Commands/PruneSystemEventsCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\SystemEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PruneSystemEventsCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deletes_events_older_than_retention_period(): void
    {
        config(['events.retention_days' => 30]);

        SystemEvent::factory()->create(['created_at' => now()->subDays(31)]);
        SystemEvent::factory()->create(['created_at' => now()->subDays(35)]);
        $recent = SystemEvent::factory()->create(['created_at' => now()->subDays(5)]);

        $this->artisan('events:prune')->assertSuccessful();

        $this->assertDatabaseCount('system_events', 1);
        $this->assertDatabaseHas('system_events', ['id' => $recent->id]);
    }

    public function test_respects_custom_retention_config(): void
    {
        config(['events.retention_days' => 7]);

        SystemEvent::factory()->create(['created_at' => now()->subDays(8)]);
        $recent = SystemEvent::factory()->create(['created_at' => now()->subDays(3)]);

        $this->artisan('events:prune')->assertSuccessful();

        $this->assertDatabaseCount('system_events', 1);
        $this->assertDatabaseHas('system_events', ['id' => $recent->id]);
    }

    public function test_does_nothing_when_no_old_events(): void
    {
        config(['events.retention_days' => 30]);

        SystemEvent::factory()->count(3)->create(['created_at' => now()]);

        $this->artisan('events:prune')->assertSuccessful();

        $this->assertDatabaseCount('system_events', 3);
    }

    public function test_outputs_deleted_count(): void
    {
        config(['events.retention_days' => 30]);

        SystemEvent::factory()->count(5)->create(['created_at' => now()->subDays(31)]);

        $this->artisan('events:prune')
            ->expectsOutputToContain('5')
            ->assertSuccessful();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PruneSystemEventsCommandTest`
Expected: FAIL — command doesn't exist

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/PruneSystemEventsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemEvent;
use Illuminate\Console\Command;

class PruneSystemEventsCommand extends Command
{
    protected $signature = 'events:prune';

    protected $description = 'Delete system events older than the configured retention period';

    public function handle(): int
    {
        $days = (int) config('events.retention_days', 30);
        $cutoff = now()->subDays($days);

        $deleted = 0;

        do {
            $batch = SystemEvent::where('created_at', '<', $cutoff)
                ->limit(1000)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("Pruned {$deleted} system events older than {$days} days.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Register in Kernel schedule**

Modify `app/Console/Kernel.php` — add inside `schedule()` method, after the existing entries:

```php
        $schedule->command('events:prune')->daily()->onOneServer();
```

Add the import at the top if not already present — but since this uses a string signature, no import needed.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=PruneSystemEventsCommandTest`
Expected: 4 tests PASS

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/PruneSystemEventsCommand.php app/Console/Kernel.php tests/Feature/Commands/PruneSystemEventsCommandTest.php
git commit -m "feat: add events:prune command with configurable retention"
```

---

### Task 4: EventController & Route

**Files:**
- Create: `app/Http/Controllers/Admin/EventController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Admin/EventControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_index_loads(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create();

        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Events/Index')
            ->has('events.data', 1)
            ->has('totalCount')
            ->has('filters')
            ->has('breadcrumbs')
        );
    }

    public function test_non_admin_cannot_access(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/events')->assertForbidden();
    }

    public function test_search_filters_by_type(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create(['type' => 'UserConnected', 'message' => 'alice connected']);
        SystemEvent::factory()->create(['type' => 'SwitchUnreachable', 'message' => 'switch down']);

        $response = $this->actingAs($admin)->get('/admin/events?search=UserConnected');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.type', 'UserConnected')
        );
    }

    public function test_search_filters_by_message(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create(['type' => 'UserConnected', 'message' => 'alice connected from 10.0.0.42']);
        SystemEvent::factory()->create(['type' => 'UserConnected', 'message' => 'bob connected from 10.0.0.43']);

        $response = $this->actingAs($admin)->get('/admin/events?search=alice');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
            ->where('events.data.0.message', 'alice connected from 10.0.0.42')
        );
    }

    public function test_total_count_reflects_unfiltered_total(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->count(5)->create(['type' => 'UserConnected', 'message' => 'user connected']);
        SystemEvent::factory()->count(3)->create(['type' => 'SwitchUnreachable', 'message' => 'switch down']);

        $response = $this->actingAs($admin)->get('/admin/events?search=UserConnected');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 5)
            ->where('totalCount', 8)
        );
    }

    public function test_pagination_works(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->count(25)->create();

        $response = $this->actingAs($admin)->get('/admin/events?perPage=10');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 10)
        );
    }

    public function test_ordered_by_created_at_desc(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        $old = SystemEvent::factory()->create(['message' => 'old', 'created_at' => now()->subHour()]);
        $new = SystemEvent::factory()->create(['message' => 'new', 'created_at' => now()]);

        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('events.data.0.message', 'new')
            ->where('events.data.1.message', 'old')
        );
    }

    public function test_events_include_expected_fields(): void
    {
        Queue::fake();
        $admin = $this->createAdminUser();
        SystemEvent::factory()->create([
            'type' => 'UserConnected',
            'level' => 'info',
            'message' => 'test event',
            'data' => ['key' => 'value'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/events');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('events.data.0', fn ($event) => $event
                ->has('id')
                ->where('type', 'UserConnected')
                ->where('level', 'info')
                ->where('message', 'test event')
                ->has('created_at')
                ->etc()
            )
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=EventControllerTest`
Expected: FAIL — route/controller doesn't exist

- [ ] **Step 3: Create EventController**

Create `app/Http/Controllers/Admin/EventController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request): Response
    {
        $search = (string) $request->input('search', '');
        $perPage = (int) $request->input('perPage', 50);

        $query = SystemEvent::query()->orderByDesc('created_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('type', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%");
            });
        }

        $events = $query->paginate($perPage)->appends(['search' => $search, 'perPage' => $perPage]);

        $events->getCollection()->transform(fn (SystemEvent $event): array => [
            'id' => $event->id,
            'type' => $event->type,
            'level' => $event->level,
            'message' => $event->message,
            'created_at' => $event->created_at->toIso8601String(),
        ]);

        $totalCount = SystemEvent::count();

        return Inertia::render('Admin/Events/Index', [
            'events' => $events,
            'totalCount' => $totalCount,
            'filters' => (object) [
                'search' => $search,
                'perPage' => $perPage,
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Event Feed'],
            ],
        ]);
    }
}
```

- [ ] **Step 4: Add route**

Modify `routes/web.php` — add the import at the top with other admin controllers:

```php
use App\Http\Controllers\Admin\EventController;
```

Add the route inside the admin middleware group, after the Audit Log route (line ~118):

```php
        // Event Feed
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=EventControllerTest`
Expected: 8 tests PASS

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/EventController.php routes/web.php tests/Feature/Admin/EventControllerTest.php
git commit -m "feat: add EventController with search, pagination and route"
```

---

### Task 5: Event Feed Page (Vue)

**Files:**
- Create: `resources/js/Pages/Admin/Events/Index.vue`
- Create: `resources/js/Components/Icons/EventFeedIcon.vue`
- Modify: `resources/js/Components/Admin/Sidebar.vue`
- Test: `tests/js/Pages/Admin/Events/Index.spec.js`

- [ ] **Step 1: Write the failing Vitest test**

Create `tests/js/Pages/Admin/Events/Index.spec.js`:

```js
import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Index from '@/Pages/Admin/Events/Index.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: vi.fn(),
        reload: vi.fn(),
    },
    Link: {
        template: '<a><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

vi.mock('@/utils/dates', () => ({
    formatRelativeTime: vi.fn((v) => v ?? '—'),
}));

globalThis.route = (...args) => `/mocked/${args[0]}`;

const mockEvents = [
    {
        id: 1,
        type: 'UserConnected',
        level: 'info',
        message: 'alice connected from 10.0.0.42',
        created_at: '2026-04-27T12:00:00+00:00',
    },
    {
        id: 2,
        type: 'SwitchUnreachable',
        level: 'critical',
        message: 'Switch edge-sw-03 unreachable after 3 failures',
        created_at: '2026-04-27T11:59:00+00:00',
    },
    {
        id: 3,
        type: 'DhcpPoolThresholdReached',
        level: 'warning',
        message: 'DHCP pool LAN reached 92% utilization',
        created_at: '2026-04-27T11:58:00+00:00',
    },
];

const defaultProps = {
    events: {
        data: mockEvents,
        current_page: 1,
        last_page: 3,
        per_page: 50,
        total: 42,
        links: [],
    },
    totalCount: 1247,
    filters: { search: '', perPage: 50 },
};

function mountIndex(propsOverride = {}) {
    return mount(Index, {
        props: { ...defaultProps, ...propsOverride },
        global: {
            stubs: {
                AdminLayout: { template: '<div><slot /></div>' },
                Pagination: { template: '<div data-testid="pagination" />', props: ['paginator'] },
            },
        },
    });
}

describe('Events/Index', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders page title', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="page-title"]').text()).toBe('Event Feed');
    });

    it('renders DataTable with event rows', () => {
        const wrapper = mountIndex();
        const rows = wrapper.findAll('[data-testid="data-table-row"]');
        expect(rows).toHaveLength(3);
    });

    it('displays event type with correct text', () => {
        const wrapper = mountIndex();
        const types = wrapper.findAll('[data-testid="event-type"]');
        expect(types[0].text()).toBe('UserConnected');
        expect(types[1].text()).toBe('SwitchUnreachable');
    });

    it('displays event message', () => {
        const wrapper = mountIndex();
        const messages = wrapper.findAll('[data-testid="event-message"]');
        expect(messages[0].text()).toBe('alice connected from 10.0.0.42');
    });

    it('renders search input', () => {
        const wrapper = mountIndex();
        const input = wrapper.find('[data-testid="event-search"]');
        expect(input.exists()).toBe(true);
    });

    it('displays filtered and total counts', () => {
        const wrapper = mountIndex();
        const count = wrapper.find('[data-testid="event-count"]');
        expect(count.exists()).toBe(true);
        expect(count.text()).toContain('42');
        expect(count.text()).toContain('1,247');
    });

    it('renders pagination', () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="pagination"]').exists()).toBe(true);
    });

    it('renders empty state when no events', () => {
        const wrapper = mountIndex({
            events: { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0, links: [] },
            totalCount: 0,
        });
        expect(wrapper.find('[data-testid="event-empty"]').exists()).toBe(true);
    });

    it('applies danger color class for critical events', () => {
        const wrapper = mountIndex();
        const types = wrapper.findAll('[data-testid="event-type"]');
        expect(types[1].classes().join(' ')).toContain('danger');
    });

    it('applies warning color class for warning events', () => {
        const wrapper = mountIndex();
        const types = wrapper.findAll('[data-testid="event-type"]');
        expect(types[2].classes().join(' ')).toContain('warning');
    });

    it('shows new events banner when live events arrive', async () => {
        const wrapper = mountIndex();
        expect(wrapper.find('[data-testid="new-events-banner"]').exists()).toBe(false);

        wrapper.vm.addLiveEvent({ type: 'UserConnected', message: 'bob connected' });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="new-events-banner"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="new-events-banner"]').text()).toContain('1');
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run tests/js/Pages/Admin/Events/Index.spec.js`
Expected: FAIL — component doesn't exist

- [ ] **Step 3: Create EventFeedIcon**

Create `resources/js/Components/Icons/EventFeedIcon.vue`:

```vue
<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-4 w-4"
        aria-hidden="true"
    >
        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12" />
    </svg>
</template>
```

- [ ] **Step 4: Create Events/Index.vue**

Create `resources/js/Pages/Admin/Events/Index.vue`:

```vue
<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DataTable from '@/Components/UI/DataTable.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Pagination from '@/Components/UI/Pagination.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelativeTime } from '@/utils/dates';
import { useAdminChannel } from '@/composables/useAdminChannel';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    events: { type: Object, default: () => ({}) },
    totalCount: { type: Number, default: 0 },
    filters: { type: Object, default: () => ({}) },
});

const searchInput = ref(props.filters?.search ?? '');
let debounceTimer = null;

const rows = computed(() => props.events?.data ?? []);

const columns = [
    { key: 'created_at', label: 'Time' },
    { key: 'type', label: 'Event' },
    { key: 'message', label: 'Details' },
];

const liveEvents = ref([]);

function addLiveEvent(eventData) {
    const search = searchInput.value.toLowerCase().trim();
    const type = eventData.type ?? '';
    const message = eventData.message ?? '';

    if (search && !type.toLowerCase().includes(search) && !message.toLowerCase().includes(search)) {
        return;
    }

    liveEvents.value.push(eventData);
}

function reloadPage() {
    liveEvents.value = [];
    router.reload({ preserveScroll: true });
}

function onSearchInput(event) {
    searchInput.value = event.target.value;
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        liveEvents.value = [];
        router.get(
            route('admin.events.index'),
            { search: searchInput.value, perPage: props.filters?.perPage ?? 50 },
            { preserveState: true, preserveScroll: true },
        );
    }, 300);
}

function levelColorClass(level) {
    if (level === 'critical') return 'text-[var(--color-danger)] danger';
    if (level === 'warning') return 'text-[var(--color-warning)] warning';
    return 'text-[var(--color-primary)]';
}

function formatCount(n) {
    return n.toLocaleString('en-GB');
}

const EVENT_TYPES = [
    'UserConnected',
    'DeviceDiscovered',
    'PortStateChanged',
    'SwitchSyncCompleted',
    'DhcpPoolThresholdReached',
    'InternetAccessChanged',
    'RateLimitChanged',
    'UserBlocked',
    'DnsFilterChanged',
    'SwitchUnreachable',
    'BandwidthAnomalyDetected',
];

const channelEvents = {};
for (const eventType of EVENT_TYPES) {
    channelEvents[eventType] = (data) => {
        addLiveEvent({ type: eventType, ...data });
    };
}

const { connected } = useAdminChannel({ events: channelEvents });

defineExpose({ addLiveEvent });
</script>

<template>
    <div data-testid="event-feed-index-layout">
        <header class="mb-2 flex items-start justify-between gap-6">
            <div>
                <h1
                    data-testid="page-title"
                    class="font-heading text-[32px] leading-[1.1] font-bold tracking-[-0.03em] text-[var(--color-text)]"
                    style="font-variation-settings: 'opsz' 48"
                >
                    Event Feed
                </h1>
                <p class="mt-1 text-[13px] text-[var(--color-text-secondary)]">
                    Real-time system activity stream
                </p>
            </div>
            <span
                v-if="connected"
                data-testid="live-indicator"
                class="inline-flex items-center gap-1.5 text-[11px] font-semibold text-[var(--color-success)]"
            >
                <span
                    class="inline-block h-[7px] w-[7px] rounded-full bg-[var(--color-success)] shadow-[0_0_6px_var(--color-success)]"
                />
                Live
            </span>
        </header>

        <SectionHeader title="Events" class="mt-6" />

        <!-- Search + Count -->
        <div class="mb-3 flex flex-wrap items-center gap-3">
            <div class="relative max-w-[400px] min-w-[200px] flex-1">
                <label for="event-search" class="sr-only">Filter events</label>
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    class="absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-[var(--color-text-muted)]"
                >
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z"
                    />
                </svg>
                <input
                    id="event-search"
                    data-testid="event-search"
                    type="text"
                    placeholder="Filter events..."
                    :value="searchInput"
                    class="w-full rounded border border-[var(--color-border-hover)] bg-[var(--color-surface)] py-[7px] pr-3 pl-8 text-[13px] text-[var(--color-text)] transition-[border-color] duration-150 outline-none placeholder:text-[var(--color-text-muted)] focus:border-[var(--color-primary)]"
                    @input="onSearchInput"
                />
            </div>
            <span
                data-testid="event-count"
                class="ml-auto font-mono text-[11px] text-[var(--color-text-muted)]"
            >
                Showing {{ formatCount(events.total ?? 0) }} of {{ formatCount(totalCount) }} events
            </span>
        </div>

        <!-- New events banner -->
        <div
            v-if="liveEvents.length > 0"
            data-testid="new-events-banner"
            class="mb-3 flex items-center gap-2 rounded border border-[var(--color-success)]/20 bg-[var(--color-success)]/5 px-3 py-2"
        >
            <span
                class="inline-block h-[6px] w-[6px] rounded-full bg-[var(--color-success)]"
            />
            <span class="text-[12px] font-semibold text-[var(--color-success)]">
                {{ liveEvents.length }} new event{{ liveEvents.length === 1 ? '' : 's' }} received
            </span>
            <button
                data-testid="new-events-reload"
                class="ml-auto text-[12px] font-semibold text-[var(--color-success)] underline hover:no-underline"
                @click="reloadPage"
            >
                Reload
            </button>
        </div>

        <!-- Table -->
        <section data-testid="event-table-section">
            <DataTable
                v-if="rows.length > 0"
                :columns="columns"
                :rows="rows"
                empty-message="No events found."
            >
                <template #row="{ row }">
                    <td
                        data-testid="event-timestamp"
                        class="py-[10px] font-mono text-[12px] whitespace-nowrap text-[var(--color-text-muted)]"
                    >
                        {{ formatRelativeTime(row.created_at) }}
                    </td>
                    <td class="py-[10px]">
                        <span
                            data-testid="event-type"
                            :class="['text-[13px] font-semibold', levelColorClass(row.level)]"
                        >
                            {{ row.type }}
                        </span>
                    </td>
                    <td
                        data-testid="event-message"
                        class="py-[10px] text-[13px] text-[var(--color-text-secondary)]"
                    >
                        {{ row.message }}
                    </td>
                </template>
            </DataTable>

            <EmptyState
                v-else
                data-testid="event-empty"
                title="No events"
                description="System events will appear here as they occur."
            />
        </section>

        <Pagination :paginator="events" class="mt-4" />
    </div>
</template>
```

- [ ] **Step 5: Add sidebar nav entry**

Modify `resources/js/Components/Admin/Sidebar.vue` — add the import:

```js
import EventFeedIcon from '@/Components/Icons/EventFeedIcon.vue';
```

Update the SYSTEM group items array (around line 50):

```js
        items: [
            { label: 'Audit Log', href: route('admin.audit-log.index'), icon: AuditLogIcon },
            { label: 'Event Feed', href: route('admin.events.index'), icon: EventFeedIcon },
        ],
```

- [ ] **Step 6: Run Vitest to verify it passes**

Run: `npx vitest run tests/js/Pages/Admin/Events/Index.spec.js`
Expected: All tests PASS

- [ ] **Step 7: Run Pint and ESLint**

Run: `vendor/bin/pint --dirty --format agent`
Run: `npx eslint resources/js/Pages/Admin/Events/Index.vue resources/js/Components/Icons/EventFeedIcon.vue resources/js/Components/Admin/Sidebar.vue`

- [ ] **Step 8: Commit**

```bash
git add resources/js/Pages/Admin/Events/Index.vue resources/js/Components/Icons/EventFeedIcon.vue resources/js/Components/Admin/Sidebar.vue tests/js/Pages/Admin/Events/Index.spec.js
git commit -m "feat: add Event Feed page with search, live banner, and sidebar nav"
```

---

### Task 6: Refactor Dashboard EventFeed Widget

**Files:**
- Modify: `resources/js/Components/Admin/EventFeed.vue`
- Modify: `resources/js/Pages/Admin/Dashboard.vue`
- Modify: `app/Http/Controllers/Admin/HomeController.php`
- Test: `tests/js/Components/Admin/EventFeed.spec.js` (update existing)
- Test: `tests/js/Pages/Admin/Dashboard.spec.js` (update existing)
- Test: `tests/Feature/Admin/DashboardControllerTest.php` (update existing)

- [ ] **Step 1: Write failing PHP test for recentEvents prop**

Add to `tests/Feature/Admin/DashboardControllerTest.php` (or the existing HomeController test file — check which test covers the dashboard `index` route):

```php
public function test_dashboard_includes_recent_events_deferred_prop(): void
{
    Queue::fake();
    $admin = $this->createAdminUser();
    SystemEvent::factory()->count(15)->create();

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Dashboard')
        ->has('recentEvents')
    );
}
```

Add the import at top: `use App\Models\SystemEvent;`

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_dashboard_includes_recent_events_deferred_prop`
Expected: FAIL — `recentEvents` prop not present

- [ ] **Step 3: Add recentEvents to HomeController**

Modify `app/Http/Controllers/Admin/HomeController.php` — add the import:

```php
use App\Models\SystemEvent;
```

Add inside the `index` method's `Inertia::render` array, after `'recentUsers'`:

```php
            'recentEvents' => Inertia::defer(fn (): array => SystemEvent::query()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (SystemEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'level' => $event->level,
                    'message' => $event->message,
                    'created_at' => $event->created_at->toIso8601String(),
                ])
                ->all()),
```

- [ ] **Step 4: Run PHP test to verify it passes**

Run: `php artisan test --compact --filter=test_dashboard_includes_recent_events_deferred_prop`
Expected: PASS

- [ ] **Step 5: Refactor EventFeed.vue component**

Replace the full content of `resources/js/Components/Admin/EventFeed.vue`:

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelativeTime } from '@/utils/dates';

defineProps({
    events: { type: Array, default: () => [] },
});
</script>

<template>
    <div data-testid="event-feed">
        <div class="flex items-baseline justify-between">
            <SectionHeader title="Event Feed" />
            <Link
                :href="route('admin.events.index')"
                data-testid="event-feed-view-all"
                class="text-[12px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
            >
                View All &rarr;
            </Link>
        </div>

        <div data-testid="event-feed-scroll" class="max-h-[400px] overflow-y-auto">
            <div v-if="events.length === 0" data-testid="event-feed-empty" class="py-6 text-center">
                <p class="text-[11px] text-[var(--color-text-muted)]">No recent events</p>
            </div>

            <div v-else class="space-y-0">
                <div
                    v-for="event in events"
                    :key="event.id"
                    data-testid="event-feed-entry"
                    class="flex items-start gap-3 border-b border-[var(--color-border)] px-1 py-2 last:border-b-0"
                >
                    <span
                        data-testid="event-feed-timestamp"
                        class="shrink-0 font-mono text-[11px] text-[var(--color-text-muted)]"
                    >
                        {{ formatRelativeTime(event.created_at) }}
                    </span>
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ event.message }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 6: Update Dashboard.vue to wire EventFeed with data**

Modify `resources/js/Pages/Admin/Dashboard.vue`:

Add `recentEvents` to the props:

```js
    recentEvents: { type: Array, default: undefined },
```

Add a reactive `eventFeedItems` ref and event handler logic after the existing `useAdminChannel` setup. Replace the existing `useAdminChannel` call with an expanded version:

```js
const eventFeedItems = ref([]);

const EVENT_FORMATTERS = {
    UserConnected: (data) => `${data.user_name} connected from ${data.ip_address}`,
    DeviceDiscovered: (data) => {
        const location = data.ip_address ? ` on ${data.ip_address}` : '';
        return `New device ${data.mac_address} discovered${location}`;
    },
    PortStateChanged: (data) => `Port ${data.port_name} changed to ${data.new_status}`,
    SwitchSyncCompleted: (data) => {
        const base = `Switch ${data.hostname} sync completed (${data.ports_updated} ports updated)`;
        return data.errors?.length ? `${base} - ${data.errors.length} errors` : base;
    },
    DhcpPoolThresholdReached: (data) => `DHCP pool ${data.pool} reached ${data.usage}% utilization`,
    InternetAccessChanged: (data) => `Internet access ${data.enabled ? 'enabled' : 'disabled'} for ${data.ip_address}`,
    RateLimitChanged: (data) => `Rate limit changed for ${data.ip_address} from ${data.old_limit} to ${data.new_limit}`,
    UserBlocked: (data) => `${data.user_name} blocked on ${data.ip_address}: ${data.reason}`,
    DnsFilterChanged: (data) => `DNS filter ${data.enabled ? 'enabled' : 'disabled'} for ${data.ip_address}`,
    SwitchUnreachable: (data) => `Switch ${data.hostname} unreachable after ${data.failure_count} failures`,
    BandwidthAnomalyDetected: (data) => {
        const parts = ['Bandwidth anomaly detected'];
        if (data.ip_address) parts.push(`on ${data.ip_address}`);
        return parts.join(' ');
    },
};

function addEventFeedItem(type, data) {
    const entry = {
        id: Date.now() + Math.random(),
        type,
        message: EVENT_FORMATTERS[type]?.(data) ?? `${type} event received`,
        created_at: new Date().toISOString(),
    };
    eventFeedItems.value.unshift(entry);
    if (eventFeedItems.value.length > 50) {
        eventFeedItems.value = eventFeedItems.value.slice(0, 50);
    }
}

function handleBroadcastEvent(type) {
    return (data) => {
        refreshDashboard();
        addEventFeedItem(type, data);
    };
}
```

Replace the existing `useAdminChannel` call:

```js
useAdminChannel({
    events: {
        UserConnected: handleBroadcastEvent('UserConnected'),
        DeviceDiscovered: handleBroadcastEvent('DeviceDiscovered'),
        DhcpPoolThresholdReached: handleBroadcastEvent('DhcpPoolThresholdReached'),
        PortStateChanged: handleBroadcastEvent('PortStateChanged'),
        SwitchSyncCompleted: handleBroadcastEvent('SwitchSyncCompleted'),
        InternetAccessChanged: handleBroadcastEvent('InternetAccessChanged'),
        RateLimitChanged: handleBroadcastEvent('RateLimitChanged'),
        UserBlocked: handleBroadcastEvent('UserBlocked'),
        DnsFilterChanged: handleBroadcastEvent('DnsFilterChanged'),
        SwitchUnreachable: handleBroadcastEvent('SwitchUnreachable'),
        BandwidthAnomalyDetected: handleBroadcastEvent('BandwidthAnomalyDetected'),
    },
    poll: fetchBandwidth,
    pollInterval: 30000,
});
```

Add a `watch` to initialize `eventFeedItems` from the deferred prop. Add `watch` to imports:

```js
import { computed, ref, watch, onMounted } from 'vue';
```

After the `useAdminChannel` call:

```js
watch(
    () => props.recentEvents,
    (newEvents) => {
        if (newEvents && eventFeedItems.value.length === 0) {
            eventFeedItems.value = [...newEvents];
        }
    },
    { immediate: true },
);
```

Update the EventFeed template — change the existing `<EventFeed data-testid="event-feed" />` to:

```html
        <!-- Live Event Feed -->
        <div class="mt-10">
            <EventFeed :events="eventFeedItems" data-testid="event-feed" />
        </div>
```

- [ ] **Step 7: Update EventFeed.spec.js**

Read the existing `tests/js/Components/Admin/EventFeed.spec.js` and rewrite it to test the new prop-driven interface:

```js
import { mount } from '@vue/test-utils';
import { describe, it, expect, vi } from 'vitest';
import EventFeed from '@/Components/Admin/EventFeed.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

vi.mock('@/utils/dates', () => ({
    formatRelativeTime: vi.fn((v) => v ?? '—'),
}));

globalThis.route = (...args) => `/mocked/${args[0]}`;

const mockEvents = [
    { id: 1, type: 'UserConnected', message: 'alice connected from 10.0.0.42', created_at: '2026-04-27T12:00:00+00:00' },
    { id: 2, type: 'SwitchUnreachable', message: 'Switch down', created_at: '2026-04-27T11:59:00+00:00' },
];

function mountFeed(props = {}) {
    return mount(EventFeed, {
        props: { events: mockEvents, ...props },
        global: {
            stubs: {
                SectionHeader: { template: '<div />' },
            },
        },
    });
}

describe('EventFeed', () => {
    it('renders event entries from props', () => {
        const wrapper = mountFeed();
        const entries = wrapper.findAll('[data-testid="event-feed-entry"]');
        expect(entries).toHaveLength(2);
    });

    it('displays event messages', () => {
        const wrapper = mountFeed();
        const entries = wrapper.findAll('[data-testid="event-feed-entry"]');
        expect(entries[0].text()).toContain('alice connected from 10.0.0.42');
    });

    it('shows empty state when no events', () => {
        const wrapper = mountFeed({ events: [] });
        expect(wrapper.find('[data-testid="event-feed-empty"]').exists()).toBe(true);
    });

    it('renders View All link', () => {
        const wrapper = mountFeed();
        const link = wrapper.find('[data-testid="event-feed-view-all"]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('View All');
    });

    it('renders timestamps', () => {
        const wrapper = mountFeed();
        const timestamps = wrapper.findAll('[data-testid="event-feed-timestamp"]');
        expect(timestamps).toHaveLength(2);
    });
});
```

- [ ] **Step 8: Run all related tests**

Run: `npx vitest run tests/js/Components/Admin/EventFeed.spec.js tests/js/Pages/Admin/Dashboard.spec.js`
Expected: All tests PASS

If Dashboard.spec.js fails because it doesn't provide `recentEvents` prop — that's fine, it defaults to `undefined` and the watch handles it gracefully.

- [ ] **Step 9: Run Pint and ESLint**

Run: `vendor/bin/pint --dirty --format agent`
Run: `npx eslint resources/js/Components/Admin/EventFeed.vue resources/js/Pages/Admin/Dashboard.vue`

- [ ] **Step 10: Commit**

```bash
git add resources/js/Components/Admin/EventFeed.vue resources/js/Pages/Admin/Dashboard.vue app/Http/Controllers/Admin/HomeController.php tests/js/Components/Admin/EventFeed.spec.js tests/Feature/Admin/DashboardControllerTest.php
git commit -m "feat: refactor EventFeed widget with server data and dashboard integration"
```

---

### Task 7: Frontend Build & Full Test Suite

**Files:** No new files — validation only.

- [ ] **Step 1: Build frontend**

Run: `npm run build`
Expected: Build succeeds without errors.

- [ ] **Step 2: Run full Vitest suite**

Run: `npx vitest run`
Expected: All tests pass. If any Sidebar tests fail because `admin.events.index` route isn't mocked, add `globalThis.route` mock or stub the Sidebar in those tests.

- [ ] **Step 3: Run full PHP test suite**

Run: `php artisan test --compact`
Expected: All tests pass.

- [ ] **Step 4: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: 0 errors.

- [ ] **Step 5: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: No changes needed.

- [ ] **Step 6: Run ESLint + Prettier**

Run: `npm run lint && npm run format:check`
Expected: No errors.

- [ ] **Step 7: Commit any fixups**

If any linting/formatting changes were needed:

```bash
git add -A
git commit -m "chore: lint and format fixes for event feed feature"
```
