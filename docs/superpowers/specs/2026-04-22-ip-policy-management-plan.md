# IP Policy Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify internet access, rate limiting, and DNS filtering into an observer-driven IP policy system where local DB is source of truth and property changes automatically propagate to external backends.

**Architecture:** Three boolean policy fields (`internet_enabled`, `rate_limit_enabled`, `dns_filtering_enabled`) on both `users` and `ip_addresses` tables. Eloquent observers detect changes and dispatch per-concern jobs. An `IpPolicyService` bridges user-level settings to IP-level state. Reconciliation commands bulk-sync local state to external systems.

**Tech Stack:** Laravel 12 / PHP 8.5, Eloquent Observers, Queued Jobs, PHPUnit, PiHole API (Guzzle), OPNsense Firewall API

---

## File Structure

### New files
- `app/Services/IpPolicyService.php` — applies user policy to IPs, applies defaults
- `app/Services/Interfaces/DnsFilteringInterface.php` — renamed from DnsBlockingInterface
- `app/Services/ValueObjects/ReconcileResult.php` — result VO for reconciliation
- `app/Observers/UserObserver.php` — detects user policy field changes
- `app/Observers/IpAddressObserver.php` — detects IP policy field changes, dispatches backend jobs
- `app/Observers/UserIpAddressObserver.php` — detects disassociation, applies defaults
- `app/Jobs/SyncUserPolicyJob.php` — bridges user change to IP change
- `app/Jobs/SyncInternetAccessJob.php` — calls firewall backend
- `app/Jobs/SyncRateLimitJob.php` — calls firewall backend
- `app/Console/Commands/ReconcileInternetCommand.php`
- `app/Console/Commands/ReconcileRateLimitsCommand.php`
- `app/Console/Commands/ReconcileDnsFilteringCommand.php`
- `database/migrations/YYYY_MM_DD_HHMMSS_rename_policy_columns_and_add_new_fields.php`

### Modified files
- `app/Services/Interfaces/DnsBlockingInterface.php` → delete (replaced by DnsFilteringInterface)
- `app/Services/PiHole/PiHoleService.php` — implement DnsFilteringInterface, semantic flip, reconcile method
- `app/Services/Interfaces/FirewallBackendInterface.php` — add reconcile methods
- `app/Services/Firewalls/OpnSense.php` — implement reconcile methods
- `app/Services/IpAddressActionService.php` — rename methods
- `app/Models/IpAddress.php` — rename fields, remove allow/deny/limit/unlimit methods
- `app/Models/User.php` — rename blocked→internet_blocked, add fields
- `app/Models/UserIpAddress.php` — no changes needed
- `app/Jobs/SyncDnsFilteringJob.php` — use DnsFilteringInterface
- `app/Jobs/IpAddressAction.php` — update method names
- `app/Jobs/GrantNetworkAccess.php` — use new field/method names
- `app/Jobs/RevokeNetworkAccess.php` — use new field/method names
- `app/Jobs/ReapplyAccessRules.php` — use new field/method names
- `app/Jobs/ResetAperture.php` — use new field/method names
- `app/Jobs/ScanNetworkDevices.php` — use new field names
- `app/Http/Controllers/Portal/DashboardController.php` — simplify, add blocked message
- `app/Http/Controllers/PortalController.php` — use new field names
- `app/Http/Controllers/Portal/DnsFilterController.php` — use DnsFilteringInterface
- `app/Http/Controllers/Portal/PiHoleController.php` — remove or consolidate
- `app/Http/Controllers/Admin/HomeController.php` — rename blocked→internet_blocked
- `app/Http/Controllers/Admin/UserController.php` — rename blocked→internet_blocked
- `app/Http/Controllers/Admin/IpAddressController.php` — use new field/method names
- `app/Providers/AppServiceProvider.php` — update binding
- `app/Events/IpAllowed.php` — rename to IpInternetEnabled or keep (cosmetic)
- `config/integrations.php` — rename noblock_group_id→filtered_group_id
- `database/factories/IpAddressFactory.php` — rename allowed→internet_enabled, limited→rate_limit_enabled
- `database/factories/UserFactory.php` — rename blocked→internet_blocked
- `app/Console/Commands/ResetCommand.php` — use new field/method names
- `app/Console/Commands/ExpireSessionsCommand.php` — use new field/method names
- `app/Console/Commands/OpnSenseRecoveryCommand.php` — use new field/method names
- `resources/js/Components/Blocks/ConnectionStripBlock.vue` — ipAllowed→internetEnabled
- `resources/js/Components/Blocks/ConnectionStatusBlock.vue` — ipAllowed→internetEnabled
- `resources/js/Pages/Admin/Dashboard.vue` — blockedUsers→internetBlockedUsers
- `resources/js/Pages/Admin/Users/Show.vue` — blocked→internet_blocked
- `resources/js/Pages/Admin/Users/Index.vue` — blocked→internet_blocked
- `resources/js/Pages/Admin/Users/Edit.vue` — blocked→internet_blocked
- `resources/views/portal.blade.php` — blocked→internet_blocked, allowed→internet_enabled

---

### Task 1: Database Migration — Rename Columns and Add New Fields

**Files:**
- Create: `database/migrations/2026_04_22_200000_rename_policy_columns_and_add_new_fields.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration rename_policy_columns_and_add_new_fields --no-interaction
```

- [ ] **Step 2: Write the migration**

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
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('blocked', 'internet_blocked');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('internet_enabled')->default(false)->after('internet_blocked');
            $table->boolean('rate_limit_enabled')->default(false)->after('internet_enabled');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('dns_filtering_enabled')->default(false)->change();
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->renameColumn('allowed', 'internet_enabled');
            $table->renameColumn('limited', 'rate_limit_enabled');
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->boolean('dns_filtering_enabled')->default(false)->after('rate_limit_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->dropColumn('dns_filtering_enabled');
        });

        Schema::table('ip_addresses', function (Blueprint $table): void {
            $table->renameColumn('internet_enabled', 'allowed');
            $table->renameColumn('rate_limit_enabled', 'limited');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('dns_filtering_enabled')->default(true)->change();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['internet_enabled', 'rate_limit_enabled']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('internet_blocked', 'blocked');
        });
    }
};
```

- [ ] **Step 3: Run the migration**

```bash
php artisan migrate
```

Expected: Migration runs successfully.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*rename_policy_columns*
git commit -m "feat: rename policy columns and add new fields to users and ip_addresses"
```

---

### Task 2: Update Factories

**Files:**
- Modify: `database/factories/UserFactory.php`
- Modify: `database/factories/IpAddressFactory.php`

- [ ] **Step 1: Update UserFactory**

In `database/factories/UserFactory.php`, rename `blocked` to `internet_blocked` in `definition()` and rename the `blocked()` state method to `internetBlocked()`:

```php
public function definition(): array
{
    return [
        'nickname' => fake()->name(),
        'email' => fake()->unique()->safeEmail(),
        'internet_blocked' => false,
        'internet_enabled' => false,
        'rate_limit_enabled' => false,
        'dns_filtering_enabled' => false,
        'external_id' => null,
        'access_token' => null,
        'refresh_token' => null,
        'token_expires_at' => null,
        'avatar_url' => null,
    ];
}

/**
 * Indicate that the user has internet blocked.
 */
public function internetBlocked(): static
{
    return $this->state(fn (array $attributes) => [
        'internet_blocked' => true,
    ]);
}
```

- [ ] **Step 2: Update IpAddressFactory**

In `database/factories/IpAddressFactory.php`, rename `allowed` to `internet_enabled` and `limited` to `rate_limit_enabled` in `definition()`. Rename `allowed()` state to `internetEnabled()`:

```php
public function definition(): array
{
    return [
        'address' => fake()->ipv4(),
        'last_seen_at' => now(),
        'internet_enabled' => false,
        'rate_limit_enabled' => false,
        'dns_filtering_enabled' => false,
        'received' => 0,
        'sent' => 0,
    ];
}

/**
 * Indicate that the IP has internet enabled.
 */
public function internetEnabled(): static
{
    return $this->state(fn (array $attributes) => [
        'internet_enabled' => true,
    ]);
}

/**
 * Indicate that the IP session has expired.
 */
public function expired(): static
{
    return $this->state(fn (array $attributes) => [
        'internet_enabled' => true,
        'expires_at' => now()->subHour(),
    ]);
}
```

- [ ] **Step 3: Commit**

```bash
git add database/factories/UserFactory.php database/factories/IpAddressFactory.php
git commit -m "refactor: update factories for renamed policy columns"
```

---

### Task 3: Update User Model

