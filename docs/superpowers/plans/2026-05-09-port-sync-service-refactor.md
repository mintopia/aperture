# PortSyncService Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Two improvements to PortSyncService: (1) move SSH/network I/O outside the DB transaction so locks are not held during network calls; (2) decompose the 510-line god class into focused single-responsibility collaborators.

**Architecture:** Extract `SyncRunTracker`, `PortStatusSync`, `PortConfigSync`, `PortMacSync` from `PortSyncService`. The original class becomes a thin orchestrator. Phase 1 is a pure behaviour-preserving refactor; all existing tests must pass throughout.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, DB transactions, PHPUnit (parallel), Laravel Pint, PHPStan Level 8

---

## Phase 1: Separate DB transaction from network I/O

**Problem:** `syncSwitch()` line 51 opens `DB::transaction()` wrapping all SSH calls (`getAllPorts()`, `syncPortConfigs()`, `syncPortMacs()`). This holds a DB lock for the full duration of slow SSH commands.

**Files:**
- Modify: `app/Services/NetworkSwitch/PortSyncService.php`
- Create: `tests/Unit/Services/NetworkSwitch/PortSyncServiceTransactionTest.php`

- [ ] **Step 1.1: Write failing test**

Create `tests/Unit/Services/NetworkSwitch/PortSyncServiceTransactionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Services\NetworkSwitch\PortSyncService;
use App\Services\NetworkSwitch\SwitchServiceFactory;
use App\Services\Interfaces\NetworkSwitchInterface;
use App\Services\ValueObjects\SwitchPort as PortStatus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PortSyncServiceTransactionTest extends TestCase
{
    public function test_network_calls_happen_before_transaction_opens(): void
    {
        $callOrder = [];

        $adapter = $this->createMock(NetworkSwitchInterface::class);
        $adapter->method('getAllPorts')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'getAllPorts';
            return [];
        });
        $adapter->method('getMacTable')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'getMacTable';
            return [];
        });

        DB::shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use (&$callOrder) {
            $callOrder[] = 'transaction_open';
            $callback();
            $callOrder[] = 'transaction_close';
        });

        $factory = $this->createMock(SwitchServiceFactory::class);
        $factory->method('make')->willReturn($adapter);

        $switchConfig = SwitchConfig::factory()->make(['id' => 1]);

        $service = new PortSyncService($factory);
        $service->syncSwitch($switchConfig);

        // All network calls must precede the transaction
        $transactionIndex = array_search('transaction_open', $callOrder);
        $portsIndex = array_search('getAllPorts', $callOrder);
        $macsIndex = array_search('getMacTable', $callOrder);

        $this->assertLessThan($transactionIndex, $portsIndex,
            'getAllPorts() must be called before the DB transaction opens');
        $this->assertLessThan($transactionIndex, $macsIndex,
            'getMacTable() must be called before the DB transaction opens');
    }
}
```

- [ ] **Step 1.2: Run to verify it fails**

```bash
php artisan test --filter PortSyncServiceTransactionTest
```

Expected: FAIL — `getAllPorts` called inside transaction.

- [ ] **Step 1.3: Refactor syncSwitch() to fetch network data first**

In `app/Services/NetworkSwitch/PortSyncService.php`, update `syncSwitch()`:

```php
public function syncSwitch(SwitchConfig $switchConfig): SyncResult
{
    $this->cleanStaleRuns($switchConfig);

    $syncStartedAt = now();

    $syncRun = SwitchSyncRun::create([
        'switch_config_id' => $switchConfig->id,
        'status' => 'running',
        'started_at' => $syncStartedAt,
    ]);

    /** @var array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}> $portStateChanges */
    $portStateChanges = [];

    try {
        $adapter = $this->factory->make($switchConfig);

        // ── Fetch all network data BEFORE opening a DB transaction ──────────
        $portStatuses = $adapter->getAllPorts();
        $portConfigs  = $this->fetchPortConfigs($adapter, $switchConfig);
        $macEntries   = $adapter->getMacTable();
        // ────────────────────────────────────────────────────────────────────

        $portsCreated = 0;
        $portsUpdated = 0;
        $macsCreated = 0;
        $macsUpdated = 0;

        DB::transaction(function () use (
            $adapter, $switchConfig, $syncStartedAt,
            $portStatuses, $portConfigs, $macEntries,
            &$portsCreated, &$portsUpdated, &$macsCreated, &$macsUpdated,
            &$portStateChanges
        ): void {
            $portStateChanges = $this->syncPortStatusesFromData($portStatuses, $switchConfig, $syncStartedAt, $portsCreated, $portsUpdated);
            $this->syncPortConfigsFromData($portConfigs, $switchConfig, $syncStartedAt);
            $syncedMacIds = $this->syncPortMacsFromData($macEntries, $switchConfig, $syncStartedAt, $macsCreated, $macsUpdated);
            $this->cleanStaleMacs($switchConfig, $syncedMacIds);
        });

        $syncRun->update([
            'status' => 'completed',
            'finished_at' => now(),
            'ports_created' => $portsCreated,
            'ports_updated' => $portsUpdated,
            'macs_created' => $macsCreated,
            'macs_updated' => $macsUpdated,
        ]);
    } catch (Throwable $throwable) {
        $syncRun->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error' => $throwable->getMessage(),
        ]);

        throw $throwable;
    } finally {
        $syncRun->refresh();

        if ($syncRun->status === 'running') {
            $syncRun->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);
        }
    }

    $this->dispatchStateChangeEvents($portStateChanges);

    return new SyncResult(
        portsCreated: $portsCreated ?? 0,
        portsUpdated: $portsUpdated ?? 0,
        macsCreated: $macsCreated ?? 0,
        macsUpdated: $macsUpdated ?? 0,
    );
}
```

