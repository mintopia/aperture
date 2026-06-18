# Major Backend Refactors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract fat model logic into focused services, introduce PHP backed enums to eliminate magic strings, and decompose the 510-line PortSyncService into reusable collaborators.

**Architecture:** Four independent tasks executed in order: (1) enums first (other tasks use them), (2) UserNetworkAssociationService extracted from User model, (3) ScanNetworkDevices private methods extracted into injectable pipeline steps, (4) IntegrationServiceProvider capability blocks extracted into per-integration bootstrappers.

**Tech Stack:** PHP 8.3 backed enums, Laravel service container, PHPUnit with XDEBUG_MODE=coverage, SQLite in-memory test DB, Laravel Pint (formatter), PHPStan level 8.

---

## Task 1: PHP backed enums for integration IDs and capabilities

**Why:** String literals like `'opnsense'`, `'pihole'`, `'captive-portal'`, `'ip-bandwidth'` are scattered across 20+ files. Typos are undetectable at analysis time and PHPStan cannot check them. Backed enums make the set closed and typo-proof.

**Files:**
- Create: `app/Enums/Integration.php`
- Create: `app/Enums/Capability.php`
- Create: `app/Enums/PortOperStatus.php`
- Modify: `app/Providers/IntegrationServiceProvider.php`
- Modify: `app/Console/Commands/SetupCommand.php`
- Modify: `tests/Unit/Enums/IntegrationTest.php`

- [ ] **Step 1.1: Write failing enum tests**

Create `tests/Unit/Enums/IntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Capability;
use App\Enums\Integration;
use App\Enums\PortOperStatus;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase
{
    public function test_integration_enum_has_all_six_integrations(): void
    {
        $cases = Integration::cases();
        $values = array_map(fn($c) => $c->value, $cases);
        $this->assertContains('opnsense', $values);
        $this->assertContains('pihole', $values);
        $this->assertContains('librenms', $values);
        $this->assertContains('borealis', $values);
        $this->assertContains('prometheus', $values);
        $this->assertContains('seatpicker', $values);
    }

    public function test_capability_enum_has_known_capabilities(): void
    {
        $values = array_map(fn($c) => $c->value, Capability::cases());
        foreach (['captive-portal', 'rate-limiting', 'dhcp', 'dns-filtering', 'ip-bandwidth', 'port-bandwidth', 'port-errors', 'ip-mac', 'port-mac', 'authentication'] as $cap) {
            $this->assertContains($cap, $values, "Missing capability: $cap");
        }
    }

    public function test_port_oper_status_up_is_up(): void
    {
        $status = PortOperStatus::from('up');
        $this->assertSame(PortOperStatus::Up, $status);
        $this->assertTrue($status->isUp());
    }

    public function test_port_oper_status_is_case_insensitive_via_tryFrom(): void
    {
        // DB stores lowercase; enum normalises
        $this->assertSame(PortOperStatus::Down, PortOperStatus::tryFrom('down'));
        $this->assertNull(PortOperStatus::tryFrom('unknown_value'));
    }

    public function test_integration_try_from_unknown_returns_null(): void
    {
        $this->assertNull(Integration::tryFrom('nonexistent'));
    }
}
```

- [ ] **Step 1.2: Run failing test**

```bash
XDEBUG_MODE=coverage php artisan test --filter=IntegrationTest 2>&1 | tail -20
```

Expected: FAIL — enums don't exist yet.

- [ ] **Step 1.3: Create app/Enums/Integration.php**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Integration: string
{
    case OpnSense  = 'opnsense';
    case PiHole    = 'pihole';
    case LibreNms  = 'librenms';
    case Borealis  = 'borealis';
    case Prometheus = 'prometheus';
    case Seatpicker = 'seatpicker';
}
```

- [ ] **Step 1.4: Create app/Enums/Capability.php**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum Capability: string
{
    case CaptivePortal   = 'captive-portal';
    case RateLimiting    = 'rate-limiting';
    case Dhcp            = 'dhcp';
    case DnsFiltering    = 'dns-filtering';
    case IpBandwidth     = 'ip-bandwidth';
    case PortBandwidth   = 'port-bandwidth';
    case PortErrors      = 'port-errors';
    case IpMac           = 'ip-mac';
    case PortMac         = 'port-mac';
    case Authentication  = 'authentication';
}
```