**Files:**
- Modify: `app/Models/User.php`

- [ ] **Step 1: Update User model**

In `app/Models/User.php`:

1. Update the docblock: rename `@property int $blocked` to `@property bool $internet_blocked`, add `@property bool $internet_enabled` and `@property bool $rate_limit_enabled`.
2. Update `casts()` to include the new fields:

```php
protected function casts(): array
{
    return [
        'password' => 'hashed',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'internet_blocked' => 'boolean',
        'internet_enabled' => 'boolean',
        'rate_limit_enabled' => 'boolean',
        'dns_filtering_enabled' => 'boolean',
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/User.php
git commit -m "refactor: update User model for renamed policy fields"
```

---

### Task 4: Update IpAddress Model — Rename Fields, Remove Action Methods

**Files:**
- Modify: `app/Models/IpAddress.php`

- [ ] **Step 1: Update IpAddress model**

In `app/Models/IpAddress.php`:

1. Update the docblock: remove `@property` for `allowed`/`limited`, add `@property bool $internet_enabled`, `@property bool $rate_limit_enabled`, `@property bool $dns_filtering_enabled`.
2. Remove the `allow()`, `deny()`, `limit()`, `unlimit()` methods entirely (lines ~138-180). The observer pattern replaces these.
3. Keep `shutPort()`, `unshutPort()`, `updateUsage()`, `getStats()` as they are not policy-related.
4. Remove the `use App\Jobs\IpAddressAction;` import if no remaining methods dispatch it.

- [ ] **Step 2: Commit**

```bash
git add app/Models/IpAddress.php
git commit -m "refactor: update IpAddress model, remove action methods in favor of observers"
```

---

### Task 5: Create ReconcileResult Value Object

**Files:**
- Create: `app/Services/ValueObjects/ReconcileResult.php`
- Create: `tests/Unit/Services/ValueObjects/ReconcileResultTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class ReconcileResultTest extends TestCase
{
    public function test_can_be_constructed_with_arrays(): void
    {
        $result = new ReconcileResult(
            added: ['10.0.0.1', '10.0.0.2'],
            removed: ['10.0.0.3'],
            unchanged: ['10.0.0.4'],
            errors: ['10.0.0.5: connection refused'],
        );

        $this->assertSame(['10.0.0.1', '10.0.0.2'], $result->added);
        $this->assertSame(['10.0.0.3'], $result->removed);
        $this->assertSame(['10.0.0.4'], $result->unchanged);
        $this->assertSame(['10.0.0.5: connection refused'], $result->errors);
    }

    public function test_can_be_constructed_with_empty_arrays(): void
    {
        $result = new ReconcileResult(
            added: [],
            removed: [],
            unchanged: [],
            errors: [],
        );

        $this->assertEmpty($result->added);
        $this->assertEmpty($result->removed);
        $this->assertEmpty($result->unchanged);
        $this->assertEmpty($result->errors);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=ReconcileResultTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

class ReconcileResult
{
    /**
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     * @param  array<int, string>  $unchanged
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly array $added,
        public readonly array $removed,
        public readonly array $unchanged,
        public readonly array $errors,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter=ReconcileResultTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ValueObjects/ReconcileResult.php tests/Unit/Services/ValueObjects/ReconcileResultTest.php
git commit -m "feat: add ReconcileResult value object"
```

---

### Task 6: Create DnsFilteringInterface (Replace DnsBlockingInterface)

**Files:**
- Create: `app/Services/Interfaces/DnsFilteringInterface.php`
- Delete: `app/Services/Interfaces/DnsBlockingInterface.php`

- [ ] **Step 1: Create the new interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ReconcileResult;

interface DnsFilteringInterface
{
    public function isEnabledForIp(string $ipAddress): bool;

    public function enableForIp(string $ipAddress): void;

    public function disableForIp(string $ipAddress): void;

    public function reconcile(bool $dryRun = false): ReconcileResult;
}
```

- [ ] **Step 2: Delete the old interface**

```bash
rm app/Services/Interfaces/DnsBlockingInterface.php
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Interfaces/DnsFilteringInterface.php
git rm app/Services/Interfaces/DnsBlockingInterface.php
git commit -m "refactor: rename DnsBlockingInterface to DnsFilteringInterface"
```

---

### Task 7: Rewrite PiHoleService — Implement DnsFilteringInterface with Semantic Flip

**Files:**
- Modify: `app/Services/PiHole/PiHoleService.php`
- Modify: `tests/Unit/Services/PiHole/PiHoleServiceTest.php` (if exists, otherwise create)

- [ ] **Step 1: Write tests for the semantic flip**

Create/update `tests/Unit/Services/PiHole/PiHoleServiceTest.php` with tests that verify:

- `enableForIp` adds the `filteredGroupId` to the client's groups (creates client if not found)
- `disableForIp` removes the `filteredGroupId` from the client's groups
- `isEnabledForIp` returns true when the `filteredGroupId` is in the client's groups
- `isEnabledForIp` returns false when client doesn't exist or `filteredGroupId` not in groups

The tests should mock the Guzzle HTTP client. Refer to the existing test structure in `tests/Unit/Services/PiHole/` if it exists.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=PiHoleServiceTest
```

- [ ] **Step 3: Rewrite PiHoleService**

In `app/Services/PiHole/PiHoleService.php`:

1. Change `implements DnsBlockingInterface` to `implements DnsFilteringInterface`
2. Rename constructor parameter `$noblockGroupId` to `$filteredGroupId`
3. Flip the semantic of all methods:
   - `isEnabledForIp`: return `true` if `filteredGroupId` IS in client's groups (was: return true if NOT in noblock group)
   - `enableForIp`: add `filteredGroupId` to groups if not present, create client with `[0, filteredGroupId]` if not found (was: remove noblock group)
   - `disableForIp`: remove `filteredGroupId` from groups, do nothing if client not found (was: add noblock group / create client)
4. Add `reconcile(bool $dryRun = false): ReconcileResult` method:
   - Fetch all clients from PiHole API
   - Query `IpAddress::where('dns_filtering_enabled', true)->pluck('address')` for desired enabled IPs
   - Query `IpAddress::where('dns_filtering_enabled', false)->pluck('address')` for desired disabled IPs
   - Diff: enabled IPs missing `filteredGroupId` → add. Disabled IPs with `filteredGroupId` → remove.
   - If `$dryRun`, return result without applying. Otherwise apply changes.
5. Update import from `DnsBlockingInterface` to `DnsFilteringInterface`

```php
<?php

declare(strict_types=1);

namespace App\Services\PiHole;

use App\Models\IpAddress;
use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\ValueObjects\ReconcileResult;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class PiHoleService implements DnsFilteringInterface
{
    public function __construct(
        protected Client $client,
        protected string $password,
        protected int $filteredGroupId,
    ) {}

    public function isEnabledForIp(string $ipAddress): bool
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return false;
        }

        return in_array($this->filteredGroupId, $client['groups'], true);
    }

    public function enableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            $this->createClient($ipAddress, [0, $this->filteredGroupId]);

            return;
        }

        if (in_array($this->filteredGroupId, $client['groups'], true)) {
            return;
        }

        $groups = array_merge($client['groups'], [$this->filteredGroupId]);
        $this->updateClientGroups($client['client'], $groups, $client['comment']);
    }

    public function disableForIp(string $ipAddress): void
    {
        $client = $this->findClient($ipAddress);

        if ($client === null) {
            return;
        }

        $groups = array_values(array_filter(
            $client['groups'],
            fn (int $g): bool => $g !== $this->filteredGroupId,
        ));

        if ($groups === $client['groups']) {
            return;
        }

        $this->updateClientGroups($client['client'], $groups, $client['comment']);
    }

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        $allClients = $this->fetchAllClients();

        $desiredEnabled = IpAddress::where('dns_filtering_enabled', true)
            ->pluck('address')
            ->all();
        $desiredDisabled = IpAddress::where('dns_filtering_enabled', false)
            ->pluck('address')
            ->all();

        $added = [];
        $removed = [];
        $unchanged = [];
        $errors = [];

        $clientMap = [];
        foreach ($allClients as $c) {
            $clientMap[$c['client']] = $c;
        }

        foreach ($desiredEnabled as $ip) {
            $existing = $clientMap[$ip] ?? null;
            if ($existing !== null && in_array($this->filteredGroupId, $existing['groups'], true)) {
                $unchanged[] = $ip;

                continue;
            }

            $added[] = $ip;
            if (! $dryRun) {
                try {
                    $this->enableForIp($ip);
                } catch (\Throwable $e) {
                    $errors[] = $ip.': '.$e->getMessage();
                }
            }
        }

        foreach ($desiredDisabled as $ip) {
            $existing = $clientMap[$ip] ?? null;
            if ($existing === null || ! in_array($this->filteredGroupId, $existing['groups'], true)) {
                $unchanged[] = $ip;

                continue;
            }

            $removed[] = $ip;
            if (! $dryRun) {
                try {
                    $this->disableForIp($ip);
                } catch (\Throwable $e) {
                    $errors[] = $ip.': '.$e->getMessage();
                }
            }
        }

        return new ReconcileResult(
            added: $added,
            removed: $removed,
            unchanged: $unchanged,
            errors: $errors,
        );
    }

    // Keep existing protected methods: findClient, createClient, updateClientGroups, getSessionId

    /**
     * Fetch all clients from PiHole.
     *
     * @return array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>
     */
    protected function fetchAllClients(): array
    {
        $sid = $this->getSessionId();

        $response = $this->client->get('/api/clients', [
            'headers' => ['X-FTL-SID' => $sid],
        ]);

        /** @var array{clients: array<int, array{id: int, client: string, groups: array<int, int>, comment: string}>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['clients'];
    }

    // ... existing findClient, createClient, updateClientGroups, getSessionId methods unchanged
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PiHoleServiceTest
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/PiHole/PiHoleService.php tests/Unit/Services/PiHole/PiHoleServiceTest.php
git commit -m "refactor: rewrite PiHoleService with DnsFilteringInterface and semantic flip"
```

