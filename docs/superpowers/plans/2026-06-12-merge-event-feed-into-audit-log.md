# Collapse SystemEvent into AuditLog — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the `SystemEvent` subsystem and make `AuditLog` the single activity store, with the dashboard "Recent Activity" widget reading live from audit logs.

**Architecture:** `AuditLog::record()` becomes the one choke point: it gains a `severity` column and broadcasts an `AuditLogRecorded` event on the existing `admin.events` private channel. The wildcard `RecordBroadcastEvent` listener stops writing `SystemEvent`s and instead translates the three live, unaudited telemetry events (`SwitchUnreachable`, `BandwidthAnomalyDetected`, `DhcpPoolThresholdReached`) into audit entries. The dashboard widget renders audit rows with a severity dot and updates live via the single broadcast.

**Tech Stack:** Laravel (PHP 8.3, PHPUnit parallel, SQLite test DB, XDEBUG_MODE=coverage), Inertia + Vue 3, Laravel Echo/Pusher, vitest. Gates: 100% coverage, Pint, PHPStan level 8, Rector (Laravel ruleset), eslint/prettier, impeccable `audit` for UI.

**Project test quirk:** `php artisan test` output must be redirected to a file (a mock server in the suite pollutes stdout). All test commands below redirect to `/tmp/aperture-test.log` then `tail` it.

---

## File Structure

**Backend — create:**
- `app/Events/AuditLogRecorded.php` — broadcast event fired by `AuditLog::record()`.
- `database/migrations/2026_06_12_000001_add_severity_to_audit_logs_table.php` — adds `severity`.
- `database/migrations/2026_06_12_000002_drop_system_events_table.php` — drops `system_events`.

**Backend — modify:**
- `app/Models/AuditLog.php` — `severity` in fillable + `@property`; `record()` gains `$severity` param and dispatches `AuditLogRecorded`.
- `app/Listeners/RecordBroadcastEvent.php` — rewrite: translate 3 telemetry events to audit logs; drop `SystemEvent`.
- `app/Services/AuditLog/AuditLogDescriptionGenerator.php` — 3 new action cases.
- `app/Http/Controllers/Admin/HomeController.php` — seed `recentEvents` from `AuditLog`; drop `SystemEvent` import.
- `routes/web.php` — remove the `events.index` route and `EventController` import.

**Backend — delete:**
- `app/Models/SystemEvent.php`, `database/factories/SystemEventFactory.php`,
  `app/Http/Controllers/Admin/EventController.php`,
  `app/Console/Commands/PruneSystemEventsCommand.php`,
  `database/migrations/2026_04_27_002100_create_system_events_table.php`.
- Tests: `tests/Unit/Models/SystemEventTest.php`,
  `tests/Feature/Admin/EventControllerTest.php`,
  `tests/Feature/Commands/PruneSystemEventsCommandTest.php`.

**Frontend — create:**
- `resources/js/Components/Admin/RecentActivity.vue` (replaces `EventFeed.vue`).
- `resources/js/Components/Admin/__tests__/RecentActivity.test.js`.

**Frontend — modify:**
- `resources/js/Pages/Admin/Dashboard.vue` — use `RecentActivity`, subscribe to `AuditLogRecorded`, drop the per-event formatters.

**Frontend — delete:**
- `resources/js/Components/Admin/EventFeed.vue` (+ any existing test),
  `resources/js/Pages/Admin/Events/Index.vue` (+ `__tests__`).

---

## Task 1: Add `severity` column to `audit_logs`

**Files:**
- Create: `database/migrations/2026_06_12_000001_add_severity_to_audit_logs_table.php`
- Modify: `app/Models/AuditLog.php`
- Test: `tests/Unit/Models/AuditLogTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Models/AuditLogTest.php`:

```php
public function test_record_persists_severity_and_defaults_to_info(): void
{
    $explicit = AuditLog::record(action: 'switch.unreachable', severity: 'critical');
    $defaulted = AuditLog::record(action: 'user.login');

    $this->assertSame('critical', $explicit->severity);
    $this->assertSame('info', $defaulted->severity);
    $this->assertDatabaseHas('audit_logs', ['action' => 'switch.unreachable', 'severity' => 'critical']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.login', 'severity' => 'info']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_record_persists_severity_and_defaults_to_info > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: FAIL — unknown column `severity` / unknown named argument `$severity`.

- [ ] **Step 3: Create the migration**

`database/migrations/2026_06_12_000001_add_severity_to_audit_logs_table.php`:

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
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('severity')->default('info')->after('process');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('severity');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Models/AuditLog.php` add `* @property string $severity` to the docblock (after `$process`), add `'severity'` to `$fillable` (after `'process'`), and change `record()` to accept and persist severity:

```php
    public static function record(
        string $action,
        ?Model $subject = null,
        ?Model $related = null,
        ?Model $actor = null,
        string $process = 'system',
        ?array $metadata = null,
        string $severity = 'info',
    ): self {
        return self::create([
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'process' => $process,
            'metadata' => $metadata,
            'severity' => $severity,
        ]);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=test_record_persists_severity_and_defaults_to_info > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_12_000001_add_severity_to_audit_logs_table.php app/Models/AuditLog.php tests/Unit/Models/AuditLogTest.php
git commit -m "feat(audit): add severity column to audit logs"
```

---

## Task 2: `AuditLogRecorded` broadcast event, dispatched from `record()`

**Files:**
- Create: `app/Events/AuditLogRecorded.php`
- Modify: `app/Models/AuditLog.php`
- Test: `tests/Feature/Events/AuditLogRecordedTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Events/AuditLogRecordedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Events\AuditLogRecorded;
use App\Models\AuditLog;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AuditLogRecordedTest extends TestCase
{
    public function test_record_dispatches_broadcast_event(): void
    {
        Event::fake([AuditLogRecorded::class]);

        $log = AuditLog::record(action: 'user.login', severity: 'info');

        Event::assertDispatched(AuditLogRecorded::class, fn (AuditLogRecorded $e): bool => $e->log->is($log));
    }

    public function test_event_is_broadcastable_with_expected_payload(): void
    {
        $log = AuditLog::record(action: 'dhcp.threshold_reached', severity: 'warning', metadata: ['pool' => 'lan']);

        $event = new AuditLogRecorded($log);
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame($log->id, $payload['id']);
        $this->assertSame('dhcp.threshold_reached', $payload['action']);
        $this->assertSame('warning', $payload['severity']);
        $this->assertArrayHasKey('description', $payload);
        $this->assertArrayHasKey('created_at', $payload);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuditLogRecordedTest > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: FAIL — class `App\Events\AuditLogRecorded` not found.

- [ ] **Step 3: Create the event**

`app/Events/AuditLogRecorded.php`:

```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AuditLog;
use App\Services\AuditLog\AuditLogDescriptionGenerator;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class AuditLogRecorded implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(public AuditLog $log) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.events'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'action' => $this->log->action,
            'description' => AuditLogDescriptionGenerator::generate($this->log),
            'severity' => $this->log->severity,
            'created_at' => $this->log->created_at->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Dispatch from `record()`**

In `app/Models/AuditLog.php`, import the event (`use App\Events\AuditLogRecorded;`) and change `record()` to capture the row and dispatch before returning:

```php
        $log = self::create([
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'process' => $process,
            'metadata' => $metadata,
            'severity' => $severity,
        ]);

        AuditLogRecorded::dispatch($log);

        return $log;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AuditLogRecordedTest > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Events/AuditLogRecorded.php app/Models/AuditLog.php tests/Feature/Events/AuditLogRecordedTest.php
git commit -m "feat(audit): broadcast AuditLogRecorded from AuditLog::record"
```

---