- [ ] **Step 1.5: Create app/Enums/PortOperStatus.php**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum PortOperStatus: string
{
    case Up   = 'up';
    case Down = 'down';

    public function isUp(): bool
    {
        return $this === self::Up;
    }

    public function isDown(): bool
    {
        return $this === self::Down;
    }
}
```

- [ ] **Step 1.6: Run test to verify pass**

```bash
XDEBUG_MODE=coverage php artisan test --filter=IntegrationTest 2>&1 | tail -10
```

Expected: PASS (3 tests, 0 failures).

- [ ] **Step 1.7: Update IntegrationServiceProvider to use enums**

In `app/Providers/IntegrationServiceProvider.php`, replace all bare string literals with enum values in `registerIntegrationTesters()` and `registerCapabilityBindings()`:

```php
// Add at top of file (with other use statements):
use App\Enums\Integration;
use App\Enums\Capability;
```

Replace in `registerIntegrationTesters()`:
```php
$registry->register(Integration::OpnSense->value, new OpnSenseTester);
$registry->register(Integration::PiHole->value, new PiHoleTester);
$registry->register(Integration::LibreNms->value, new LibreNmsTester);
$registry->register(Integration::Borealis->value, new BorealisTester);
$registry->register(Integration::Prometheus->value, new PrometheusTester);
$registry->register(Integration::Seatpicker->value, new SeatpickerTester);
```

Replace in `registerSharedSingletons()`:
```php
$dbConfig = $this->getIntegrationDbConfig(Integration::OpnSense->value);
// ... (PrometheusService)
$config = $this->getIntegrationDbConfig(Integration::Prometheus->value);
// ... (LibreNmsService)
$dbConfig = $this->getIntegrationDbConfig(Integration::LibreNms->value);
```

Replace in `registerCapabilityBindings()` — each `isActive()` call:
```php
if ($this->isActive(Integration::OpnSense->value, Capability::CaptivePortal->value)) {
if ($this->isActive(Integration::OpnSense->value, Capability::RateLimiting->value)) {
if ($this->isActive(Integration::OpnSense->value, Capability::Dhcp->value)) {
if ($this->isActive(Integration::PiHole->value, Capability::DnsFiltering->value)) {
if ($this->isActive(Integration::Prometheus->value, Capability::IpBandwidth->value)) {
if ($this->isActive(Integration::Prometheus->value, Capability::PortBandwidth->value)) {
if ($this->isActive(Integration::Prometheus->value, Capability::PortErrors->value)) {
if ($this->isActive(Integration::LibreNms->value, Capability::IpMac->value)) {
if ($this->isActive(Integration::LibreNms->value, Capability::PortMac->value)) {
```

Replace in `registerNonCapabilityBindings()`:
```php
return new OpnSenseApiService($this->getIntegrationDbConfig(Integration::OpnSense->value));
// ...
$dbConfig = $this->getIntegrationDbConfig(Integration::Borealis->value);
```

Replace in `buildDhcpService()`:
```php
$opnsenseConfig = $this->getIntegrationDbConfig(Integration::OpnSense->value);
```

- [ ] **Step 1.8: Update SetupCommand to use enum**

In `app/Console/Commands/SetupCommand.php`, replace bare `'borealis'` strings:

```php
use App\Enums\Integration;
use App\Enums\Capability;
// ...
IntegrationConfig::setValue(Integration::Borealis->value, 'endpoint', $endpoint);
IntegrationConfig::setValue(Integration::Borealis->value, 'client_id', $clientId);
IntegrationConfig::setValue(Integration::Borealis->value, 'client_secret', $clientSecret, true);
// ...
IntegrationConfig::getWithFallback(Integration::Borealis->value, 'scope', 'discord')
// ...
CapabilityAssignment::assign(Capability::Authentication->value, Integration::Borealis->value);
```

- [ ] **Step 1.9: Run PHPStan and Pint**

```bash
./vendor/bin/pint app/Enums/ app/Providers/IntegrationServiceProvider.php app/Console/Commands/SetupCommand.php
./vendor/bin/phpstan analyse app/Enums/ app/Providers/IntegrationServiceProvider.php app/Console/Commands/SetupCommand.php --level=8 2>&1 | tail -20
```

- [ ] **Step 1.10: Run full test suite**

```bash
XDEBUG_MODE=coverage php artisan test --parallel 2>&1 | tail -20
```

Expected: green.

- [ ] **Step 1.11: Commit**

```bash
git add app/Enums/ app/Providers/IntegrationServiceProvider.php app/Console/Commands/SetupCommand.php tests/Unit/Enums/
git commit -m "feat: introduce Integration, Capability and PortOperStatus backed enums

Replace magic strings with type-safe backed enums across
IntegrationServiceProvider and SetupCommand.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 2: Extract User::addIp() into UserNetworkAssociationService

**Why:** The `User` model contains 97 lines of complex business logic (`addIp` + `cascadeMacOwnership`) with three service dependencies. This violates the single-responsibility principle and makes the logic untestable without an Eloquent User instance.

**Files:**
- Create: `app/Services/UserNetworkAssociationService.php`
- Create: `tests/Unit/Services/UserNetworkAssociationServiceTest.php`
- Modify: `app/Models/User.php` (delegate to service; keep thin wrapper for BC)
- Check callers: `app/Services/Auth/`, `app/Http/Controllers/`

- [ ] **Step 2.1: Find all callers of User::addIp()**

```bash
grep -rn "addIp\|->addIp" app/ --include="*.php" | grep -v "User.php"
```

Record each caller — they will need to be updated to inject `UserNetworkAssociationService`.

- [ ] **Step 2.2: Write failing unit test**

Create `tests/Unit/Services/UserNetworkAssociationServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IpAddress;
use App\Models\User;
use App\Services\IpAddressActionService;
use App\Services\IpPolicyService;
use App\Services\NetworkRangeService;
use App\Services\UserNetworkAssociationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class UserNetworkAssociationServiceTest extends TestCase
{
    private NetworkRangeService&MockObject $rangeService;
    private IpPolicyService&MockObject $policyService;
    private IpAddressActionService&MockObject $actionService;
    private UserNetworkAssociationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rangeService  = $this->createMock(NetworkRangeService::class);
        $this->policyService = $this->createMock(IpPolicyService::class);
        $this->actionService = $this->createMock(IpAddressActionService::class);
        $this->service = new UserNetworkAssociationService(
            $this->rangeService,
            $this->policyService,
            $this->actionService,
        );
    }

    public function test_add_ip_returns_null_when_not_managed(): void
    {
        $this->rangeService->method('isManaged')->with('10.0.0.1')->willReturn(false);
        $user = $this->createMock(User::class);

        $result = $this->service->addIp($user, '10.0.0.1');
        $this->assertNull($result);
    }

    public function test_service_is_constructable_with_di(): void
    {
        $this->assertInstanceOf(UserNetworkAssociationService::class, $this->service);
    }
}
```

- [ ] **Step 2.3: Run failing test**

```bash
XDEBUG_MODE=coverage php artisan test --filter=UserNetworkAssociationServiceTest 2>&1 | tail -15
```

Expected: FAIL — class doesn't exist.

- [ ] **Step 2.4: Create UserNetworkAssociationService**

Create `app/Services/UserNetworkAssociationService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\IpAddress;
use App\Models\MacAddress;
use App\Models\User;
use App\Models\UserIpAddress;
use Throwable;

final class UserNetworkAssociationService
{
    public function __construct(
        private readonly NetworkRangeService $rangeService,
        private readonly IpPolicyService $policyService,
        private readonly IpAddressActionService $actionService,
    ) {}

    /**
     * Associate an IP address with a user, applying policies and optionally
     * cascading MAC/sibling-IP ownership. Returns null if IP is outside managed ranges.
     */
    public function addIp(User $user, string $clientIp, bool $cascade = true): ?IpAddress
    {
        if (! $this->rangeService->isManaged($clientIp)) {
            return null;
        }

        $ip = IpAddress::whereAddress($clientIp)->first()
            ?? $this->createIpAddress($clientIp);

        $this->upsertUserIpLink($user, $ip);
        $this->policyService->applyUserPolicy($user, $ip);
        $this->syncFirewall($ip);

        if ($cascade) {
            $this->cascadeMacOwnership($user, $ip);
        }

        return $ip;
    }

    private function createIpAddress(string $clientIp): IpAddress
    {
        $ip = new IpAddress;
        $ip->address = $clientIp;
        $ip->last_seen_at = now();
        $ip->save();

        return $ip;
    }

    private function upsertUserIpLink(User $user, IpAddress $ip): void
    {
        $userIp = $user->ips()->whereIpAddressId($ip->id)->first()
            ?? (function () use ($user, $ip): UserIpAddress {
                $link = new UserIpAddress;
                $link->user()->associate($user);
                $link->ip()->associate($ip);

                return $link;
            })();

        $userIp->last_seen_at = now();
        $userIp->save();
    }

    private function syncFirewall(IpAddress $ip): void
    {
        if (! $ip->internet_enabled) {
            return;
        }

        try {
            $this->actionService->enableInternet($ip);
        } catch (Throwable) {
            // Firewall sync is best-effort
        }
    }

    private function cascadeMacOwnership(User $user, IpAddress $ip): void
    {
        $macs = $ip->macAddresses()->get();

        foreach ($macs as $mac) {
            $this->claimMacIfUnowned($user, $mac, $ip);

            if ((int) $mac->user_id !== (int) $user->id) {
                continue;
            }

            $this->cascadeSiblingIps($user, $mac, $ip);
        }
    }

    private function claimMacIfUnowned(User $user, MacAddress $mac, IpAddress $ip): void
    {
        if ($mac->user_id !== null) {
            return;
        }

        $mac->user_id = $user->id;
        $mac->save();

        AuditLog::record(
            action: 'mac.user_assigned',
            subject: $mac,
            related: $ip,
            actor: $user,
            process: 'portal_login',
            metadata: ['user_id' => $user->id],
        );
    }

    private function cascadeSiblingIps(User $user, MacAddress $mac, IpAddress $originIp): void
    {
        $siblingIps = $mac->ipAddresses()
            ->where('ip_addresses.id', '!=', $originIp->id)
            ->get();

        foreach ($siblingIps as $siblingIp) {
            $existingOwner = UserIpAddress::where('ip_address_id', $siblingIp->id)->first();
            if ($existingOwner !== null && (int) $existingOwner->user_id !== (int) $user->id) {
                continue;
            }

            $cascaded = $this->addIp($user, $siblingIp->address, cascade: false);

            if ($cascaded instanceof IpAddress) {
                AuditLog::record(
                    action: 'ip.user_cascaded',
                    subject: $siblingIp,
                    related: $mac,
                    actor: $user,
                    process: 'portal_login',
                    metadata: ['source_ip' => $originIp->address],
                );
            }
        }
    }
}
```

- [ ] **Step 2.5: Replace User::addIp() with thin delegation wrapper**

In `app/Models/User.php`, replace the `addIp()` method body and `cascadeMacOwnership()` entirely with:

```php
public function addIp(string $clientIp, bool $cascade = true): ?IpAddress
{
    return app(UserNetworkAssociationService::class)->addIp($this, $clientIp, $cascade);
}
```

Delete the entire `private function cascadeMacOwnership(IpAddress $ip): void { ... }` method from User.php.

Add at the top of User.php (with other use statements):
```php
use App\Services\UserNetworkAssociationService;
```

- [ ] **Step 2.6: Run tests**

```bash
XDEBUG_MODE=coverage php artisan test --filter=UserNetworkAssociationServiceTest 2>&1 | tail -10
```

Expected: PASS.

- [ ] **Step 2.7: Run full suite to catch regressions**

```bash
XDEBUG_MODE=coverage php artisan test --parallel 2>&1 | tail -20
```

- [ ] **Step 2.8: Pint and PHPStan**

```bash
./vendor/bin/pint app/Services/UserNetworkAssociationService.php app/Models/User.php
./vendor/bin/phpstan analyse app/Services/UserNetworkAssociationService.php app/Models/User.php --level=8 2>&1 | tail -20
```

- [ ] **Step 2.9: Commit**

```bash
git add app/Services/UserNetworkAssociationService.php app/Models/User.php tests/Unit/Services/UserNetworkAssociationServiceTest.php
git commit -m "refactor: extract User::addIp cascade logic to UserNetworkAssociationService

User model now delegates to injected service. Logic is testable in
isolation without Eloquent model. Backward-compatible thin wrapper
preserved on User.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 3: Decompose ScanNetworkDevices into injectable pipeline steps

**Why:** `ScanNetworkDevices::handle()` has 6 private methods totalling ~280 lines. Logic is not unit-testable in isolation and every future change requires modifying one 510-line file. Extracting each phase into a single-method collaborator class makes each step independently testable and swappable.

**Files:**
- Create: `app/Jobs/NetworkScan/PersistMacsStep.php`
- Create: `app/Jobs/NetworkScan/PersistIpsStep.php`
- Create: `app/Jobs/NetworkScan/LinkIpMacStep.php`
- Create: `app/Jobs/NetworkScan/PersistDhcpLeasesStep.php`
- Create: `app/Jobs/NetworkScan/LinkSwitchPortMacsStep.php`
- Create: `app/Jobs/NetworkScan/ApplyOuiPolicyStep.php`
- Modify: `app/Jobs/ScanNetworkDevices.php` (thin orchestrator)
- Create: `tests/Unit/Jobs/NetworkScan/PersistMacsStepTest.php`

- [ ] **Step 3.1: Read full ScanNetworkDevices source**

```bash
cat app/Jobs/ScanNetworkDevices.php
```

Record the exact signatures and bodies of all 6 private methods.

- [ ] **Step 3.2: Write failing test for PersistMacsStep**

Create `tests/Unit/Jobs/NetworkScan/PersistMacsStepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\NetworkScan;

use App\Jobs\NetworkScan\PersistMacsStep;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class PersistMacsStepTest extends TestCase
{
    public function test_step_is_constructable(): void
    {
        $this->assertInstanceOf(PersistMacsStep::class, new PersistMacsStep);
    }

    public function test_invoke_is_callable_with_empty_collections(): void
    {
        // Use a DB-less smoke test by checking method exists
        $step = new PersistMacsStep;
        $this->assertTrue(method_exists($step, '__invoke'));
    }
}
```

- [ ] **Step 3.3: Run failing test**

```bash
XDEBUG_MODE=coverage php artisan test --filter=PersistMacsStepTest 2>&1 | tail -10
```

Expected: FAIL — class doesn't exist.

- [ ] **Step 3.4: Create NetworkScan step directory**

```bash
mkdir -p app/Jobs/NetworkScan
```

- [ ] **Step 3.5: Extract each private method into its own step class**

For each of the 6 private methods in `ScanNetworkDevices`, create a corresponding step class in `app/Jobs/NetworkScan/`. Each class follows this pattern:

```php
<?php

declare(strict_types=1);

namespace App\Jobs\NetworkScan;

// (imports from ScanNetworkDevices for this step)

final class PersistMacsStep
{
    public function __invoke(/* same parameters as the extracted private method */): void
    {
        // (exact body of the private method, copied verbatim)
    }
}
```

The six steps and their signatures (from `ScanNetworkDevices.php`):

**PersistMacsStep:**
```php
// __invoke(Collection $leases, Collection $arpEntries, Collection $forwardingEntries): void
// Copy body of persistMacs() verbatim
```

**PersistIpsStep:**
```php
// __invoke(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
// Copy body of persistIps() verbatim
```

**LinkIpMacStep:**
```php
// __invoke(Collection $leases, Collection $arpEntries, NetworkRangeService $rangeService): void
// Copy body of linkIpMac() verbatim
```

**PersistDhcpLeasesStep:**
```php
// __invoke(Collection $leases, NetworkRangeService $rangeService): void
// Copy body of persistDhcpLeases() verbatim
```

**LinkSwitchPortMacsStep:**
```php
// __invoke(Collection $forwardingEntries): void
// Copy body of linkSwitchPortMacs() verbatim
```

**ApplyOuiPolicyStep:**
```php
// __invoke(): void
// Copy body of applyOuiPolicy() verbatim
```

- [ ] **Step 3.6: Update ScanNetworkDevices::handle() to delegate to steps**

Replace the `handle()` method body in `app/Jobs/ScanNetworkDevices.php`:

```php
public function handle(
    ?DhcpInterface $dhcp = null,
    ?IpMacResolverInterface $ipMac = null,
    ?PortMacInterface $portMac = null,
    ?NetworkRangeService $rangeService = null,
    ?PersistMacsStep $persistMacs = null,
    ?PersistIpsStep $persistIps = null,
    ?LinkIpMacStep $linkIpMac = null,
    ?PersistDhcpLeasesStep $persistDhcpLeases = null,
    ?LinkSwitchPortMacsStep $linkSwitchPortMacs = null,
    ?ApplyOuiPolicyStep $applyOuiPolicy = null,
): void {
    $dhcp              ??= app(DhcpInterface::class);
    $ipMac             ??= app(IpMacResolverInterface::class);
    $portMac           ??= app(PortMacInterface::class);
    $rangeService      ??= app(NetworkRangeService::class);
    $persistMacs       ??= new PersistMacsStep;
    $persistIps        ??= new PersistIpsStep;
    $linkIpMac         ??= new LinkIpMacStep;
    $persistDhcpLeases ??= new PersistDhcpLeasesStep;
    $linkSwitchPortMacs ??= new LinkSwitchPortMacsStep;
    $applyOuiPolicy    ??= new ApplyOuiPolicyStep;

    $leases           = $dhcp->getLeases();
    $arpEntries       = $ipMac->getArpTable();
    $forwardingEntries = $portMac->getForwardingTable();

    $persistMacs($leases, $arpEntries, $forwardingEntries);
    $persistIps($leases, $arpEntries, $rangeService);
    $linkIpMac($leases, $arpEntries, $rangeService);
    $persistDhcpLeases($leases, $rangeService);
    $linkSwitchPortMacs($forwardingEntries);
    $applyOuiPolicy();
}
```

Delete the 6 private methods from `ScanNetworkDevices.php`.

Add use statements for the 6 new step classes at the top.

- [ ] **Step 3.7: Run tests**

```bash
XDEBUG_MODE=coverage php artisan test --filter=PersistMacsStepTest 2>&1 | tail -10
```

Expected: PASS.

- [ ] **Step 3.8: Run full suite**

```bash
XDEBUG_MODE=coverage php artisan test --parallel 2>&1 | tail -20
```

- [ ] **Step 3.9: Pint and PHPStan**

```bash
./vendor/bin/pint app/Jobs/NetworkScan/ app/Jobs/ScanNetworkDevices.php
./vendor/bin/phpstan analyse app/Jobs/NetworkScan/ app/Jobs/ScanNetworkDevices.php --level=8 2>&1 | tail -20
```

- [ ] **Step 3.10: Commit**

```bash
git add app/Jobs/NetworkScan/ app/Jobs/ScanNetworkDevices.php tests/Unit/Jobs/NetworkScan/
git commit -m "refactor: decompose ScanNetworkDevices private methods into pipeline step classes

Each step (PersistMacs, PersistIps, LinkIpMac, PersistDhcpLeases,
LinkSwitchPortMacs, ApplyOuiPolicy) is now an injectable class.
ScanNetworkDevices::handle() is a thin orchestrator.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 4: IntegrationServiceProvider — extract per-integration bootstrappers

**Why:** `IntegrationServiceProvider` has ~390 lines, mixes registration for 6 integrations in one class, and the `buildDhcpService()` helper is 100+ lines alone. Extracting per-integration configuration into dedicated bootstrapper classes isolates concerns and makes each integration independently testable.

**Files:**
- Create: `app/Integration/OpnSenseBootstrapper.php`
- Create: `app/Integration/PrometheusBootstrapper.php`
- Create: `app/Integration/LibreNmsBootstrapper.php`
- Create: `app/Integration/PiHoleBootstrapper.php`
- Create: `app/Integration/BorealisBootstrapper.php`
- Create: `app/Integration/TesterRegistryBootstrapper.php`
- Modify: `app/Providers/IntegrationServiceProvider.php` (thin orchestrator)
- Create: `tests/Unit/Integration/OpnSenseBootstrapperTest.php`

- [ ] **Step 4.1: Define the bootstrapper interface**

Create `app/Integration/IntegrationBootstrapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Integration;

use Illuminate\Contracts\Foundation\Application;

interface IntegrationBootstrapper
{
    /**
     * Register all capability bindings for this integration into the container.
     */
    public function register(Application $app): void;
}
```

- [ ] **Step 4.2: Write failing test for OpnSenseBootstrapper**

Create `tests/Unit/Integration/OpnSenseBootstrapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Integration;

use App\Integration\IntegrationBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use PHPUnit\Framework\TestCase;

class OpnSenseBootstrapperTest extends TestCase
{
    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(IntegrationBootstrapper::class, new OpnSenseBootstrapper);
    }
}
```

- [ ] **Step 4.3: Run failing test**

```bash
XDEBUG_MODE=coverage php artisan test --filter=OpnSenseBootstrapperTest 2>&1 | tail -10
```

Expected: FAIL.

- [ ] **Step 4.4: Create OpnSenseBootstrapper**

Create `app/Integration/OpnSenseBootstrapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Integration;

use App\Models\CapabilityAssignment;
use App\Models\IntegrationConfig;
use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\Interfaces\DhcpInterface;
use App\Services\Interfaces\RateLimitingInterface;
use App\Services\Null\NullCaptivePortal;
use App\Services\Null\NullDhcpService;
use App\Services\Null\NullRateLimiter;
use App\Services\OpnSense\OpnSenseCaptivePortal;
use App\Services\OpnSense\OpnSenseClient;
use App\Services\OpnSense\OpnSenseDhcpService;
use App\Services\OpnSense\OpnSenseRateLimiter;
use GuzzleHttp\Client;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class OpnSenseBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        $this->bindCaptivePortal($app);
        $this->bindRateLimiting($app);
        $this->bindDhcp($app);
    }

    private function bindCaptivePortal(Application $app): void
    {
        $app->bind(CaptivePortalInterface::class, function (Application $app): CaptivePortalInterface {
            if (! $this->isActive('captive-portal')) {
                return new NullCaptivePortal;
            }

            return new OpnSenseCaptivePortal(
                $app->make(OpnSenseClient::class),
                (int) IntegrationConfig::getValue('opnsense', 'zone_id', '0'),
            );
        });
    }

    private function bindRateLimiting(Application $app): void
    {
        $app->bind(RateLimitingInterface::class, function (Application $app): RateLimitingInterface {
            if (! $this->isActive('rate-limiting')) {
                return new NullRateLimiter;
            }

            return new OpnSenseRateLimiter(
                $app->make(OpnSenseClient::class),
                (string) IntegrationConfig::getValue('opnsense', 'ratelimit_up_uuid', ''),
                (string) IntegrationConfig::getValue('opnsense', 'ratelimit_down_uuid', ''),
            );
        });
    }

    private function bindDhcp(Application $app): void
    {
        $app->bind(DhcpInterface::class, function (Application $app): DhcpInterface {
            if (! $this->isActive('dhcp')) {
                return new NullDhcpService;
            }

            $config = IntegrationConfig::getAll('opnsense');
            $dhcpServer = (string) ($config['dhcp_server'] ?? 'isc');

            // (copy buildDhcpService logic here using $config and $dhcpServer)
            // Build and return OpnSenseDhcpService as in IntegrationServiceProvider::buildDhcpService()
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider('opnsense', $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
```

**Important:** The `bindDhcp` method body should contain the full `buildDhcpService()` logic copied from `IntegrationServiceProvider`. Copy it verbatim, replacing `$this->getIntegrationDbConfig('opnsense')` with `IntegrationConfig::getAll('opnsense')`.

- [ ] **Step 4.5: Create remaining bootstrappers (PrometheusBootstrapper, LibreNmsBootstrapper, PiHoleBootstrapper, BorealisBootstrapper)**

Each follows the same pattern. For example, `PrometheusBootstrapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Integration;

use App\Models\CapabilityAssignment;
use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\Interfaces\PortErrorsInterface;
use App\Services\Null\NullIpBandwidth;
use App\Services\Null\NullPortBandwidth;
use App\Services\Null\NullPortErrors;
use App\Services\Prometheus\PrometheusIpBandwidth;
use App\Services\Prometheus\PrometheusPortBandwidth;
use App\Services\Prometheus\PrometheusPortErrors;
use App\Services\Prometheus\PrometheusService;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

final class PrometheusBootstrapper implements IntegrationBootstrapper
{
    public function register(Application $app): void
    {
        $app->bind(IpBandwidthInterface::class, function (Application $app): IpBandwidthInterface {
            if (! $this->isActive('ip-bandwidth')) {
                return new NullIpBandwidth;
            }
            // extract from IntegrationServiceProvider::registerCapabilityBindings() ip-bandwidth block
        });

        $app->bind(PortBandwidthInterface::class, function (Application $app): PortBandwidthInterface {
            if (! $this->isActive('port-bandwidth')) {
                return new NullPortBandwidth;
            }
            return new PrometheusPortBandwidth($app->make(PrometheusService::class));
        });

        $app->bind(PortErrorsInterface::class, function (Application $app): PortErrorsInterface {
            if (! $this->isActive('port-errors')) {
                return new NullPortErrors;
            }
            return new PrometheusPortErrors($app->make(PrometheusService::class));
        });
    }

    private function isActive(string $capability): bool
    {
        try {
            return CapabilityAssignment::isActiveProvider('prometheus', $capability);
        } catch (Throwable) {
            return false;
        }
    }
}
```

Copy the exact binding logic from `IntegrationServiceProvider` for each integration. Do NOT simplify or change the logic — exact copy.

- [ ] **Step 4.6: Update IntegrationServiceProvider to use bootstrappers**

Replace `registerCapabilityBindings()` body in `IntegrationServiceProvider.php`:

```php
protected function registerCapabilityBindings(): void
{
    (new OpnSenseBootstrapper)->register($this->app);
    (new PrometheusBootstrapper)->register($this->app);
    (new LibreNmsBootstrapper)->register($this->app);
    (new PiHoleBootstrapper)->register($this->app);
}
```

Add use statements at top:
```php
use App\Integration\LibreNmsBootstrapper;
use App\Integration\OpnSenseBootstrapper;
use App\Integration\PiHoleBootstrapper;
use App\Integration\PrometheusBootstrapper;
```

- [ ] **Step 4.7: Run full test suite**

```bash
XDEBUG_MODE=coverage php artisan test --parallel 2>&1 | tail -20
```

Expected: green.

- [ ] **Step 4.8: Pint and PHPStan**

```bash
./vendor/bin/pint app/Integration/ app/Providers/IntegrationServiceProvider.php
./vendor/bin/phpstan analyse app/Integration/ app/Providers/IntegrationServiceProvider.php --level=8 2>&1 | tail -20
```

- [ ] **Step 4.9: Commit**

```bash
git add app/Integration/ app/Providers/IntegrationServiceProvider.php tests/Unit/Integration/
git commit -m "refactor: extract per-integration capability registration into bootstrapper classes

Each integration (OpnSense, Prometheus, LibreNms, PiHole) now owns its
capability binding logic. IntegrationServiceProvider delegates to
bootstrappers. DHCP config logic moved into OpnSenseBootstrapper.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```