Add a `fetchPortConfigs()` helper that delegates to `syncPortConfigs()` but returns the raw data instead of persisting — or simply call the existing methods by passing pre-fetched data. Read the existing `syncPortConfigs()` implementation to understand what data it fetches from the adapter and restructure accordingly.

- [ ] **Step 1.4: Fix N+1 inside syncPortStatuses**

In the loop in `syncPortStatuses()` (line ~127), a `SwitchPort::where(...)->first()` query runs per port. Pre-load all ports for the switch before the loop:

```php
// Before the foreach:
$existingPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
    ->get()
    ->keyBy('port_name');

// In the loop, replace:
$existingPort = $existingPorts->get($portStatus->interface);
```

- [ ] **Step 1.5: Run tests**

```bash
php artisan test --filter PortSyncService
XDEBUG_MODE=coverage php artisan test --parallel
./vendor/bin/pint app/Services/NetworkSwitch/PortSyncService.php
./vendor/bin/phpstan analyse --no-progress
```

Expected: All pass.

- [ ] **Step 1.6: Commit**

```bash
git add app/Services/NetworkSwitch/PortSyncService.php tests/Unit/Services/NetworkSwitch/PortSyncServiceTransactionTest.php
git commit -m "perf: fetch switch data before DB transaction in PortSyncService

SSH/network calls (getAllPorts, getMacTable, port configs) now complete
before DB::transaction() opens, preventing long-held DB locks during
slow SSH operations. Also fixes N+1 in syncPortStatuses() by pre-loading
all ports keyed by port_name.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Phase 2: Decompose PortSyncService into focused collaborators

**Problem:** At 510 lines, `PortSyncService` has too many responsibilities. Extract into:

- `SyncRunTracker` — `SwitchSyncRun` model lifecycle
- `PortStatusSync` — sync port operational statuses from pre-fetched data
- `PortConfigSync` — sync port running configs from pre-fetched data
- `PortMacSync` — sync MAC address table from pre-fetched data

`PortSyncService` becomes a thin orchestrator.

**Files:**
- Create: `app/Services/NetworkSwitch/SyncRunTracker.php`
- Create: `app/Services/NetworkSwitch/PortStatusSync.php`
- Create: `app/Services/NetworkSwitch/PortConfigSync.php`
- Create: `app/Services/NetworkSwitch/PortMacSync.php`
- Modify: `app/Services/NetworkSwitch/PortSyncService.php`
- Create: `tests/Unit/Services/NetworkSwitch/SyncRunTrackerTest.php`
- Create: `tests/Unit/Services/NetworkSwitch/PortStatusSyncTest.php`
- Create: `tests/Unit/Services/NetworkSwitch/PortMacSyncTest.php`

### Task 2a: Extract SyncRunTracker

- [ ] **Step 2a.1: Write failing test**

Create `tests/Unit/Services/NetworkSwitch/SyncRunTrackerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;
use App\Services\NetworkSwitch\SyncRunTracker;
use Tests\TestCase;

class SyncRunTrackerTest extends TestCase
{
    public function test_start_creates_running_sync_run(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $tracker = new SyncRunTracker();

        $run = $tracker->start($switchConfig);

        $this->assertInstanceOf(SwitchSyncRun::class, $run);
        $this->assertSame('running', $run->status);
        $this->assertSame($switchConfig->id, $run->switch_config_id);
    }