---

### Task 8: Update FirewallBackendInterface with Reconcile Methods

**Files:**
- Modify: `app/Services/Interfaces/FirewallBackendInterface.php`
- Modify: `app/Services/Firewalls/OpnSense.php`

- [ ] **Step 1: Write tests for the reconcile methods**

Add tests in the appropriate OpnSense test file that verify `reconcileInternet()` and `reconcileRateLimits()` fetch backend state, compare with DB state, and return a `ReconcileResult`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=OpnSense
```

- [ ] **Step 3: Add methods to the interface**

In `app/Services/Interfaces/FirewallBackendInterface.php`, add:

```php
use App\Services\ValueObjects\ReconcileResult;

// Add to interface body:
public function reconcileInternet(bool $dryRun = false): ReconcileResult;

public function reconcileRateLimits(bool $dryRun = false): ReconcileResult;
```

- [ ] **Step 4: Implement in OpnSense**

In `app/Services/Firewalls/OpnSense.php`, implement `reconcileInternet()` and `reconcileRateLimits()`. Each should:
1. Fetch current state from the OPNsense API
2. Query `IpAddress` table for desired state
3. Diff and apply changeset (or report if dry run)
4. Return `ReconcileResult`

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=OpnSense
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/FirewallBackendInterface.php app/Services/Firewalls/OpnSense.php
git commit -m "feat: add reconcile methods to FirewallBackendInterface and OpnSense"
```

---

### Task 9: Update IpAddressActionService — Rename Methods

**Files:**
- Modify: `app/Services/IpAddressActionService.php`
- Modify: `tests/Unit/Services/IpAddressActionServiceTest.php` (or equivalent)

- [ ] **Step 1: Update tests to use new method names**

Rename all test references from `allow`→`enableInternet`, `deny`→`disableInternet`, `limit`→`enableRateLimit`, `unlimit`→`disableRateLimit`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=IpAddressActionService
```

- [ ] **Step 3: Rename methods in the service**

In `app/Services/IpAddressActionService.php`:

- `allow(IpAddress $ip)` → `enableInternet(IpAddress $ip)` — keep the same body but change `$ip->allowed = true` to `$ip->internet_enabled = true`
- `deny(IpAddress $ip)` → `disableInternet(IpAddress $ip)` — change `$ip->allowed = false` to `$ip->internet_enabled = false`
- `limit(IpAddress $ip)` → `enableRateLimit(IpAddress $ip)` — change `$ip->limited = true` to `$ip->rate_limit_enabled = true`
- `unlimit(IpAddress $ip)` → `disableRateLimit(IpAddress $ip)` — change `$ip->limited = false` to `$ip->rate_limit_enabled = false`

**Important:** These methods should ONLY call the external backend (firewall). They should NOT set the IP model fields or call `$ip->save()` — the observer handles that. Remove the field assignments and saves from these methods. They become pure backend wrappers:

```php
public function enableInternet(IpAddress $ip): void
{
    $description = $ip->comment;
    /** @var UserIpAddress|null $userIp */
    $userIp = $ip->users()->first();
    if ($userIp && $userIp->user) {
        $description = $userIp->user->nickname;
    }

    $this->firewall->updateIp($ip->address, (string) $description);

    try {
        $mac = $this->macResolver->resolveIpToMac($ip->address);
        if ($mac !== null) {
            $macAddress = MacAddress::firstOrCreate(
                ['mac_address' => $mac],
                ['source' => 'auth', 'allowed' => true, 'allowed_at' => now()],
            );
            $ip->mac_address_id = (int) $macAddress->id;
            $ip->saveQuietly(); // saveQuietly to avoid re-triggering observer

            if (! $macAddress->allowed) {
                $macAddress->allowed = true;
                $macAddress->allowed_at = now();
                $macAddress->save();
            }

            if ($macAddress->user_id === null && $userIp?->user) {
                $macAddress->user_id = (int) $userIp->user->id;
                $macAddress->save();
            }
        }
    } catch (Throwable) {
        // MAC resolution is best-effort
    }
}

public function disableInternet(IpAddress $ip): void
{
    $this->firewall->removeIp($ip->address);
}

public function enableRateLimit(IpAddress $ip): void
{
    $this->firewall->limitIp($ip->address);
}

public function disableRateLimit(IpAddress $ip): void
{
    $this->firewall->unlimitIp($ip->address);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=IpAddressActionService
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/IpAddressActionService.php tests/Unit/Services/IpAddressActionServiceTest.php
git commit -m "refactor: rename IpAddressActionService methods to match new field names"
```

---

### Task 10: Create IpPolicyService

**Files:**
- Create: `app/Services/IpPolicyService.php`
- Create: `tests/Unit/Services/IpPolicyServiceTest.php`

- [ ] **Step 1: Write the tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_user_policy_sets_ip_fields_to_match_user(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => true,
            'internet_blocked' => false,
        ]);
        $ip = IpAddress::factory()->create();

        $service = new IpPolicyService;
        $service->applyUserPolicy($user, $ip);

        $ip->refresh();
        $this->assertTrue($ip->internet_enabled);
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertTrue($ip->dns_filtering_enabled);
    }

    public function test_apply_user_policy_forces_internet_disabled_when_blocked(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => true,
            'internet_blocked' => true,
        ]);
        $ip = IpAddress::factory()->create();

        $service = new IpPolicyService;
        $service->applyUserPolicy($user, $ip);

        $ip->refresh();
        $this->assertFalse($ip->internet_enabled);
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertTrue($ip->dns_filtering_enabled);
    }

    public function test_apply_user_policy_does_not_save_when_already_matching(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
            'internet_blocked' => false,
        ]);
        $ip = IpAddress::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ]);

        $originalUpdatedAt = $ip->updated_at;

        $service = new IpPolicyService;
        $service->applyUserPolicy($user, $ip);

        $ip->refresh();
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }

    public function test_apply_defaults_sets_all_fields_to_false(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => true,
            'dns_filtering_enabled' => true,
        ]);

        $service = new IpPolicyService;
        $service->applyDefaults($ip);

        $ip->refresh();
        $this->assertFalse($ip->internet_enabled);
        $this->assertFalse($ip->rate_limit_enabled);
        $this->assertFalse($ip->dns_filtering_enabled);
    }

    public function test_apply_defaults_does_not_save_when_already_default(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create([
            'internet_enabled' => false,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ]);

        $originalUpdatedAt = $ip->updated_at;

        $service = new IpPolicyService;
        $service->applyDefaults($ip);

        $ip->refresh();
        $this->assertEquals($originalUpdatedAt, $ip->updated_at);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=IpPolicyServiceTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IpAddress;
use App\Models\User;

class IpPolicyService
{
    public function applyUserPolicy(User $user, IpAddress $ip): void
    {
        $internetEnabled = $user->internet_blocked ? false : $user->internet_enabled;

        $ip->internet_enabled = $internetEnabled;
        $ip->rate_limit_enabled = $user->rate_limit_enabled;
        $ip->dns_filtering_enabled = $user->dns_filtering_enabled;

        if ($ip->isDirty()) {
            $ip->save();
        }
    }

    public function applyDefaults(IpAddress $ip): void
    {
        $ip->internet_enabled = false;
        $ip->rate_limit_enabled = false;
        $ip->dns_filtering_enabled = false;

        if ($ip->isDirty()) {
            $ip->save();
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=IpPolicyServiceTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/IpPolicyService.php tests/Unit/Services/IpPolicyServiceTest.php
git commit -m "feat: add IpPolicyService for applying user policy to IPs"
```

---

### Task 11: Create Backend Sync Jobs

**Files:**
- Create: `app/Jobs/SyncInternetAccessJob.php`
- Create: `app/Jobs/SyncRateLimitJob.php`
- Create: `app/Jobs/SyncUserPolicyJob.php`
- Modify: `app/Jobs/SyncDnsFilteringJob.php`
- Create: `tests/Unit/Jobs/SyncInternetAccessJobTest.php`
- Create: `tests/Unit/Jobs/SyncRateLimitJobTest.php`
- Create: `tests/Unit/Jobs/SyncUserPolicyJobTest.php`
- Modify: `tests/Unit/Jobs/SyncDnsFilteringJobTest.php`

- [ ] **Step 1: Write test for SyncInternetAccessJob**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncInternetAccessJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncInternetAccessJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_enable_internet_when_enabled(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['internet_enabled' => true]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('enableInternet')
                ->once()
                ->withArgs(fn (IpAddress $arg): bool => $arg->id === $ip->id);
            $mock->shouldNotReceive('disableInternet');
        });

        $job = new SyncInternetAccessJob($ip, true);
        $this->app->call([$job, 'handle']);
    }

    public function test_calls_disable_internet_when_disabled(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['internet_enabled' => false]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('disableInternet')
                ->once()
                ->withArgs(fn (IpAddress $arg): bool => $arg->id === $ip->id);
            $mock->shouldNotReceive('enableInternet');
        });

        $job = new SyncInternetAccessJob($ip, false);
        $this->app->call([$job, 'handle']);
    }
}
```

- [ ] **Step 2: Write test for SyncRateLimitJob**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncRateLimitJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_enable_rate_limit_when_enabled(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['rate_limit_enabled' => true]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('enableRateLimit')
                ->once()
                ->withArgs(fn (IpAddress $arg): bool => $arg->id === $ip->id);
            $mock->shouldNotReceive('disableRateLimit');
        });

        $job = new SyncRateLimitJob($ip, true);
        $this->app->call([$job, 'handle']);
    }

    public function test_calls_disable_rate_limit_when_disabled(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['rate_limit_enabled' => false]);

        $this->mock(IpAddressActionService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('disableRateLimit')
                ->once()
                ->withArgs(fn (IpAddress $arg): bool => $arg->id === $ip->id);
            $mock->shouldNotReceive('enableRateLimit');
        });

        $job = new SyncRateLimitJob($ip, false);
        $this->app->call([$job, 'handle']);
    }
}
```