## Task 3: Description generator cases for the 3 telemetry actions

**Files:**
- Modify: `app/Services/AuditLog/AuditLogDescriptionGenerator.php`
- Test: `tests/Unit/Services/AuditLog/AuditLogDescriptionGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/Services/AuditLog/AuditLogDescriptionGeneratorTest.php` (use the file's existing `makeLog` helper):

```php
public function test_describes_switch_unreachable(): void
{
    $switch = SwitchConfig::factory()->create(['name' => 'core-sw']);
    $log = $this->makeLog('switch.unreachable', subject: $switch, metadata: ['failure_count' => 3]);

    $this->assertSame('Switch core-sw unreachable after 3 failures', AuditLogDescriptionGenerator::generate($log));
}

public function test_describes_bandwidth_anomaly(): void
{
    $log = $this->makeLog('bandwidth.anomaly', metadata: ['ip_address' => '10.0.0.5']);

    $this->assertSame('Bandwidth anomaly detected on 10.0.0.5', AuditLogDescriptionGenerator::generate($log));
}

public function test_describes_dhcp_threshold_reached(): void
{
    $log = $this->makeLog('dhcp.threshold_reached', metadata: ['pool' => 'lan', 'usage' => 92.4]);

    $this->assertSame('DHCP pool lan reached 92% utilisation', AuditLogDescriptionGenerator::generate($log));
}
```

> If `makeLog` does not already accept a `subject:` argument or `SwitchConfig` is not imported, add the import (`use App\Models\SwitchConfig;`) and extend the helper to set the subject — mirror how it sets `metadata`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuditLogDescriptionGeneratorTest > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: FAIL — the three new actions fall through to `fallback()`.

- [ ] **Step 3: Add the cases**

In `app/Services/AuditLog/AuditLogDescriptionGenerator.php`, add these arms to the `match ($log->action)` (before `default =>`):

```php
            'switch.unreachable' => sprintf('Switch %s unreachable after %s failures', self::subjectLabel($log), self::metadataString($log, 'failure_count', '?')),
            'bandwidth.anomaly' => sprintf('Bandwidth anomaly detected on %s', self::metadataString($log, 'ip_address', 'unknown')),
            'dhcp.threshold_reached' => sprintf('DHCP pool %s reached %d%% utilisation', self::metadataString($log, 'pool', 'unknown'), (int) ($log->metadata['usage'] ?? 0)),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AuditLogDescriptionGeneratorTest > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditLog/AuditLogDescriptionGenerator.php tests/Unit/Services/AuditLog/AuditLogDescriptionGeneratorTest.php
git commit -m "feat(audit): describe switch/bandwidth/dhcp telemetry actions"
```

---

## Task 4: Rewrite `RecordBroadcastEvent` to translate telemetry into audit logs

**Files:**
- Modify: `app/Listeners/RecordBroadcastEvent.php`
- Test: `tests/Feature/Listeners/RecordBroadcastEventTest.php` (rewrite)

- [ ] **Step 1: Replace the test file**

Overwrite `tests/Feature/Listeners/RecordBroadcastEventTest.php` with audit-based assertions:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DhcpPoolThresholdReached;
use App\Events\InternetAccessChanged;
use App\Events\SwitchUnreachable;
use App\Listeners\RecordBroadcastEvent;
use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\SwitchConfig;
use App\Models\User;
use Tests\TestCase;

class RecordBroadcastEventTest extends TestCase
{
    private function listener(): RecordBroadcastEvent
    {
        return new RecordBroadcastEvent;
    }

    public function test_switch_unreachable_records_critical_audit_with_subject(): void
    {
        $switch = SwitchConfig::factory()->create(['name' => 'core-sw']);

        $this->listener()->handleBroadcastEvent(new SwitchUnreachable($switch, 3));

        $log = AuditLog::where('action', 'switch.unreachable')->firstOrFail();
        $this->assertSame('critical', $log->severity);
        $this->assertSame('network', $log->process);
        $this->assertSame($switch->getMorphClass(), $log->subject_type);
        $this->assertSame($switch->id, $log->subject_id);
        $this->assertSame(3, $log->metadata['failure_count']);
    }

    public function test_bandwidth_anomaly_records_warning_audit(): void
    {
        $this->listener()->handleBroadcastEvent(
            new BandwidthAnomalyDetected('10.0.0.5', 'alice', 1, 5000.0, 1000.0, 5.0, 3.0)
        );

        $log = AuditLog::where('action', 'bandwidth.anomaly')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertNull($log->subject_type);
        $this->assertSame('10.0.0.5', $log->metadata['ip_address']);
    }

    public function test_dhcp_threshold_records_warning_audit(): void
    {
        $this->listener()->handleBroadcastEvent(
            new DhcpPoolThresholdReached(pool: 'lan', usage: 92.0, threshold: 90.0, addressFamily: 'ipv4')
        );

        $log = AuditLog::where('action', 'dhcp.threshold_reached')->firstOrFail();
        $this->assertSame('warning', $log->severity);
        $this->assertSame('lan', $log->metadata['pool']);
    }

    public function test_already_audited_actor_event_is_not_recorded(): void
    {
        $ip = IpAddress::factory()->create();
        $user = User::factory()->create();

        $this->listener()->handleBroadcastEvent(new InternetAccessChanged($ip, true, $user));

        $this->assertDatabaseMissing('audit_logs', ['action' => 'internet_access_changed']);
        $this->assertSame(0, AuditLog::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RecordBroadcastEventTest > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: FAIL — listener still writes `SystemEvent`s and creates no audit rows.

- [ ] **Step 3: Rewrite the listener**

Overwrite `app/Listeners/RecordBroadcastEvent.php`:

```php
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\BandwidthAnomalyDetected;
use App\Events\DhcpPoolThresholdReached;
use App\Events\SwitchUnreachable;
use App\Models\AuditLog;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Events\Dispatcher;

class RecordBroadcastEvent
{
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
        try {
            match (true) {
                $event instanceof SwitchUnreachable => AuditLog::record(
                    action: 'switch.unreachable',
                    subject: $event->switchConfig,
                    process: 'network',
                    metadata: $event->broadcastWith(),
                    severity: 'critical',
                ),
                $event instanceof BandwidthAnomalyDetected => AuditLog::record(
                    action: 'bandwidth.anomaly',
                    process: 'network',
                    metadata: $event->broadcastWith(),
                    severity: 'warning',
                ),
                $event instanceof DhcpPoolThresholdReached => AuditLog::record(
                    action: 'dhcp.threshold_reached',
                    process: 'network',
                    metadata: $event->broadcastWith(),
                    severity: 'warning',
                ),
                default => null,
            };
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
```

> `AuditLogRecorded` is a `ShouldBroadcast` event too, but it matches none of the `instanceof` arms, so the wildcard listener ignores it — no recursion. The already-audited actor events (`InternetAccessChanged` etc.) also fall through to `default`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RecordBroadcastEventTest > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Listeners/RecordBroadcastEvent.php tests/Feature/Listeners/RecordBroadcastEventTest.php
git commit -m "refactor(events): record telemetry as audit logs, drop SystemEvent writes"
```

---

## Task 5: Seed dashboard `recentEvents` from `AuditLog`

**Files:**
- Modify: `app/Http/Controllers/Admin/HomeController.php`
- Test: `tests/Feature/Admin/HomeControllerTest.php` (add a test; create the file if absent)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Admin/HomeControllerTest.php`:

```php
public function test_dashboard_recent_events_come_from_audit_logs(): void
{
    $admin = User::factory()->admin()->create();
    AuditLog::record(action: 'switch.unreachable', metadata: ['failure_count' => 2], severity: 'critical');

    $this->actingAs($admin)
        ->get(route('admin.home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recentEvents', 1)
            ->where('recentEvents.0.action', 'switch.unreachable')
            ->where('recentEvents.0.severity', 'critical')
            ->has('recentEvents.0.description')
        );
}
```

> Match the existing file's helpers for admin auth and the `AssertableInertia` import (`use Inertia\Testing\AssertableInertia;`). `recentEvents` is `Inertia::defer`, so the test must request the deferred prop — if the suite has a helper for deferred props, use it; otherwise the assertion above triggers the closure because the prop is referenced.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_dashboard_recent_events_come_from_audit_logs > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: FAIL — `recentEvents` still maps `SystemEvent` rows (and `action`/`severity` keys are absent).

- [ ] **Step 3: Update the controller**

In `app/Http/Controllers/Admin/HomeController.php`: remove `use App\Models\SystemEvent;`, add `use App\Services\AuditLog\AuditLogDescriptionGenerator;` (keep the existing `use App\Models\AuditLog;`), and replace the `recentEvents` closure:

```php
            'recentEvents' => Inertia::defer(fn (): array => AuditLog::query()
                ->with(['subject', 'actor'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn (AuditLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => AuditLogDescriptionGenerator::generate($log),
                    'severity' => $log->severity,
                    'created_at' => $log->created_at->toIso8601String(),
                ])
                ->all()),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_dashboard_recent_events_come_from_audit_logs > /tmp/aperture-test.log 2>&1; tail -n 30 /tmp/aperture-test.log`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/HomeController.php tests/Feature/Admin/HomeControllerTest.php
git commit -m "feat(dashboard): seed recent activity from audit logs"
```

---

## Task 6: Delete the SystemEvent subsystem

**Files:**
- Delete: model, factory, controller, command, create-migration, and their tests (see File Structure).
- Create: `database/migrations/2026_06_12_000002_drop_system_events_table.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Confirm no remaining references**

Run: `grep -rEn "SystemEvent|EventController|events\.index|events:prune" app/ routes/ database/ resources/js tests/ "--include=*.php" "--include=*.vue" "--include=*.js" | grep -v "seatpicker"`
Expected: only the files this task deletes/edits. Note any unexpected hit (e.g. a scheduler entry for `events:prune` in `routes/console.php` or `bootstrap/app.php`) and remove it in Step 3.

- [ ] **Step 2: Create the drop migration**

`database/migrations/2026_06_12_000002_drop_system_events_table.php`:

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
        Schema::dropIfExists('system_events');
    }

    public function down(): void
    {
        Schema::create('system_events', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('level');
            $table->text('message');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
};
```

- [ ] **Step 3: Delete files and clean the route**

```bash
git rm app/Models/SystemEvent.php \
       database/factories/SystemEventFactory.php \
       app/Http/Controllers/Admin/EventController.php \
       app/Console/Commands/PruneSystemEventsCommand.php \
       database/migrations/2026_04_27_002100_create_system_events_table.php \
       tests/Unit/Models/SystemEventTest.php \
       tests/Feature/Admin/EventControllerTest.php \
       tests/Feature/Commands/PruneSystemEventsCommandTest.php
```

In `routes/web.php` remove `use App\Http\Controllers\Admin\EventController;` and the line:
`Route::get('/events', [EventController::class, 'index'])->name('events.index');`

Also remove any `events:prune` scheduler registration found in Step 1.

- [ ] **Step 4: Run the full backend suite**

Run: `XDEBUG_MODE=coverage php artisan test > /tmp/aperture-test.log 2>&1; tail -n 40 /tmp/aperture-test.log`
Expected: PASS — no references to the deleted classes; migrations run clean (create-severity, drop-system_events).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore(events): delete SystemEvent model, controller, command, migration, tests"
```

---

## Task 7: `RecentActivity.vue` component (replaces `EventFeed.vue`)

**Files:**
- Create: `resources/js/Components/Admin/RecentActivity.vue`
- Test: `resources/js/Components/Admin/__tests__/RecentActivity.test.js`
- Delete: `resources/js/Components/Admin/EventFeed.vue` (+ its test if present)

- [ ] **Step 1: Write the failing test**

Create `resources/js/Components/Admin/__tests__/RecentActivity.test.js`:

```js
import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import RecentActivity from '../RecentActivity.vue';

vi.mock('@inertiajs/vue3', () => ({ Link: { template: '<a><slot /></a>' } }));
globalThis.route = () => '/admin/audit-log';

const events = [
    { id: 1, action: 'switch.unreachable', description: 'Switch core-sw unreachable after 3 failures', severity: 'critical', created_at: '2026-06-12T10:00:00+00:00' },
    { id: 2, action: 'user.login', description: 'alice logged in', severity: 'info', created_at: '2026-06-12T09:59:00+00:00' },
];

describe('RecentActivity', () => {
    it('renders one entry per event with its description', () => {
        const wrapper = mount(RecentActivity, { props: { events } });
        const rows = wrapper.findAll('[data-testid="recent-activity-entry"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.text()).toContain('Switch core-sw unreachable after 3 failures');
    });

    it('marks the severity on each entry', () => {
        const wrapper = mount(RecentActivity, { props: { events } });
        const dots = wrapper.findAll('[data-testid="recent-activity-severity"]');
        expect(dots[0].attributes('data-severity')).toBe('critical');
        expect(dots[1].attributes('data-severity')).toBe('info');
    });

    it('shows the empty state when there are no events', () => {
        const wrapper = mount(RecentActivity, { props: { events: [] } });
        expect(wrapper.find('[data-testid="recent-activity-empty"]').exists()).toBe(true);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Components/Admin/__tests__/RecentActivity.test.js > /tmp/aperture-vitest.log 2>&1; tail -n 30 /tmp/aperture-vitest.log`
Expected: FAIL — `RecentActivity.vue` does not exist.

- [ ] **Step 3: Create the component**

`resources/js/Components/Admin/RecentActivity.vue`:

```vue
<script setup>
import { Link } from '@inertiajs/vue3';
import SectionHeader from '@/Components/UI/SectionHeader.vue';
import { formatRelativeTime } from '@/utils/dates';

defineProps({
    events: { type: Array, default: () => [] },
});

const SEVERITY_COLOR = {
    info: 'var(--color-text-muted)',
    warning: 'var(--color-warning)',
    critical: 'var(--color-danger)',
};

function severityColor(severity) {
    return SEVERITY_COLOR[severity] ?? SEVERITY_COLOR.info;
}
</script>

<template>
    <div data-testid="recent-activity">
        <div class="flex items-baseline justify-between">
            <SectionHeader title="Recent Activity" />
            <Link
                :href="route('admin.audit-log.index')"
                data-testid="recent-activity-view-all"
                class="text-[12px] font-semibold text-[var(--color-primary)] transition-colors hover:text-[var(--color-primary-hover)]"
            >
                View All &rarr;
            </Link>
        </div>

        <div data-testid="recent-activity-scroll" class="max-h-[400px] overflow-y-auto">
            <div v-if="events.length === 0" data-testid="recent-activity-empty" class="py-6 text-center">
                <p class="text-[11px] text-[var(--color-text-muted)]">No recent activity</p>
            </div>

            <div v-else class="space-y-0">
                <div
                    v-for="(event, index) in events"
                    :key="event.id"
                    data-testid="recent-activity-entry"
                    class="recent-activity-item flex items-start gap-3 border-b border-[var(--color-border)] px-1 py-2 last:border-b-0"
                    :style="{ '--i': Math.min(index, 7) }"
                >
                    <span
                        data-testid="recent-activity-severity"
                        :data-severity="event.severity"
                        class="mt-[6px] h-[7px] w-[7px] shrink-0 rounded-full"
                        :style="{ backgroundColor: severityColor(event.severity) }"
                    />
                    <span
                        data-testid="recent-activity-timestamp"
                        class="shrink-0 font-mono text-[11px] text-[var(--color-text-muted)]"
                    >
                        {{ formatRelativeTime(event.created_at) }}
                    </span>
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ event.description }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.recent-activity-item {
    animation: recent-activity-slide-in 300ms cubic-bezier(0.16, 1, 0.3, 1) backwards;
    animation-delay: calc(var(--i, 0) * 30ms);
}

@keyframes recent-activity-slide-in {
    from {
        opacity: 0;
        transform: translateX(12px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .recent-activity-item {
        animation: none;
    }
}
</style>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/Components/Admin/__tests__/RecentActivity.test.js > /tmp/aperture-vitest.log 2>&1; tail -n 30 /tmp/aperture-vitest.log`
Expected: PASS

- [ ] **Step 5: Delete the old component**

```bash
git rm resources/js/Components/Admin/EventFeed.vue
# also remove its test if one exists:
git rm resources/js/Components/Admin/__tests__/EventFeed.test.js 2>/dev/null || true
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Admin/RecentActivity.vue resources/js/Components/Admin/__tests__/RecentActivity.test.js
git commit -m "feat(dashboard): RecentActivity widget with severity, replaces EventFeed"
```

---

## Task 8: Rewire `Dashboard.vue` to the single `AuditLogRecorded` stream

**Files:**
- Modify: `resources/js/Pages/Admin/Dashboard.vue`
- Delete: `resources/js/Pages/Admin/Events/Index.vue` (+ `__tests__`)
- Test: `resources/js/Pages/Admin/__tests__/Dashboard.test.js` (update existing assertions)

- [ ] **Step 1: Update the Dashboard test**

In the existing Dashboard test, replace event-feed assertions with recent-activity ones. Add/adjust:

```js
it('seeds recent activity from the recentEvents prop', () => {
    const wrapper = mountDashboard({
        recentEvents: [
            { id: 1, action: 'user.login', description: 'alice logged in', severity: 'info', created_at: '2026-06-12T10:00:00+00:00' },
        ],
    });
    expect(wrapper.find('[data-testid="recent-activity"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('alice logged in');
});

it('prepends an AuditLogRecorded broadcast to the feed', async () => {
    const handlers = captureAdminChannelHandlers(); // helper that records the events map passed to useAdminChannel
    mountDashboard({ recentEvents: [] });
    handlers.AuditLogRecorded({ id: 9, action: 'switch.unreachable', description: 'Switch core-sw unreachable after 3 failures', severity: 'critical', created_at: '2026-06-12T10:01:00+00:00' });
    await nextTick();
    // assert the entry is now rendered
});
```

> Reuse the file's existing mount helper and its `useAdminChannel` mock. If the mock currently inspects specific event keys (e.g. `SwitchSyncCompleted`), update it to capture the `AuditLogRecorded` handler instead.

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Pages/Admin/__tests__/Dashboard.test.js > /tmp/aperture-vitest.log 2>&1; tail -n 40 /tmp/aperture-vitest.log`
Expected: FAIL — Dashboard still imports `EventFeed` and lacks the `AuditLogRecorded` subscription.

- [ ] **Step 3: Rewire the script**

In `resources/js/Pages/Admin/Dashboard.vue`:

1. Replace the import `import EventFeed from '@/Components/Admin/EventFeed.vue';` with
   `import RecentActivity from '@/Components/Admin/RecentActivity.vue';`.
2. Delete the entire `EVENT_FORMATTERS` object and the `addEventFeedItem` and `handleBroadcastEvent` functions.
3. Replace the `useAdminChannel({ events: { ...11 handlers... }, poll, pollInterval })` call with:

```js
function addActivityItem(activity) {
    eventFeedItems.value = [activity, ...eventFeedItems.value].slice(0, 50);
}

useAdminChannel({
    events: {
        AuditLogRecorded: (activity) => {
            addActivityItem(activity);
            refreshDashboard();
        },
    },
    poll: fetchBandwidth,
    pollInterval: 30000,
});
```

4. Keep the existing `eventFeedItems` ref and the `watch(() => props.recentEvents, ...)` seeding block unchanged.
5. In the template, replace `<EventFeed :events="eventFeedItems" data-testid="event-feed" />` with
   `<RecentActivity :events="eventFeedItems" data-testid="recent-activity" />`.

> The broadcast payload from `AuditLogRecorded` already matches the prop shape `{ id, action, description, severity, created_at }`, so no client-side formatting remains.

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/Pages/Admin/__tests__/Dashboard.test.js > /tmp/aperture-vitest.log 2>&1; tail -n 40 /tmp/aperture-vitest.log`
Expected: PASS

- [ ] **Step 5: Delete the standalone Event Feed page**

```bash
git rm resources/js/Pages/Admin/Events/Index.vue
git rm -r resources/js/Pages/Admin/Events/__tests__ 2>/dev/null || true
```

Run: `grep -rn "Admin/Events" resources/js` — expected: no matches.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Admin/Dashboard.vue
git add -A
git commit -m "feat(dashboard): drive Recent Activity from AuditLogRecorded broadcast"
```

---

## Task 9: Full gate pass

**Files:** none (verification only)

- [ ] **Step 1: Backend tests + coverage**

Run: `XDEBUG_MODE=coverage php artisan test --coverage > /tmp/aperture-test.log 2>&1; tail -n 50 /tmp/aperture-test.log`
Expected: PASS, 100% coverage on all touched files. If any new file is below 100%, add the missing case before proceeding.

- [ ] **Step 2: Static analysis + formatting (PHP)**

Run: `vendor/bin/pint --dirty && vendor/bin/phpstan analyse > /tmp/aperture-stan.log 2>&1; tail -n 40 /tmp/aperture-stan.log && vendor/bin/rector process --dry-run > /tmp/aperture-rector.log 2>&1; tail -n 40 /tmp/aperture-rector.log`
Expected: Pint clean, PHPStan level 8 no errors (no new baseline entries), Rector no changes.

- [ ] **Step 3: Frontend tests + lint**

Run: `npx vitest run > /tmp/aperture-vitest.log 2>&1; tail -n 40 /tmp/aperture-vitest.log && npx eslint resources/js && npx prettier --check resources/js`
Expected: all green.

- [ ] **Step 4: UI audit**

Use the impeccable `audit` skill (or `audit` subagent) on the dashboard Recent Activity widget. Implement any critical/high findings, then re-run Steps 1–3.

- [ ] **Step 5: Final commit (if Step 4 changed anything)**

```bash
git add -A
git commit -m "chore: gate fixes for recent-activity migration"
```

---

## Self-Review notes

- **Spec coverage:** A→Task 6; B→Tasks 1–2; C→Task 4; D→Task 3; E→Task 1; F→Tasks 5,7,8; G→Tasks throughout + Task 9.
- **No-recursion** (`AuditLogRecorded` ignored by listener) is asserted implicitly by `test_already_audited_actor_event_is_not_recorded` (count stays 0) and explicitly safe via the `instanceof` allow-list; add a dedicated dispatch-and-assert-count test in Task 4 if coverage requires.
- **Type consistency:** `record(..., string $severity = 'info')`; payload keys `{id, action, description, severity, created_at}` are identical across the event (Task 2), HomeController (Task 5), the component props (Task 7), and the Dashboard handler (Task 8).
- **Deferred-prop testing** (Task 5) and the **existing Dashboard mount/`useAdminChannel` mock** (Task 8) are the two spots most likely to need adaptation to the suite's existing helpers — flagged inline.