    public function test_complete_updates_status_and_counters(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $tracker = new SyncRunTracker();
        $run = $tracker->start($switchConfig);

        $tracker->complete($run, portsCreated: 2, portsUpdated: 5, macsCreated: 10, macsUpdated: 3);
        $run->refresh();

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->ports_created);
        $this->assertSame(5, $run->ports_updated);
        $this->assertNotNull($run->finished_at);
    }

    public function test_fail_updates_status_and_records_error(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $tracker = new SyncRunTracker();
        $run = $tracker->start($switchConfig);

        $tracker->fail($run, 'Connection refused');
        $run->refresh();

        $this->assertSame('failed', $run->status);
        $this->assertSame('Connection refused', $run->error);
    }
}
```

- [ ] **Step 2a.2: Run to verify it fails**

```bash
php artisan test --filter SyncRunTrackerTest
```

Expected: FAIL — class not found.

- [ ] **Step 2a.3: Create SyncRunTracker**

Create `app/Services/NetworkSwitch/SyncRunTracker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchSyncRun;

class SyncRunTracker
{
    public function start(SwitchConfig $switchConfig): SwitchSyncRun
    {
        return SwitchSyncRun::create([
            'switch_config_id' => $switchConfig->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function complete(
        SwitchSyncRun $run,
        int $portsCreated,
        int $portsUpdated,
        int $macsCreated,
        int $macsUpdated,
    ): void {
        $run->update([
            'status' => 'completed',
            'finished_at' => now(),
            'ports_created' => $portsCreated,
            'ports_updated' => $portsUpdated,
            'macs_created' => $macsCreated,
            'macs_updated' => $macsUpdated,
        ]);
    }

    public function fail(SwitchSyncRun $run, string $error): void
    {
        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error' => $error,
        ]);
    }
}
```

- [ ] **Step 2a.4: Run tests**

```bash
php artisan test --filter SyncRunTrackerTest
./vendor/bin/pint app/Services/NetworkSwitch/SyncRunTracker.php
```

Expected: PASS.

- [ ] **Step 2a.5: Commit**

```bash
git add app/Services/NetworkSwitch/SyncRunTracker.php tests/Unit/Services/NetworkSwitch/SyncRunTrackerTest.php
git commit -m "refactor: extract SyncRunTracker from PortSyncService

Manages SwitchSyncRun lifecycle (start, complete, fail). PortSyncService
will delegate to this collaborator in a follow-up commit.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

### Task 2b: Extract PortStatusSync

- [ ] **Step 2b.1: Write failing test**

Create `tests/Unit/Services/NetworkSwitch/PortStatusSyncTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use App\Services\NetworkSwitch\PortStatusSync;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PortStatusSyncTest extends TestCase
{
    public function test_creates_new_port_when_not_existing(): void
    {
        $switchConfig = SwitchConfig::factory()->create();
        $sync = new PortStatusSync();

        // Use an actual PortStatus value object matching what getAllPorts() returns
        // Read App\Services\ValueObjects\SwitchPort to get correct constructor
        $portStatus = new \App\Services\ValueObjects\SwitchPort(
            interface: 'Gi1/0/1',
            status: 'connected',
            adminStatus: 'enabled',
            speed: '1000',
            duplex: 'full',
            description: 'Test port',
            vlan: '10',
            switchportMode: 'access',
        );

        $result = $sync->sync([$portStatus], $switchConfig, Carbon::now());

        $this->assertDatabaseHas('switch_ports', [
            'switch_config_id' => $switchConfig->id,
            'port_name' => 'Gi1/0/1',
            'status' => 'connected',
        ]);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
    }
}
```

- [ ] **Step 2b.2: Run to verify it fails**

```bash
php artisan test --filter PortStatusSyncTest
```

- [ ] **Step 2b.3: Create PortStatusSync**

Read `app/Services/ValueObjects/SwitchPort.php` first to confirm constructor signature:

```bash
cat app/Services/ValueObjects/SwitchPort.php 2>/dev/null || find app/ -name "SwitchPort.php" | head -3
```

Create `app/Services/NetworkSwitch/PortStatusSync.php` by extracting the `syncPortStatusesFromData()` logic from `PortSyncService`:

```php
<?php

declare(strict_types=1);

namespace App\Services\NetworkSwitch;

use App\Models\SwitchConfig;
use App\Models\SwitchPort;
use Illuminate\Support\Carbon;

class PortStatusSync
{
    /**
     * @param  array<int, \App\Services\ValueObjects\SwitchPort>  $portStatuses
     * @return array{created: int, updated: int, stateChanges: array<int, array{switchPort: SwitchPort, oldStatus: ?string, newStatus: string}>}
     */
    public function sync(array $portStatuses, SwitchConfig $switchConfig, Carbon $syncStartedAt): array
    {
        $existingPorts = SwitchPort::where('switch_config_id', $switchConfig->id)
            ->get()
            ->keyBy('port_name');

        $created = 0;
        $updated = 0;
        $stateChanges = [];

        foreach ($portStatuses as $portStatus) {
            $accessVlan = $portStatus->vlan !== '' ? (int) $portStatus->vlan : null;
            $switchportMode = $portStatus->switchportMode !== '' ? $portStatus->switchportMode : null;
            $description = $portStatus->description !== '' ? $portStatus->description : null;

            $existingPort = $existingPorts->get($portStatus->interface);

            if ($existingPort instanceof SwitchPort) {
                $oldStatus = $existingPort->status;

                $existingPort->update([
                    'status' => $portStatus->status,
                    'admin_status' => $portStatus->adminStatus,
                    'speed' => $portStatus->speed,
                    'duplex' => $portStatus->duplex,
                    'access_vlan' => $accessVlan,
                    'switchport_mode' => $switchportMode,
                    'switch_description' => $description,
                    'last_synced_at' => $syncStartedAt,
                ]);
                $updated++;

                if ($oldStatus !== $portStatus->status) {
                    $stateChanges[] = [
                        'switchPort' => $existingPort,
                        'oldStatus' => $oldStatus,
                        'newStatus' => $portStatus->status,
                    ];
                }
            } else {
                SwitchPort::create([
                    'switch_config_id' => $switchConfig->id,
                    'port_name' => $portStatus->interface,
                    'port_number' => $portStatus->interface,
                    'status' => $portStatus->status,
                    'admin_status' => $portStatus->adminStatus,
                    'speed' => $portStatus->speed,
                    'duplex' => $portStatus->duplex,
                    'access_vlan' => $accessVlan,
                    'switchport_mode' => $switchportMode,
                    'switch_description' => $description,
                    'last_synced_at' => $syncStartedAt,
                ]);
                $created++;
            }
        }

        return compact('created', 'updated', 'stateChanges');
    }
}
```

- [ ] **Step 2b.4: Run tests**

```bash
php artisan test --filter PortStatusSyncTest
./vendor/bin/pint app/Services/NetworkSwitch/PortStatusSync.php
./vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 2b.5: Commit**

```bash
git add app/Services/NetworkSwitch/PortStatusSync.php tests/Unit/Services/NetworkSwitch/PortStatusSyncTest.php
git commit -m "refactor: extract PortStatusSync from PortSyncService

Port status upsert logic now lives in a focused, testable collaborator.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

### Task 2c: Extract PortMacSync

- [ ] **Step 2c.1: Write test for PortMacSync**

Read `app/Services/NetworkSwitch/PortSyncService.php` lines 283–400 to understand `syncPortMacs()` logic, then create `tests/Unit/Services/NetworkSwitch/PortMacSyncTest.php` with a test that:
- Creates a SwitchConfig and SwitchPort
- Calls `PortMacSync::sync()` with a MAC table entry pointing to the port
- Asserts a `SwitchPortMac` record is created

- [ ] **Step 2c.2: Run to verify it fails**

```bash
php artisan test --filter PortMacSyncTest
```

- [ ] **Step 2c.3: Create PortMacSync**

Extract `syncPortMacs()` and `cleanStaleMacs()` from `PortSyncService` into `app/Services/NetworkSwitch/PortMacSync.php`.

- [ ] **Step 2c.4: Run tests and commit**

```bash
php artisan test --filter PortMacSync
./vendor/bin/pint app/Services/NetworkSwitch/PortMacSync.php
git add app/Services/NetworkSwitch/PortMacSync.php tests/Unit/Services/NetworkSwitch/PortMacSyncTest.php
git commit -m "refactor: extract PortMacSync from PortSyncService

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

### Task 2d: Wire PortSyncService to use collaborators

- [ ] **Step 2d.1: Update PortSyncService**

Replace the inline logic in `syncSwitch()` with calls to the extracted collaborators via constructor injection:

```php
class PortSyncService
{
    public function __construct(
        protected SwitchServiceFactory $factory,
        protected SyncRunTracker $runTracker,
        protected PortStatusSync $portStatusSync,
        protected PortMacSync $portMacSync,
    ) {}
}
```

- [ ] **Step 2d.2: Register in service container if needed**

Check if `PortSyncService` is bound in any service provider. Laravel auto-wires constructor injection for unbound classes, so this may require no changes. If bound manually, update the binding.

```bash
grep -r "PortSyncService" app/Providers/ --include="*.php"
```

- [ ] **Step 2d.3: Run full test suite**

```bash
XDEBUG_MODE=coverage php artisan test --parallel
./vendor/bin/pint
./vendor/bin/phpstan analyse --no-progress
```

Expected: All pass.

- [ ] **Step 2d.4: Final commit**

```bash
git add app/Services/NetworkSwitch/PortSyncService.php
git commit -m "refactor: wire PortSyncService to delegate to extracted collaborators

PortSyncService is now a thin orchestrator. SyncRunTracker, PortStatusSync,
and PortMacSync handle single responsibilities via constructor injection.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```