- [ ] **Step 3: Write test for SyncUserPolicyJob**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\SyncUserPolicyJob;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\IpPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncUserPolicyJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_apply_user_policy(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();
        UserIpAddress::factory()->create([
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
        ]);

        $this->mock(IpPolicyService::class, function (MockInterface $mock) use ($user, $ip): void {
            $mock->shouldReceive('applyUserPolicy')
                ->once()
                ->withArgs(fn (User $u, IpAddress $i): bool => $u->id === $user->id && $i->id === $ip->id);
        });

        $job = new SyncUserPolicyJob($user, $ip);
        $this->app->call([$job, 'handle']);
    }
}
```

- [ ] **Step 4: Run all three tests to verify they fail**

```bash
php artisan test --compact --filter="SyncInternetAccessJobTest|SyncRateLimitJobTest|SyncUserPolicyJobTest"
```

Expected: FAIL — classes not found.

- [ ] **Step 5: Write SyncInternetAccessJob**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncInternetAccessJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly IpAddress $ip,
        public readonly bool $enabled,
    ) {}

    public function handle(IpAddressActionService $actionService): void
    {
        if ($this->enabled) {
            $actionService->enableInternet($this->ip);
        } else {
            $actionService->disableInternet($this->ip);
        }
    }
}
```

- [ ] **Step 6: Write SyncRateLimitJob**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Services\IpAddressActionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRateLimitJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly IpAddress $ip,
        public readonly bool $enabled,
    ) {}

    public function handle(IpAddressActionService $actionService): void
    {
        if ($this->enabled) {
            $actionService->enableRateLimit($this->ip);
        } else {
            $actionService->disableRateLimit($this->ip);
        }
    }
}
```

- [ ] **Step 7: Write SyncUserPolicyJob**

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpPolicyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncUserPolicyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly IpAddress $ip,
    ) {}

    public function handle(IpPolicyService $policyService): void
    {
        $policyService->applyUserPolicy($this->user, $this->ip);
    }
}
```

- [ ] **Step 8: Update SyncDnsFilteringJob to use DnsFilteringInterface**

In `app/Jobs/SyncDnsFilteringJob.php`, change `DnsBlockingInterface` to `DnsFilteringInterface` in both the import and the `handle()` parameter type hint:

```php
use App\Services\Interfaces\DnsFilteringInterface;

// ...

public function handle(DnsFilteringInterface $dnsFiltering): void
{
    if ($this->enabled) {
        $dnsFiltering->enableForIp($this->ipAddress);
    } else {
        $dnsFiltering->disableForIp($this->ipAddress);
    }
}
```

- [ ] **Step 9: Update SyncDnsFilteringJobTest**

In `tests/Unit/Jobs/SyncDnsFilteringJobTest.php`, change all `DnsBlockingInterface` references to `DnsFilteringInterface`.

- [ ] **Step 10: Run all job tests**

```bash
php artisan test --compact --filter="SyncInternetAccessJobTest|SyncRateLimitJobTest|SyncUserPolicyJobTest|SyncDnsFilteringJobTest"
```

Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add app/Jobs/SyncInternetAccessJob.php app/Jobs/SyncRateLimitJob.php app/Jobs/SyncUserPolicyJob.php app/Jobs/SyncDnsFilteringJob.php tests/Unit/Jobs/
git commit -m "feat: add SyncInternetAccessJob, SyncRateLimitJob, SyncUserPolicyJob; update SyncDnsFilteringJob"
```

---

### Task 12: Create Observers

**Files:**
- Create: `app/Observers/UserObserver.php`
- Create: `app/Observers/IpAddressObserver.php`
- Create: `app/Observers/UserIpAddressObserver.php`
- Create: `tests/Unit/Observers/UserObserverTest.php`
- Create: `tests/Unit/Observers/IpAddressObserverTest.php`
- Create: `tests/Unit/Observers/UserIpAddressObserverTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (register observers)

- [ ] **Step 1: Write UserObserver test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\SyncUserPolicyJob;
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_sync_job_when_internet_enabled_changes(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_enabled' => false]);
        $ip = IpAddress::factory()->create();
        UserIpAddress::factory()->create([
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
        ]);

        $user->internet_enabled = true;
        $user->save();

        Queue::assertPushed(SyncUserPolicyJob::class, function (SyncUserPolicyJob $job) use ($user, $ip): bool {
            return $job->user->id === $user->id && $job->ip->id === $ip->id;
        });
    }

    public function test_dispatches_sync_job_when_internet_blocked_changes(): void
    {
        Queue::fake();
        $user = User::factory()->create(['internet_blocked' => false]);
        $ip = IpAddress::factory()->create();
        UserIpAddress::factory()->create([
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
        ]);

        $user->internet_blocked = true;
        $user->save();

        Queue::assertPushed(SyncUserPolicyJob::class);
    }

    public function test_does_not_dispatch_when_unrelated_field_changes(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create();
        UserIpAddress::factory()->create([
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
        ]);

        $user->nickname = 'New Name';
        $user->save();

        Queue::assertNotPushed(SyncUserPolicyJob::class);
    }

    public function test_dispatches_job_per_associated_ip(): void
    {
        Queue::fake();
        $user = User::factory()->create(['dns_filtering_enabled' => false]);
        $ip1 = IpAddress::factory()->create();
        $ip2 = IpAddress::factory()->create();
        UserIpAddress::factory()->create(['user_id' => $user->id, 'ip_address_id' => $ip1->id]);
        UserIpAddress::factory()->create(['user_id' => $user->id, 'ip_address_id' => $ip2->id]);

        $user->dns_filtering_enabled = true;
        $user->save();

        Queue::assertPushed(SyncUserPolicyJob::class, 2);
    }
}
```

- [ ] **Step 2: Write IpAddressObserver test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IpAddressObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_internet_job_when_internet_enabled_changes(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['internet_enabled' => false]);

        $ip->internet_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncInternetAccessJob::class, function (SyncInternetAccessJob $job) use ($ip): bool {
            return $job->ip->id === $ip->id && $job->enabled === true;
        });
        Queue::assertNotPushed(SyncRateLimitJob::class);
        Queue::assertNotPushed(SyncDnsFilteringJob::class);
    }

    public function test_dispatches_rate_limit_job_when_rate_limit_enabled_changes(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['rate_limit_enabled' => false]);

        $ip->rate_limit_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncRateLimitJob::class, function (SyncRateLimitJob $job) use ($ip): bool {
            return $job->ip->id === $ip->id && $job->enabled === true;
        });
        Queue::assertNotPushed(SyncInternetAccessJob::class);
        Queue::assertNotPushed(SyncDnsFilteringJob::class);
    }

    public function test_dispatches_dns_job_when_dns_filtering_enabled_changes(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create(['dns_filtering_enabled' => false]);

        $ip->dns_filtering_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncDnsFilteringJob::class, function (SyncDnsFilteringJob $job) use ($ip): bool {
            return $job->ipAddress === $ip->address && $job->enabled === true;
        });
        Queue::assertNotPushed(SyncInternetAccessJob::class);
        Queue::assertNotPushed(SyncRateLimitJob::class);
    }

    public function test_dispatches_multiple_jobs_when_multiple_fields_change(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create([
            'internet_enabled' => false,
            'rate_limit_enabled' => false,
            'dns_filtering_enabled' => false,
        ]);

        $ip->internet_enabled = true;
        $ip->rate_limit_enabled = true;
        $ip->dns_filtering_enabled = true;
        $ip->save();

        Queue::assertPushed(SyncInternetAccessJob::class, 1);
        Queue::assertPushed(SyncRateLimitJob::class, 1);
        Queue::assertPushed(SyncDnsFilteringJob::class, 1);
    }

    public function test_does_not_dispatch_when_no_policy_fields_change(): void
    {
        Queue::fake();
        $ip = IpAddress::factory()->create();

        $ip->comment = 'Updated comment';
        $ip->save();

        Queue::assertNotPushed(SyncInternetAccessJob::class);
        Queue::assertNotPushed(SyncRateLimitJob::class);
        Queue::assertNotPushed(SyncDnsFilteringJob::class);
    }
}
```

- [ ] **Step 3: Write UserIpAddressObserver test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Services\IpPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class UserIpAddressObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_defaults_when_user_ip_deleted(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $ip = IpAddress::factory()->create([
            'internet_enabled' => true,
            'rate_limit_enabled' => true,
            'dns_filtering_enabled' => true,
        ]);
        $userIp = UserIpAddress::factory()->create([
            'user_id' => $user->id,
            'ip_address_id' => $ip->id,
        ]);

        $this->mock(IpPolicyService::class, function (MockInterface $mock) use ($ip): void {
            $mock->shouldReceive('applyDefaults')
                ->once()
                ->withArgs(fn (IpAddress $arg): bool => $arg->id === $ip->id);
        });

        $userIp->delete();
    }
}
```

- [ ] **Step 4: Run tests to verify they fail**

```bash
php artisan test --compact --filter="UserObserverTest|IpAddressObserverTest|UserIpAddressObserverTest"
```

Expected: FAIL — observer classes not found / not registered.

- [ ] **Step 5: Write UserObserver**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncUserPolicyJob;
use App\Models\User;

class UserObserver
{
    /** @var list<string> */
    protected array $policyFields = [
        'internet_enabled',
        'rate_limit_enabled',
        'dns_filtering_enabled',
        'internet_blocked',
    ];

    public function updated(User $user): void
    {
        $changed = array_intersect($this->policyFields, array_keys($user->getChanges()));

        if (empty($changed)) {
            return;
        }

        $userIps = $user->ips()->with('ip')->get();

        foreach ($userIps as $userIp) {
            if ($userIp->ip) {
                SyncUserPolicyJob::dispatch($user, $userIp->ip);
            }
        }
    }
}
```

- [ ] **Step 6: Write IpAddressObserver**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncDnsFilteringJob;
use App\Jobs\SyncInternetAccessJob;
use App\Jobs\SyncRateLimitJob;
use App\Models\IpAddress;

class IpAddressObserver
{
    public function updated(IpAddress $ip): void
    {
        if ($ip->wasChanged('internet_enabled')) {
            SyncInternetAccessJob::dispatch($ip, $ip->internet_enabled);
        }

        if ($ip->wasChanged('rate_limit_enabled')) {
            SyncRateLimitJob::dispatch($ip, $ip->rate_limit_enabled);
        }

        if ($ip->wasChanged('dns_filtering_enabled')) {
            SyncDnsFilteringJob::dispatch($ip->address, $ip->dns_filtering_enabled);
        }
    }
}
```

- [ ] **Step 7: Write UserIpAddressObserver**

```php
<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\UserIpAddress;
use App\Services\IpPolicyService;

class UserIpAddressObserver
{
    public function __construct(
        protected IpPolicyService $policyService,
    ) {}

    public function deleted(UserIpAddress $userIp): void
    {
        $ip = $userIp->ip;

        if ($ip) {
            $this->policyService->applyDefaults($ip);
        }
    }
}
```

- [ ] **Step 8: Register observers in AppServiceProvider**

In `app/Providers/AppServiceProvider.php`, add to the `boot()` method:

```php
use App\Models\IpAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use App\Observers\IpAddressObserver;
use App\Observers\UserIpAddressObserver;
use App\Observers\UserObserver;

// In boot():
User::observe(UserObserver::class);
IpAddress::observe(IpAddressObserver::class);
UserIpAddress::observe(UserIpAddressObserver::class);
```

- [ ] **Step 9: Run tests to verify they pass**

```bash
php artisan test --compact --filter="UserObserverTest|IpAddressObserverTest|UserIpAddressObserverTest"
```

Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add app/Observers/ tests/Unit/Observers/ app/Providers/AppServiceProvider.php
git commit -m "feat: add UserObserver, IpAddressObserver, UserIpAddressObserver for policy sync"
```

---

### Task 13: Update User::addIp() to Apply User Policy

**Files:**
- Modify: `app/Models/User.php`
- Modify: `tests/Feature/Portal/DashboardControllerTest.php` (or relevant tests)

- [ ] **Step 1: Write/update tests**

Add a test that verifies `addIp()` applies user policy to the IP via `IpPolicyService`:

```php
public function test_add_ip_applies_user_policy(): void
{
    Queue::fake();
    $user = User::factory()->create([
        'internet_enabled' => true,
        'dns_filtering_enabled' => true,
        'internet_blocked' => false,
    ]);

    $ip = $user->addIp('10.0.0.1');

    $this->assertTrue($ip->internet_enabled);
    $this->assertTrue($ip->dns_filtering_enabled);
}

public function test_add_ip_respects_internet_blocked(): void
{
    Queue::fake();
    $user = User::factory()->create([
        'internet_enabled' => true,
        'internet_blocked' => true,
    ]);

    $ip = $user->addIp('10.0.0.2');

    $this->assertFalse($ip->internet_enabled);
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="test_add_ip_applies_user_policy"
```

- [ ] **Step 3: Update User::addIp()**

In `app/Models/User.php`, update `addIp()` to call `IpPolicyService::applyUserPolicy()` after associating the IP:

```php
public function addIp(string $clientIp): IpAddress
{
    $ip = IpAddress::whereAddress($clientIp)->first();
    if (! $ip) {
        $ip = new IpAddress;
        $ip->address = $clientIp;
        $ip->last_seen_at = Carbon::now();
        $ip->save();
    }

    $userIp = $this->ips()->whereIpAddressId($ip->id)->first();
    if (! $userIp) {
        $userIp = new UserIpAddress;
        $userIp->user()->associate($this);
        $userIp->ip()->associate($ip);
    }

    $userIp->last_seen_at = Carbon::now();
    $userIp->save();

    app(IpPolicyService::class)->applyUserPolicy($this, $ip);

    return $ip;
}
```

Add the import:
```php
use App\Services\IpPolicyService;
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter="test_add_ip_applies_user_policy|test_add_ip_respects_internet_blocked"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php
git commit -m "feat: addIp applies user policy via IpPolicyService"
```

---

### Task 14: Simplify DashboardController

**Files:**
- Modify: `app/Http/Controllers/Portal/DashboardController.php`
- Modify: `tests/Feature/Portal/DashboardControllerTest.php`

- [ ] **Step 1: Update tests**

Update existing dashboard tests:
- Replace `blockContext.ipAllowed` assertions with `blockContext.internetEnabled`
- Add assertions for `blockContext.internetBlocked` and `blockContext.blockedMessage`
- Remove tests that check for `SyncDnsFilteringJob` dispatch from the dashboard (the observer handles it now)
- Update factory calls: `->blocked()` → `->internetBlocked()`

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=DashboardControllerTest
```

- [ ] **Step 3: Update DashboardController**

In `app/Http/Controllers/Portal/DashboardController.php`:

1. Remove the `if (! $user->blocked)` block and the `SyncDnsFilteringJob::dispatch()` call — `addIp()` now handles everything
2. Update `blockContext`:
   - `ipAllowed` → `internetEnabled` using `(bool) $ip->internet_enabled`
   - Add `internetBlocked` from `(bool) $user->internet_blocked`
   - Add `blockedMessage` from `Setting::get('portal.blocked_message', '')`
3. Remove `use App\Jobs\SyncDnsFilteringJob;` import

```php
public function index(Request $request): Response
{
    /** @var User $user */
    $user = $request->user();
    $ip = $user->addIp((string) $request->getClientIp());

    $blocks = ContentBlock::active()->get();

    $checkUrl = Setting::get('dns.check_url');
    $warningMessage = Setting::get('dns.warning_message');

    $ipv6 = $this->resolveIpv6ForMac($ip->mac);

    return Inertia::render('Portal/Dashboard', [
        'blocks' => $blocks,
        'blockContext' => [
            'currentIpv4' => $ip->address,
            'currentIpv6' => $ipv6,
            'internetEnabled' => (bool) $ip->internet_enabled,
            'internetBlocked' => (bool) $user->internet_blocked,
            'blockedMessage' => (string) Setting::get('portal.blocked_message', ''),
            'macAddress' => $ip->mac,
            'dnsFilteringEnabled' => (bool) $user->dns_filtering_enabled,
            'user' => [
                'name' => $user->nickname ?? '',
                'params' => $user->parameters()->pluck('value', 'key')->toArray(),
            ],
        ],
        'dnsDetection' => $checkUrl ? [
            'checkUrl' => $checkUrl,
            'warningMessage' => $warningMessage ?? 'Your device is not using the event DNS servers. Please update your DNS settings.',
        ] : null,
    ]);
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=DashboardControllerTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Portal/DashboardController.php tests/Feature/Portal/DashboardControllerTest.php
git commit -m "refactor: simplify DashboardController, delegate policy to addIp + observers"
```

---

### Task 15: Update PortalController

**Files:**
- Modify: `app/Http/Controllers/PortalController.php`
- Modify: `tests/Feature/PortalControllerTest.php`

- [ ] **Step 1: Update tests**

In `tests/Feature/PortalControllerTest.php`:
- Replace all `blocked` factory calls with `internetBlocked`
- Replace `allowed` assertions with `internet_enabled`
- Replace `$user->blocked` checks with `$user->internet_blocked`
- Remove manual `$ip->allow(true)` calls — `addIp()` handles it

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=PortalControllerTest
```

- [ ] **Step 3: Update PortalController**

In `app/Http/Controllers/PortalController.php`:

1. `index()`: Remove `if (! $user->blocked) { $ip->allow(true); }` — `addIp()` handles it now
2. `status()`: Change `'allowed' => (bool) $ip->allowed` to `'internetEnabled' => (bool) $ip->internet_enabled`
3. `ipv6()`: Remove `if (! $user->blocked) { $ip->allow(true); }` — `addIp()` handles it. Change response field.

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PortalControllerTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php
git commit -m "refactor: update PortalController for renamed policy fields"
```

---

### Task 16: Update DnsFilterController and Remove PiHoleController

**Files:**
- Modify: `app/Http/Controllers/Portal/DnsFilterController.php`
- Delete: `app/Http/Controllers/Portal/PiHoleController.php`
- Modify: `tests/Feature/Portal/DnsFilterControllerTest.php`
- Delete or update: `tests/Feature/Portal/PiHoleControllerTest.php`

- [ ] **Step 1: Update DnsFilterController tests**

Update `tests/Feature/Portal/DnsFilterControllerTest.php` to expect the toggle to just flip the user's `dns_filtering_enabled` field. Remove assertions about `SyncDnsFilteringJob` being dispatched directly — the observer handles it via the IP.

- [ ] **Step 2: Update DnsFilterController**

In `app/Http/Controllers/Portal/DnsFilterController.php`:
- Remove `SyncDnsFilteringJob::dispatch()` call — the user observer handles it
- The controller just toggles `$user->dns_filtering_enabled` and saves

```php
public function toggle(Request $request): JsonResponse
{
    /** @var User $user */
    $user = $request->user();

    $enabled = ! $user->dns_filtering_enabled;
    $user->dns_filtering_enabled = $enabled;
    $user->save();

    return response()->json(['enabled' => $enabled]);
}
```

Remove the IP ownership check — the user's setting applies to all their IPs via the observer.

- [ ] **Step 3: Delete PiHoleController**

```bash
git rm app/Http/Controllers/Portal/PiHoleController.php tests/Feature/Portal/PiHoleControllerTest.php
```

Update routes to remove the PiHole controller route if it exists separately from the DnsFilter route.

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=DnsFilterControllerTest
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Portal/DnsFilterController.php tests/Feature/Portal/DnsFilterControllerTest.php
git rm app/Http/Controllers/Portal/PiHoleController.php tests/Feature/Portal/PiHoleControllerTest.php
git commit -m "refactor: simplify DnsFilterController, remove PiHoleController"
```

---

### Task 17: Update Admin Controllers

**Files:**
- Modify: `app/Http/Controllers/Admin/HomeController.php`
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`
- Modify corresponding test files

- [ ] **Step 1: Update HomeController**

In `app/Http/Controllers/Admin/HomeController.php`:
- `'onlineUsers'` query: change `where('allowed', true)` to `where('internet_enabled', true)`
- `'activeIps'` query: change `where('allowed', true)` to `where('internet_enabled', true)`
- `'blockedUsers'` query: change `where('blocked', true)` to `where('internet_blocked', true)`

- [ ] **Step 2: Update UserController**

In `app/Http/Controllers/Admin/UserController.php`:
- `edit()`: change `'blocked' => $user->blocked` to `'internet_blocked' => $user->internet_blocked`
- `block()`: change `$user->blocked = (int) $request->boolean('block')` to `$user->internet_blocked = $request->boolean('block')`. Update `if ($user->blocked !== 0)` to `if ($user->internet_blocked)`. Update messages.

- [ ] **Step 3: Update IpAddressController**

In `app/Http/Controllers/Admin/IpAddressController.php`:
- `index()`: rename `'allowed'` and `'limited'` in the `$orderBy` array to `'internet_enabled'` and `'rate_limit_enabled'`
- `limit()`: change `$ip->limit(true)` / `$ip->unlimit(true)` to set fields directly:
  ```php
  $ip->rate_limit_enabled = (bool) $request->input('limit');
  $ip->save();
  ```
- `internet()`: change `$ip->allow(true)` / `$ip->deny(true)` to set fields directly:
  ```php
  $ip->internet_enabled = (bool) $request->input('allow');
  $ip->save();
  ```
- `store()`: change `$ip->allow(true)` / `$ip->limit(true)` to set fields directly

- [ ] **Step 4: Update test files**

Update `tests/Feature/Admin/DashboardControllerTest.php`, `tests/Feature/Admin/UserControllerBlockValidationTest.php`, and any IpAddressController tests with the renamed fields and factory methods.

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter="Admin"
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ tests/Feature/Admin/
git commit -m "refactor: update admin controllers for renamed policy fields"
```

---

### Task 18: Update Existing Jobs and Commands

**Files:**
- Modify: `app/Jobs/GrantNetworkAccess.php`
- Modify: `app/Jobs/RevokeNetworkAccess.php`
- Modify: `app/Jobs/ReapplyAccessRules.php`
- Modify: `app/Jobs/ResetAperture.php`
- Modify: `app/Jobs/IpAddressAction.php`
- Modify: `app/Jobs/ScanNetworkDevices.php`
- Modify: `app/Console/Commands/ResetCommand.php`
- Modify: `app/Console/Commands/ExpireSessionsCommand.php`
- Modify: `app/Console/Commands/OpnSenseRecoveryCommand.php`
- Modify corresponding test files

- [ ] **Step 1: Update GrantNetworkAccess**

In `app/Jobs/GrantNetworkAccess.php`:
- Change `$this->ipAddress->allow()` to set the field directly:
  ```php
  $this->ipAddress->internet_enabled = true;
  $this->ipAddress->save();
  ```
  The observer will dispatch the backend job.

- [ ] **Step 2: Update RevokeNetworkAccess**

In `app/Jobs/RevokeNetworkAccess.php`:
- Change `$this->ipAddress->deny()` to:
  ```php
  $this->ipAddress->internet_enabled = false;
  $this->ipAddress->save();
  ```

- [ ] **Step 3: Update ReapplyAccessRules**

In `app/Jobs/ReapplyAccessRules.php`:
- Change `IpAddress::whereAllowed(true)` to `IpAddress::where('internet_enabled', true)`
- Change `$ip->allow()` to set field directly (observer handles backend)

- [ ] **Step 4: Update ResetAperture**

In `app/Jobs/ResetAperture.php`:
- Change `if ($ip->limited)` to `if ($ip->rate_limit_enabled)`
- Change `$ip->unlimit()` to `$ip->rate_limit_enabled = false; $ip->save();`
- Change `$ip->deny()` to `$ip->internet_enabled = false; $ip->save();`

- [ ] **Step 5: Update IpAddressAction**

In `app/Jobs/IpAddressAction.php`, this job calls methods dynamically on the IP model. Since `allow`, `deny`, `limit`, `unlimit` are removed, this job should only support remaining methods like `shutPort`, `unshutPort`. Update accordingly or remove if no longer needed.

- [ ] **Step 6: Update ScanNetworkDevices**

In `app/Jobs/ScanNetworkDevices.php`:
- Change `if (! $ip->allowed)` to `if (! $ip->internet_enabled)`

- [ ] **Step 7: Update ResetCommand**

In `app/Console/Commands/ResetCommand.php`:
- Change `if ($ip->limited)` to `if ($ip->rate_limit_enabled)`
- Change `$ip->unlimit()` to `$ip->rate_limit_enabled = false; $ip->saveQuietly();` (use `saveQuietly` to avoid observer dispatch during reset)
- Change `$ip->deny()` to `$ip->internet_enabled = false; $ip->saveQuietly();`

- [ ] **Step 8: Update ExpireSessionsCommand**

In `app/Console/Commands/ExpireSessionsCommand.php`:
- Change `if ($ip->limited)` to `if ($ip->rate_limit_enabled)`
- Change `$ip->unlimit()` to `$ip->rate_limit_enabled = false; $ip->saveQuietly();`
- Change `$ip->deny()` to `$ip->internet_enabled = false; $ip->saveQuietly();`

- [ ] **Step 9: Update OpnSenseRecoveryCommand**

In `app/Console/Commands/OpnSenseRecoveryCommand.php`:
- Change `$userIp->user->blocked` to `$userIp->user->internet_blocked`
- Change `$ip->allow()` to `$ip->internet_enabled = true; $ip->save();`

- [ ] **Step 10: Update all corresponding tests**

Update test files for all modified jobs and commands to use the new field names and factory methods.

- [ ] **Step 11: Run tests**

```bash
php artisan test --compact --filter="GrantNetworkAccess|RevokeNetworkAccess|ReapplyAccessRules|ResetAperture|IpAddressAction|ScanNetworkDevices|ResetCommand|ExpireSession|OpnSenseRecovery"
```

Expected: PASS

- [ ] **Step 12: Commit**

```bash
git add app/Jobs/ app/Console/Commands/ tests/
git commit -m "refactor: update all jobs and commands for renamed policy fields"
```

---

### Task 19: Update AppServiceProvider Binding

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `tests/Unit/Providers/AppServiceProviderTest.php`

- [ ] **Step 1: Update the binding**

In `app/Providers/AppServiceProvider.php`:

1. Change the import: `DnsBlockingInterface` → `DnsFilteringInterface`
2. Change the singleton return type: `DnsBlockingInterface` → `DnsFilteringInterface`
3. Change `noblock_group_id` to `filtered_group_id` in the config lookup:

```php
$this->app->singleton(function (Application $application): DnsFilteringInterface {
    $dbConfig = $this->getIntegrationDbConfig('pihole');
    $client = new Client([
        'verify' => (bool) ($dbConfig['verify_ssl'] ?? true),
        'base_uri' => $dbConfig['endpoint'] ?? '',
    ]);

    return new PiHoleService(
        $client,
        (string) ($dbConfig['password'] ?? ''),
        (int) ($dbConfig['filtered_group_id'] ?? 1),
    );
});
```

- [ ] **Step 2: Update the test**

In `tests/Unit/Providers/AppServiceProviderTest.php`:
- Change `DnsBlockingInterface` → `DnsFilteringInterface` in imports and assertions
- Change config key `noblock_group_id` → `filtered_group_id`

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=AppServiceProviderTest
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Unit/Providers/AppServiceProviderTest.php
git commit -m "refactor: update AppServiceProvider binding for DnsFilteringInterface"
```

---

### Task 20: Update Integration Config

**Files:**
- Modify: `config/integrations.php`
- Modify: `tests/Feature/Admin/IntegrationControllerTest.php`

- [ ] **Step 1: Update config**

In `config/integrations.php`, in the `'pihole'` section:
- Rename field key `'noblock_group_id'` to `'filtered_group_id'`
- Update label to `'Filtered Group'`
- Update `remote_url` to `/admin/settings/integrations/pihole/groups` (keep the same)
- Update validation rule key to `'filtered_group_id' => 'nullable|integer|min:0'`

- [ ] **Step 2: Update tests**

In `tests/Feature/Admin/IntegrationControllerTest.php`:
- Replace all `noblock_group_id` references with `filtered_group_id`

- [ ] **Step 3: Run tests**

```bash
php artisan test --compact --filter=IntegrationControllerTest
```

Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add config/integrations.php tests/Feature/Admin/IntegrationControllerTest.php
git commit -m "refactor: rename pihole config key noblock_group_id to filtered_group_id"
```

---

### Task 21: Create Reconciliation Commands

**Files:**
- Create: `app/Console/Commands/ReconcileInternetCommand.php`
- Create: `app/Console/Commands/ReconcileRateLimitsCommand.php`
- Create: `app/Console/Commands/ReconcileDnsFilteringCommand.php`
- Create: `tests/Feature/Console/ReconcileInternetCommandTest.php`
- Create: `tests/Feature/Console/ReconcileRateLimitsCommandTest.php`
- Create: `tests/Feature/Console/ReconcileDnsFilteringCommandTest.php`

- [ ] **Step 1: Write tests for ReconcileInternetCommand**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Interfaces\FirewallBackendInterface;
use App\Services\ValueObjects\ReconcileResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class ReconcileInternetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_calls_reconcile_internet(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(false)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: ['10.0.0.1'],
                    removed: ['10.0.0.2'],
                    unchanged: ['10.0.0.3'],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-internet')
            ->assertExitCode(0);
    }

    public function test_supports_dry_run(): void
    {
        $this->mock(FirewallBackendInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcileInternet')
                ->with(true)
                ->once()
                ->andReturn(new ReconcileResult(
                    added: [],
                    removed: [],
                    unchanged: [],
                    errors: [],
                ));
        });

        $this->artisan('aperture:reconcile-internet', ['--dry-run' => true])
            ->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Write tests for ReconcileRateLimitsCommand**

Follow same pattern as above but mock `reconcileRateLimits` on `FirewallBackendInterface`.

- [ ] **Step 3: Write tests for ReconcileDnsFilteringCommand**

Follow same pattern but mock `reconcile` on `DnsFilteringInterface`.

- [ ] **Step 4: Run tests to verify they fail**

```bash
php artisan test --compact --filter="Reconcile"
```

- [ ] **Step 5: Write ReconcileInternetCommand**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Interfaces\FirewallBackendInterface;
use Illuminate\Console\Command;

class ReconcileInternetCommand extends Command
{
    protected $signature = 'aperture:reconcile-internet {--dry-run}';

    protected $description = 'Reconcile internet access state with the firewall backend';

    public function handle(FirewallBackendInterface $firewall): int
    {
        $dryRun = $this->option('dry-run');
        $result = $firewall->reconcileInternet((bool) $dryRun);

        if ($dryRun) {
            $this->info('[DRY RUN] No changes applied.');
        }

        $this->info(sprintf('Added: %d, Removed: %d, Unchanged: %d, Errors: %d',
            count($result->added),
            count($result->removed),
            count($result->unchanged),
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Write ReconcileRateLimitsCommand**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Interfaces\FirewallBackendInterface;
use Illuminate\Console\Command;

class ReconcileRateLimitsCommand extends Command
{
    protected $signature = 'aperture:reconcile-rate-limits {--dry-run}';

    protected $description = 'Reconcile rate limit state with the firewall backend';

    public function handle(FirewallBackendInterface $firewall): int
    {
        $dryRun = $this->option('dry-run');
        $result = $firewall->reconcileRateLimits((bool) $dryRun);

        if ($dryRun) {
            $this->info('[DRY RUN] No changes applied.');
        }

        $this->info(sprintf('Added: %d, Removed: %d, Unchanged: %d, Errors: %d',
            count($result->added),
            count($result->removed),
            count($result->unchanged),
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Write ReconcileDnsFilteringCommand**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Interfaces\DnsFilteringInterface;
use Illuminate\Console\Command;

class ReconcileDnsFilteringCommand extends Command
{
    protected $signature = 'aperture:reconcile-dns-filtering {--dry-run}';

    protected $description = 'Reconcile DNS filtering state with PiHole';

    public function handle(DnsFilteringInterface $dnsFiltering): int
    {
        $dryRun = $this->option('dry-run');
        $result = $dnsFiltering->reconcile((bool) $dryRun);

        if ($dryRun) {
            $this->info('[DRY RUN] No changes applied.');
        }

        $this->info(sprintf('Added: %d, Removed: %d, Unchanged: %d, Errors: %d',
            count($result->added),
            count($result->removed),
            count($result->unchanged),
            count($result->errors),
        ));

        foreach ($result->errors as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Run tests**

```bash
php artisan test --compact --filter="Reconcile"
```

Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Console/Commands/Reconcile* tests/Feature/Console/Reconcile*
git commit -m "feat: add reconciliation commands for internet, rate limits, and DNS filtering"
```

---

### Task 22: Update Frontend — Vue Components

**Files:**
- Modify: `resources/js/Components/Blocks/ConnectionStripBlock.vue`
- Modify: `resources/js/Components/Blocks/ConnectionStatusBlock.vue`
- Modify: `resources/js/Pages/Admin/Dashboard.vue`
- Modify: `resources/js/Pages/Admin/Users/Show.vue`
- Modify: `resources/js/Pages/Admin/Users/Index.vue`
- Modify: `resources/js/Pages/Admin/Users/Edit.vue`
- Modify: `resources/views/portal.blade.php`
- Modify corresponding JS test files

- [ ] **Step 1: Update ConnectionStripBlock.vue**

Change `props.blockContext.ipAllowed` to `props.blockContext.internetEnabled` in both the `resolveValue` function and the template `v-if` class binding.

- [ ] **Step 2: Update ConnectionStatusBlock.vue**

Change `ipAllowed` prop references to use `internetEnabled` from blockContext.

- [ ] **Step 3: Update Admin Dashboard.vue**

Change `blockedUsers` prop to `internetBlockedUsers` (or keep as `blockedUsers` if the controller still sends that key — match the controller).

- [ ] **Step 4: Update Admin Users/Show.vue**

Change `user.blocked` references to `user.internet_blocked`. Update block/unblock toggle UI labels if needed.

- [ ] **Step 5: Update Admin Users/Index.vue**

Change `blocked` filter and column references to `internet_blocked`.

- [ ] **Step 6: Update Admin Users/Edit.vue**

Change `blocked` field reference to `internet_blocked`.

- [ ] **Step 7: Update portal.blade.php**

Change `Auth::user()->blocked` to `Auth::user()->internet_blocked`. Change `$ip->allowed` to `$ip->internet_enabled`.

- [ ] **Step 8: Update JS test files**

Update `tests/js/Components/BlockGrid.spec.js`, `tests/js/Pages/Admin/Dashboard.spec.js`, and any other JS test files that reference `ipAllowed`, `blocked`, or `blockedUsers`.

- [ ] **Step 9: Run ESLint and Prettier**

```bash
npm run lint
npm run format:check
```

Expected: PASS

- [ ] **Step 10: Run JS tests**

```bash
npm run test
```

Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add resources/js/ resources/views/ tests/js/
git commit -m "refactor: update frontend for renamed policy fields"
```

---

### Task 23: Update Remaining Tests

**Files:**
- Modify: `tests/Unit/Models/IpAddressDirectTest.php`
- Modify: `tests/Unit/Models/IpAddressTest.php`
- Modify: `tests/Unit/Jobs/IpAddressActionTest.php`
- Modify: `tests/Feature/MacTrackingIntegrationTest.php`
- Modify: `tests/Feature/GrantNetworkAccessJobTest.php`
- Modify: `tests/Feature/GrantNetworkAccessHandleTest.php`
- Modify: `tests/Feature/ReapplyAccessRulesJobTest.php`
- Modify: `tests/Feature/Jobs/ResetApertureJobTest.php`
- Modify: `tests/Feature/Jobs/ScanNetworkDevicesTest.php`
- Modify: Any other test files still referencing old field names

- [ ] **Step 1: Search for remaining old references**

```bash
grep -r "->allowed" tests/ --include="*.php" -l
grep -r "->limited" tests/ --include="*.php" -l
grep -r "->blocked" tests/ --include="*.php" -l
grep -r "ipAllowed" tests/ -l
grep -r "DnsBlockingInterface" tests/ -l
grep -r "'allowed'" tests/ --include="*.php" -l
grep -r "'limited'" tests/ --include="*.php" -l
grep -r "'blocked'" tests/ --include="*.php" -l
```

- [ ] **Step 2: Update all found files**

For each file found:
- `->allowed` → `->internet_enabled`
- `->limited` → `->rate_limit_enabled`
- `->blocked` → `->internet_blocked`
- `'allowed'` (in factory/query) → `'internet_enabled'`
- `'limited'` → `'rate_limit_enabled'`
- `'blocked'` → `'internet_blocked'`
- `DnsBlockingInterface` → `DnsFilteringInterface`
- `->allow()` → set field directly
- `->deny()` → set field directly
- `->limit()` → set field directly
- `->unlimit()` → set field directly
- `->blocked()` (factory state) → `->internetBlocked()`
- `->allowed()` (factory state) → `->internetEnabled()`

- [ ] **Step 3: Run full test suite**

```bash
php artisan test --compact
```

Expected: ALL PASS

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "refactor: update all remaining tests for renamed policy fields"
```

---

### Task 24: Run Full Quality Suite

**Files:** None (verification only)

- [ ] **Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse --no-progress
```

Expected: No errors

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --format agent
```

Expected: Pass

- [ ] **Step 3: Run Rector**

```bash
vendor/bin/rector process --dry-run
```

Expected: No suggestions

- [ ] **Step 4: Run ESLint**

```bash
npm run lint
```

Expected: No errors

- [ ] **Step 5: Run Prettier**

```bash
npm run format:check
```

Expected: All files formatted

- [ ] **Step 6: Run full PHP test suite**

```bash
php artisan test --compact
```

Expected: ALL PASS

- [ ] **Step 7: Run full JS test suite**

```bash
npm run test
```

Expected: ALL PASS

- [ ] **Step 8: Run Vite build**

```bash
npx vite build --mode development
```

Expected: Build succeeds

- [ ] **Step 9: Fix any issues found and commit**

```bash
git add -A
git commit -m "chore: fix quality issues from IP policy refactor"
```

---

### Task 25: Search for Any Remaining Old References

**Files:** Entire codebase

- [ ] **Step 1: Search for stale references**

```bash
grep -r "DnsBlockingInterface" app/ config/ --include="*.php" -l
grep -r "noblockGroupId\|noblock_group_id" app/ config/ --include="*.php" -l
grep -r "->allowed\b" app/ --include="*.php" -l
grep -r "->limited\b" app/ --include="*.php" -l
grep -r "->blocked\b" app/ --include="*.php" -l
grep -r "\$ip->allow\b\|\$ip->deny\b\|\$ip->limit\b\|\$ip->unlimit\b" app/ --include="*.php" -l
grep -r "ipAllowed" resources/ -l
```

- [ ] **Step 2: Fix any remaining references**

Update any files found in the search above.

- [ ] **Step 3: Re-run quality suite**

```bash
bash bin/quality.sh
```

Expected: ALL PASS

- [ ] **Step 4: Commit if changes made**

```bash
git add -A
git commit -m "chore: clean up remaining stale references from policy rename"
```

---

### Task 26: Regenerate IDE Helper

**Files:**
- Modify: `_ide_helper_models.php`

- [ ] **Step 1: Regenerate**

```bash
php artisan ide-helper:models --write --no-interaction
```

- [ ] **Step 2: Commit**

```bash
git add _ide_helper_models.php
git commit -m "chore: regenerate IDE helper models"
```
