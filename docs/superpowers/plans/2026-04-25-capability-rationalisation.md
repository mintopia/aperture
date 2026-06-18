# Capability Rationalisation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rationalise the capability system so every capability has a dedicated interface, a null provider, and follows LSP. Remove ntopng. Gate all bindings through `CapabilityAssignment`.

**Architecture:** Split monolithic interfaces (`FirewallBackendInterface`, `MetricsProviderInterface`, `NetworkInventoryInterface`) into single-responsibility capability interfaces. Extract shared HTTP clients (`OpnSenseClient`). Rename `TrafficMonitorInterface` to `IpBandwidthInterface`. Remove ntopng entirely. Every capability binding gated by `CapabilityAssignment::isActiveProvider()`.

**Tech Stack:** Laravel 12, PHP 8.5, PHPUnit 11, PHPStan Level 8, Laravel Pint

**Spec:** `docs/superpowers/specs/2026-04-25-capability-rationalisation-design.md`

---

## Phase 1: New Value Objects & Interfaces

Create all new interfaces and value objects first. No existing code changes — purely additive.

### Task 1: Create `PortTimeSeries` Value Object

**Files:**
- Create: `app/Services/ValueObjects/PortTimeSeries.php`
- Create: `tests/Unit/Services/ValueObjects/PortTimeSeriesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\PortTimeSeries;
use PHPUnit\Framework\TestCase;

class PortTimeSeriesTest extends TestCase
{
    public function test_constructs_with_in_and_out_arrays(): void
    {
        $in = [['timestamp' => 1.0, 'value' => 100.0]];
        $out = [['timestamp' => 1.0, 'value' => 50.0]];

        $series = new PortTimeSeries(in: $in, out: $out);

        $this->assertSame($in, $series->in);
        $this->assertSame($out, $series->out);
    }

    public function test_constructs_with_empty_arrays(): void
    {
        $series = new PortTimeSeries(in: [], out: []);

        $this->assertSame([], $series->in);
        $this->assertSame([], $series->out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=PortTimeSeriesTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class PortTimeSeries
{
    /**
     * @param  array<int, array{timestamp: float, value: float}>  $in
     * @param  array<int, array{timestamp: float, value: float}>  $out
     */
    public function __construct(
        public array $in,
        public array $out,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=PortTimeSeriesTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ValueObjects/PortTimeSeries.php tests/Unit/Services/ValueObjects/PortTimeSeriesTest.php
git commit -m "feat: add PortTimeSeries value object"
```

### Task 2: Rename `UserBandwidth` to `IpBandwidthResult`

**Files:**
- Create: `app/Services/ValueObjects/IpBandwidthResult.php`
- Create: `tests/Unit/Services/ValueObjects/IpBandwidthResultTest.php`

This is created as a new file alongside the old one. Consumers are migrated in later tasks. The old file is deleted in the cleanup phase.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\IpBandwidthResult;
use PHPUnit\Framework\TestCase;

class IpBandwidthResultTest extends TestCase
{
    public function test_constructs_with_all_fields(): void
    {
        $result = new IpBandwidthResult(
            received: 1024,
            sent: 512,
            timestamps: ['1000', '1060'],
            download: [100.0, 200.0],
            upload: [50.0, 75.0],
        );

        $this->assertSame(1024, $result->received);
        $this->assertSame(512, $result->sent);
        $this->assertSame(['1000', '1060'], $result->timestamps);
        $this->assertSame([100.0, 200.0], $result->download);
        $this->assertSame([50.0, 75.0], $result->upload);
    }

    public function test_constructs_with_empty_arrays(): void
    {
        $result = new IpBandwidthResult(
            received: 0,
            sent: 0,
            timestamps: [],
            download: [],
            upload: [],
        );

        $this->assertSame(0, $result->received);
        $this->assertSame([], $result->timestamps);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=IpBandwidthResultTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueObjects;

readonly class IpBandwidthResult
{
    /**
     * @param  array<int, string>  $timestamps
     * @param  array<int, float>  $download
     * @param  array<int, float>  $upload
     */
    public function __construct(
        public int $received,
        public int $sent,
        public array $timestamps,
        public array $download,
        public array $upload,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=IpBandwidthResultTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/ValueObjects/IpBandwidthResult.php tests/Unit/Services/ValueObjects/IpBandwidthResultTest.php
git commit -m "feat: add IpBandwidthResult value object (replaces UserBandwidth)"
```

### Task 3: Create `CaptivePortalInterface` and `NullCaptivePortal`

**Files:**
- Create: `app/Services/Interfaces/CaptivePortalInterface.php`
- Create: `app/Services/Null/NullCaptivePortal.php`
- Create: `tests/Unit/Services/Null/NullCaptivePortalTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullCaptivePortal;
use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class NullCaptivePortalTest extends TestCase
{
    private NullCaptivePortal $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullCaptivePortal;
    }

    public function test_add_ip_is_noop(): void
    {
        $this->provider->addIp('10.0.0.1', 'test');
        $this->assertTrue(true);
    }

    public function test_remove_ip_is_noop(): void
    {
        $this->provider->removeIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_add_allowed_hostnames_is_noop(): void
    {
        $this->provider->addAllowedHostnames(['example.com']);
        $this->assertTrue(true);
    }

    public function test_reconcile_returns_empty_result(): void
    {
        $result = $this->provider->reconcile();
        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
        $this->assertSame([], $result->unchanged);
        $this->assertSame([], $result->errors);
    }

    public function test_reconcile_dry_run_returns_empty_result(): void
    {
        $result = $this->provider->reconcile(dryRun: true);
        $this->assertInstanceOf(ReconcileResult::class, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullCaptivePortalTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ReconcileResult;

interface CaptivePortalInterface
{
    public function addIp(string $ip, string $description): void;

    public function removeIp(string $ip): void;

    /** @param array<int, string> $hostnames */
    public function addAllowedHostnames(array $hostnames): void;

    public function reconcile(bool $dryRun = false): ReconcileResult;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\CaptivePortalInterface;
use App\Services\ValueObjects\ReconcileResult;

class NullCaptivePortal implements CaptivePortalInterface
{
    public function addIp(string $ip, string $description): void {}

    public function removeIp(string $ip): void {}

    public function addAllowedHostnames(array $hostnames): void {}

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        return new ReconcileResult(added: [], removed: [], unchanged: [], errors: []);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullCaptivePortalTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/CaptivePortalInterface.php app/Services/Null/NullCaptivePortal.php tests/Unit/Services/Null/NullCaptivePortalTest.php
git commit -m "feat: add CaptivePortalInterface and NullCaptivePortal"
```

### Task 4: Create `RateLimitingInterface` and `NullRateLimiter`

**Files:**
- Create: `app/Services/Interfaces/RateLimitingInterface.php`
- Create: `app/Services/Null/NullRateLimiter.php`
- Create: `tests/Unit/Services/Null/NullRateLimiterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullRateLimiter;
use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class NullRateLimiterTest extends TestCase
{
    private NullRateLimiter $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullRateLimiter;
    }

    public function test_limit_ip_is_noop(): void
    {
        $this->provider->limitIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_unlimit_ip_is_noop(): void
    {
        $this->provider->unlimitIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_reconcile_returns_empty_result(): void
    {
        $result = $this->provider->reconcile();
        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullRateLimiterTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ReconcileResult;

interface RateLimitingInterface
{
    public function limitIp(string $ip): void;

    public function unlimitIp(string $ip): void;

    public function reconcile(bool $dryRun = false): ReconcileResult;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\RateLimitingInterface;
use App\Services\ValueObjects\ReconcileResult;

class NullRateLimiter implements RateLimitingInterface
{
    public function limitIp(string $ip): void {}

    public function unlimitIp(string $ip): void {}

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        return new ReconcileResult(added: [], removed: [], unchanged: [], errors: []);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullRateLimiterTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/RateLimitingInterface.php app/Services/Null/NullRateLimiter.php tests/Unit/Services/Null/NullRateLimiterTest.php
git commit -m "feat: add RateLimitingInterface and NullRateLimiter"
```

### Task 5: Create `IpBandwidthInterface` and `NullIpBandwidth`

**Files:**
- Create: `app/Services/Interfaces/IpBandwidthInterface.php`
- Create: `app/Services/Null/NullIpBandwidth.php`
- Create: `tests/Unit/Services/Null/NullIpBandwidthTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullIpBandwidth;
use App\Services\ValueObjects\IpBandwidthResult;
use PHPUnit\Framework\TestCase;

class NullIpBandwidthTest extends TestCase
{
    private NullIpBandwidth $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullIpBandwidth;
    }

    public function test_get_ip_bandwidth_returns_empty_result(): void
    {
        $result = $this->provider->getIpBandwidth('10.0.0.1');
        $this->assertInstanceOf(IpBandwidthResult::class, $result);
        $this->assertSame(0, $result->received);
        $this->assertSame(0, $result->sent);
        $this->assertSame([], $result->timestamps);
        $this->assertSame([], $result->download);
        $this->assertSame([], $result->upload);
    }

    public function test_get_ip_bandwidth_accepts_array(): void
    {
        $result = $this->provider->getIpBandwidth(['10.0.0.1', '10.0.0.2']);
        $this->assertInstanceOf(IpBandwidthResult::class, $result);
    }

    public function test_get_total_bandwidth_returns_empty_result(): void
    {
        $result = $this->provider->getTotalBandwidth();
        $this->assertInstanceOf(IpBandwidthResult::class, $result);
        $this->assertSame(0, $result->received);
    }

    public function test_get_top_talkers_returns_empty_collection(): void
    {
        $result = $this->provider->getTopTalkers();
        $this->assertCount(0, $result);
    }

    public function test_get_top_talkers_accepts_range(): void
    {
        $result = $this->provider->getTopTalkers(limit: 5, range: '5m');
        $this->assertCount(0, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullIpBandwidthTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\IpBandwidthResult;
use App\Services\ValueObjects\TopTalker;
use Illuminate\Support\Collection;

interface IpBandwidthInterface
{
    /** @param string|string[] $ipAddress */
    public function getIpBandwidth(string|array $ipAddress, string $range = '24h'): IpBandwidthResult;

    public function getTotalBandwidth(string $range = '24h'): IpBandwidthResult;

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10, string $range = '1m'): Collection;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\IpBandwidthInterface;
use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Support\Collection;

class NullIpBandwidth implements IpBandwidthInterface
{
    public function getIpBandwidth(string|array $ipAddress, string $range = '24h'): IpBandwidthResult
    {
        return new IpBandwidthResult(received: 0, sent: 0, timestamps: [], download: [], upload: []);
    }

    public function getTotalBandwidth(string $range = '24h'): IpBandwidthResult
    {
        return new IpBandwidthResult(received: 0, sent: 0, timestamps: [], download: [], upload: []);
    }

    public function getTopTalkers(int $limit = 10, string $range = '1m'): Collection
    {
        return collect();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullIpBandwidthTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/IpBandwidthInterface.php app/Services/Null/NullIpBandwidth.php tests/Unit/Services/Null/NullIpBandwidthTest.php
git commit -m "feat: add IpBandwidthInterface and NullIpBandwidth"
```

### Task 6: Create `PortBandwidthInterface` and `NullPortBandwidth`

**Files:**
- Create: `app/Services/Interfaces/PortBandwidthInterface.php`
- Create: `app/Services/Null/NullPortBandwidth.php`
- Create: `tests/Unit/Services/Null/NullPortBandwidthTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullPortBandwidth;
use App\Services\ValueObjects\PortTimeSeries;
use PHPUnit\Framework\TestCase;

class NullPortBandwidthTest extends TestCase
{
    private NullPortBandwidth $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullPortBandwidth;
    }

    public function test_get_port_bandwidth_returns_empty_series(): void
    {
        $result = $this->provider->getPortBandwidth('switch1', 'GigabitEthernet0/1', 0.0, 1000.0);
        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame([], $result->in);
        $this->assertSame([], $result->out);
    }

    public function test_is_available_returns_false(): void
    {
        $this->assertFalse($this->provider->isAvailable());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullPortBandwidthTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\PortTimeSeries;

interface PortBandwidthInterface
{
    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries;

    public function isAvailable(): bool;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\ValueObjects\PortTimeSeries;

class NullPortBandwidth implements PortBandwidthInterface
{
    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        return new PortTimeSeries(in: [], out: []);
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullPortBandwidthTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/PortBandwidthInterface.php app/Services/Null/NullPortBandwidth.php tests/Unit/Services/Null/NullPortBandwidthTest.php
git commit -m "feat: add PortBandwidthInterface and NullPortBandwidth"
```

### Task 7: Create `PortErrorsInterface` and `NullPortErrors`

**Files:**
- Create: `app/Services/Interfaces/PortErrorsInterface.php`
- Create: `app/Services/Null/NullPortErrors.php`
- Create: `tests/Unit/Services/Null/NullPortErrorsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullPortErrors;
use App\Services\ValueObjects\PortTimeSeries;
use PHPUnit\Framework\TestCase;

class NullPortErrorsTest extends TestCase
{
    private NullPortErrors $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullPortErrors;
    }

    public function test_get_port_errors_returns_empty_series(): void
    {
        $result = $this->provider->getPortErrors('switch1', 'GigabitEthernet0/1', 0.0, 1000.0);
        $this->assertInstanceOf(PortTimeSeries::class, $result);
        $this->assertSame([], $result->in);
        $this->assertSame([], $result->out);
    }

    public function test_is_available_returns_false(): void
    {
        $this->assertFalse($this->provider->isAvailable());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullPortErrorsTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\PortTimeSeries;

interface PortErrorsInterface
{
    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries;

    public function isAvailable(): bool;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\PortErrorsInterface;
use App\Services\ValueObjects\PortTimeSeries;

class NullPortErrors implements PortErrorsInterface
{
    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        return new PortTimeSeries(in: [], out: []);
    }

    public function isAvailable(): bool
    {
        return false;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullPortErrorsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/PortErrorsInterface.php app/Services/Null/NullPortErrors.php tests/Unit/Services/Null/NullPortErrorsTest.php
git commit -m "feat: add PortErrorsInterface and NullPortErrors"
```

### Task 8: Create `IpMacResolverInterface` and `NullIpMacResolver`

**Files:**
- Create: `app/Services/Interfaces/IpMacResolverInterface.php`
- Create: `app/Services/Null/NullIpMacResolver.php`
- Create: `tests/Unit/Services/Null/NullIpMacResolverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullIpMacResolver;
use PHPUnit\Framework\TestCase;

class NullIpMacResolverTest extends TestCase
{
    public function test_get_arp_table_returns_empty_collection(): void
    {
        $provider = new NullIpMacResolver;
        $result = $provider->getArpTable();
        $this->assertCount(0, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullIpMacResolverTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ArpEntry;
use Illuminate\Support\Collection;

interface IpMacResolverInterface
{
    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\IpMacResolverInterface;
use Illuminate\Support\Collection;

class NullIpMacResolver implements IpMacResolverInterface
{
    public function getArpTable(): Collection
    {
        return collect();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullIpMacResolverTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/IpMacResolverInterface.php app/Services/Null/NullIpMacResolver.php tests/Unit/Services/Null/NullIpMacResolverTest.php
git commit -m "feat: add IpMacResolverInterface and NullIpMacResolver"
```

### Task 9: Create `PortMacInterface` and `NullPortMac`

**Files:**
- Create: `app/Services/Interfaces/PortMacInterface.php`
- Create: `app/Services/Null/NullPortMac.php`
- Create: `tests/Unit/Services/Null/NullPortMacTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullPortMac;
use PHPUnit\Framework\TestCase;

class NullPortMacTest extends TestCase
{
    public function test_get_forwarding_database_returns_empty_collection(): void
    {
        $provider = new NullPortMac;
        $result = $provider->getForwardingDatabase();
        $this->assertCount(0, $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullPortMacTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the interface**

```php
<?php

declare(strict_types=1);

namespace App\Services\Interfaces;

use App\Services\ValueObjects\ForwardingEntry;
use Illuminate\Support\Collection;

interface PortMacInterface
{
    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection;
}
```

- [ ] **Step 4: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\PortMacInterface;
use Illuminate\Support\Collection;

class NullPortMac implements PortMacInterface
{
    public function getForwardingDatabase(): Collection
    {
        return collect();
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullPortMacTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/Interfaces/PortMacInterface.php app/Services/Null/NullPortMac.php tests/Unit/Services/Null/NullPortMacTest.php
git commit -m "feat: add PortMacInterface and NullPortMac"
```

### Task 10: Create `NullDnsFiltering`

Currently no null provider exists for `DnsFilteringInterface`.

**Files:**
- Create: `app/Services/Null/NullDnsFiltering.php`
- Create: `tests/Unit/Services/Null/NullDnsFilteringTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\Null;

use App\Services\Null\NullDnsFiltering;
use App\Services\ValueObjects\ReconcileResult;
use PHPUnit\Framework\TestCase;

class NullDnsFilteringTest extends TestCase
{
    private NullDnsFiltering $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new NullDnsFiltering;
    }

    public function test_is_enabled_for_ip_returns_false(): void
    {
        $this->assertFalse($this->provider->isEnabledForIp('10.0.0.1'));
    }

    public function test_enable_for_ip_is_noop(): void
    {
        $this->provider->enableForIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_disable_for_ip_is_noop(): void
    {
        $this->provider->disableForIp('10.0.0.1');
        $this->assertTrue(true);
    }

    public function test_reconcile_returns_empty_result(): void
    {
        $result = $this->provider->reconcile();
        $this->assertInstanceOf(ReconcileResult::class, $result);
        $this->assertSame([], $result->added);
        $this->assertSame([], $result->removed);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=NullDnsFilteringTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the null provider**

```php
<?php

declare(strict_types=1);

namespace App\Services\Null;

use App\Services\Interfaces\DnsFilteringInterface;
use App\Services\ValueObjects\ReconcileResult;

class NullDnsFiltering implements DnsFilteringInterface
{
    public function isEnabledForIp(string $ipAddress): bool
    {
        return false;
    }

    public function enableForIp(string $ipAddress): void {}

    public function disableForIp(string $ipAddress): void {}

    public function reconcile(bool $dryRun = false): ReconcileResult
    {
        return new ReconcileResult(added: [], removed: [], unchanged: [], errors: []);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=NullDnsFilteringTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Null/NullDnsFiltering.php tests/Unit/Services/Null/NullDnsFilteringTest.php
git commit -m "feat: add NullDnsFiltering null provider"
```

---

## Phase 2: Real Implementations

Create the real capability implementations wrapping existing code.

### Task 11: Extract `OpnSenseClient` shared HTTP client

**Files:**
- Create: `app/Services/OpnSense/OpnSenseClient.php`
- Create: `tests/Unit/Services/OpnSense/OpnSenseClientTest.php`

Extract the `get()`, `post()`, `decodeResponse()`, `makeOptions()` methods from `app/Services/Firewalls/OpnSense.php` into a shared client. The current `OpnSense` constructor takes a Guzzle `Client`, `zoneId`, `uploadRuleUuid`, `downloadRuleUuid`. The shared client takes just the Guzzle `Client`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\OpnSense;

use App\Services\Exceptions\BackendException;
use App\Services\OpnSense\OpnSenseClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class OpnSenseClientTest extends TestCase
{
    public function test_get_returns_decoded_json(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"status":"ok"}'),
        ]);
        $client = new OpnSenseClient(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $client->get('/api/test');

        $this->assertSame('ok', $result->status);
    }

    public function test_post_returns_decoded_json(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '{"result":"saved"}'),
        ]);
        $client = new OpnSenseClient(new Client(['handler' => HandlerStack::create($mock)]));

        $result = $client->post('/api/test', [], ['data' => 'value']);

        $this->assertSame('saved', $result->result);
    }

    public function test_invalid_json_throws_backend_exception(): void
    {
        $mock = new MockHandler([
            new Response(200, [], 'not json'),
        ]);
        $client = new OpnSenseClient(new Client(['handler' => HandlerStack::create($mock)]));

        $this->expectException(BackendException::class);
        $client->get('/api/test');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=OpnSenseClientTest`
Expected: FAIL — class not found

- [ ] **Step 3: Write the implementation**

Extract the HTTP methods from `app/Services/Firewalls/OpnSense.php` (lines for `get()`, `post()`, `decodeResponse()`, `makeOptions()`) into:

```php
<?php

declare(strict_types=1);

namespace App\Services\OpnSense;

use App\Services\Exceptions\BackendException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use stdClass;

class OpnSenseClient
{
    public function __construct(
        protected Client $client,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws BackendException
     */
    public function get(string $uri, array $query = []): stdClass
    {
        $options = $this->makeOptions($query);
        try {
            Log::debug('[OpnSense] GET '.$uri);
            $response = $this->client->get($uri, $options);

            return $this->decodeResponse($response);
        } catch (GuzzleException $guzzleException) {
            throw new BackendException('Error from Opnsense: '.$guzzleException->getMessage(), $guzzleException->getCode(), $guzzleException);
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|stdClass|null  $payload
     *
     * @throws BackendException
     */
    public function post(string $uri, array $query = [], array|stdClass|null $payload = []): stdClass
    {
        $options = $this->makeOptions($query, $payload);
        try {
            Log::debug('[OpnSense] POST '.$uri);
            $response = $this->client->post($uri, $options);

            return $this->decodeResponse($response);
        } catch (GuzzleException $guzzleException) {
            throw new BackendException('Error from Opnsense: '.$guzzleException->getMessage(), $guzzleException->getCode(), $guzzleException);
        }
    }

    /**
     * @throws BackendException
     */
    protected function decodeResponse(ResponseInterface $response): stdClass
    {
        $json = json_decode((string) $response->getBody());
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BackendException('Unable to decode response');
        }

        return (object) $json;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|stdClass|null  $payload
     * @return array<string, mixed>
     */
    protected function makeOptions(array $query = [], array|stdClass|null $payload = null): array
    {
        $options = [];
        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        return $options;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=OpnSenseClientTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/OpnSense/OpnSenseClient.php tests/Unit/Services/OpnSense/OpnSenseClientTest.php
git commit -m "feat: extract OpnSenseClient shared HTTP client"
```

### Task 12: Create `OpnSenseCaptivePortal`

**Files:**
- Create: `app/Services/OpnSense/OpnSenseCaptivePortal.php`
- Create: `tests/Unit/Services/OpnSense/OpnSenseCaptivePortalTest.php`

Extract captive portal methods from `app/Services/Firewalls/OpnSense.php`: `updateIp()`, `removeIp()`, `addAllowedHostnames()`, `reconcileInternet()`, `fetchConnectedIps()`. Uses `OpnSenseClient` for HTTP.

- [ ] **Step 1: Write failing tests** covering `addIp`, `removeIp`, `addAllowedHostnames`, and `reconcile`. Test that the correct API endpoints are called via mocked `OpnSenseClient`. Reference the existing test patterns in `tests/Unit/Services/Firewalls/OpnSenseTest.php` for mock response shapes.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=OpnSenseCaptivePortalTest`
Expected: FAIL

- [ ] **Step 3: Write the implementation** — extract from `OpnSense.php`, adapt to use `OpnSenseClient` instead of internal methods. Constructor takes `OpnSenseClient $client, int $zoneId`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=OpnSenseCaptivePortalTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/OpnSense/OpnSenseCaptivePortal.php tests/Unit/Services/OpnSense/OpnSenseCaptivePortalTest.php
git commit -m "feat: add OpnSenseCaptivePortal implementing CaptivePortalInterface"
```

### Task 13: Create `OpnSenseRateLimiter`

**Files:**
- Create: `app/Services/OpnSense/OpnSenseRateLimiter.php`
- Create: `tests/Unit/Services/OpnSense/OpnSenseRateLimiterTest.php`

Extract rate limiting methods from `app/Services/Firewalls/OpnSense.php`: `limitIp()`, `unlimitIp()`, `reconcileRateLimits()`, `fetchRateLimitedIps()`, plus the shaper rule helpers (`addHostToRule`, `removeHostFromRule`, `getShaperRule`, `filter`, `updateShaperRule`, `applyShaperRules`). Constructor takes `OpnSenseClient $client, string $uploadRuleUuid, string $downloadRuleUuid`.

- [ ] **Step 1: Write failing tests** covering `limitIp`, `unlimitIp`, and `reconcile`. Reference the existing test patterns in `tests/Unit/Services/Firewalls/OpnSenseTest.php` and `tests/Unit/Services/Firewalls/OpnSenseDstPortBugTest.php` for shaper rule mock shapes.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the implementation**

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/OpnSense/OpnSenseRateLimiter.php tests/Unit/Services/OpnSense/OpnSenseRateLimiterTest.php
git commit -m "feat: add OpnSenseRateLimiter implementing RateLimitingInterface"
```

### Task 14: Create `PrometheusIpBandwidth`

**Files:**
- Create: `app/Services/Prometheus/PrometheusIpBandwidth.php`
- Create: `tests/Unit/Services/Prometheus/PrometheusIpBandwidthTest.php`

This is a rename + adaptation of `app/Services/Prometheus/PrometheusTrafficMonitor.php`. Change class name, implement `IpBandwidthInterface` instead of `TrafficMonitorInterface`, return `IpBandwidthResult` instead of `UserBandwidth`, rename `getUserBandwidth` to `getIpBandwidth`, add `string $range` parameter to `getTopTalkers`, remove `getAggregateStats`.

- [ ] **Step 1: Write failing tests** — adapt from `tests/Unit/Services/Prometheus/PrometheusTrafficMonitorTest.php`. Use `IpBandwidthResult` assertions. Add test for `getTopTalkers` with range parameter. Remove `getAggregateStats` tests.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the implementation** — copy `PrometheusTrafficMonitor.php`, rename class, change interface, change return types, rename method, remove `getAggregateStats`.

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Prometheus/PrometheusIpBandwidth.php tests/Unit/Services/Prometheus/PrometheusIpBandwidthTest.php
git commit -m "feat: add PrometheusIpBandwidth implementing IpBandwidthInterface"
```

### Task 15: Create `PrometheusPortBandwidth`

**Files:**
- Create: `app/Services/Prometheus/PrometheusPortBandwidth.php`
- Create: `tests/Unit/Services/Prometheus/PrometheusPortBandwidthTest.php`

Wraps `PrometheusService` internal methods. `getPortBandwidth()` delegates to `PrometheusService::getPortBandwidth()` but returns `PortTimeSeries` instead of a raw array. `isAvailable()` delegates to `PrometheusService::isAvailable()`.

- [ ] **Step 1: Write failing tests** — test that `getPortBandwidth` returns `PortTimeSeries`, test `isAvailable` delegation. Mock `PrometheusService`.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\PortBandwidthInterface;
use App\Services\ValueObjects\PortTimeSeries;

class PrometheusPortBandwidth implements PortBandwidthInterface
{
    public function __construct(
        protected PrometheusService $prometheus,
    ) {}

    public function getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        $data = $this->prometheus->getPortBandwidth($device, $ifName, $start, $end, $step);

        return new PortTimeSeries(in: $data['in'], out: $data['out']);
    }

    public function isAvailable(): bool
    {
        return $this->prometheus->isAvailable();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Prometheus/PrometheusPortBandwidth.php tests/Unit/Services/Prometheus/PrometheusPortBandwidthTest.php
git commit -m "feat: add PrometheusPortBandwidth implementing PortBandwidthInterface"
```

### Task 16: Create `PrometheusPortErrors`

**Files:**
- Create: `app/Services/Prometheus/PrometheusPortErrors.php`
- Create: `tests/Unit/Services/Prometheus/PrometheusPortErrorsTest.php`

Same pattern as Task 15 but for port errors.

- [ ] **Step 1: Write failing tests**

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\Prometheus;

use App\Services\Interfaces\PortErrorsInterface;
use App\Services\ValueObjects\PortTimeSeries;

class PrometheusPortErrors implements PortErrorsInterface
{
    public function __construct(
        protected PrometheusService $prometheus,
    ) {}

    public function getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
    {
        $data = $this->prometheus->getPortErrors($device, $ifName, $start, $end, $step);

        return new PortTimeSeries(in: $data['in'], out: $data['out']);
    }

    public function isAvailable(): bool
    {
        return $this->prometheus->isAvailable();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/Prometheus/PrometheusPortErrors.php tests/Unit/Services/Prometheus/PrometheusPortErrorsTest.php
git commit -m "feat: add PrometheusPortErrors implementing PortErrorsInterface"
```

### Task 17: Create `LibreNmsIpMacResolver`

**Files:**
- Create: `app/Services/LibreNms/LibreNmsIpMacResolver.php`
- Create: `tests/Unit/Services/LibreNms/LibreNmsIpMacResolverTest.php`

Wraps `LibreNmsService`, merges `getArpTable()` + `getIpv6Neighbors()` into a single `getArpTable()` call.

- [ ] **Step 1: Write failing tests** — test that `getArpTable` returns merged IPv4 ARP + IPv6 neighbors, test deduplication, test empty results.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\Interfaces\IpMacResolverInterface;
use App\Services\LibreNmsService;
use Illuminate\Support\Collection;

class LibreNmsIpMacResolver implements IpMacResolverInterface
{
    public function __construct(
        protected LibreNmsService $libreNms,
    ) {}

    public function getArpTable(): Collection
    {
        $arp = $this->libreNms->getArpTable();
        $ipv6 = $this->libreNms->getIpv6Neighbors();

        return $arp->concat($ipv6)->unique(fn ($entry) => $entry->ip.'|'.$entry->mac)->values();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/LibreNms/LibreNmsIpMacResolver.php tests/Unit/Services/LibreNms/LibreNmsIpMacResolverTest.php
git commit -m "feat: add LibreNmsIpMacResolver implementing IpMacResolverInterface"
```

### Task 18: Create `LibreNmsPortMac`

**Files:**
- Create: `app/Services/LibreNms/LibreNmsPortMac.php`
- Create: `tests/Unit/Services/LibreNms/LibreNmsPortMacTest.php`

Wraps `LibreNmsService::getForwardingDatabase()`.

- [ ] **Step 1: Write failing tests**

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Services\LibreNms;

use App\Services\Interfaces\PortMacInterface;
use App\Services\LibreNmsService;
use Illuminate\Support\Collection;

class LibreNmsPortMac implements PortMacInterface
{
    public function __construct(
        protected LibreNmsService $libreNms,
    ) {}

    public function getForwardingDatabase(): Collection
    {
        return $this->libreNms->getForwardingDatabase();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/LibreNms/LibreNmsPortMac.php tests/Unit/Services/LibreNms/LibreNmsPortMacTest.php
git commit -m "feat: add LibreNmsPortMac implementing PortMacInterface"
```

---

## Phase 3: Move `OpnSenseDhcpService` and `LibreNmsService`

### Task 19: Move `OpnSenseDhcpService` to `OpnSense/` directory

**Files:**
- Move: `app/Services/Dhcp/OpnSenseDhcpService.php` → `app/Services/OpnSense/OpnSenseDhcpService.php`
- Modify: All files that import the old namespace

- [ ] **Step 1: Update the namespace** in the file from `App\Services\Dhcp` to `App\Services\OpnSense`.

- [ ] **Step 2: Find and update all imports** — search for `use App\Services\Dhcp\OpnSenseDhcpService` across the codebase and update to `use App\Services\OpnSense\OpnSenseDhcpService`. This includes `IntegrationServiceProvider.php` and all test files in `tests/Unit/Services/Dhcp/`.

- [ ] **Step 3: Run the full test suite** to verify nothing broke.

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor: move OpnSenseDhcpService to OpnSense namespace"
```

### Task 20: Move `LibreNmsService` to `LibreNms/` directory

**Files:**
- Move: `app/Services/LibreNmsService.php` → `app/Services/LibreNms/LibreNmsService.php`
- Modify: All files that import the old namespace

- [ ] **Step 1: Update the namespace** from `App\Services` to `App\Services\LibreNms`.

- [ ] **Step 2: Find and update all imports** — search for `use App\Services\LibreNmsService` across the codebase. This includes `IntegrationServiceProvider.php`, `LibreNmsIpMacResolver.php`, `LibreNmsPortMac.php`, and `tests/Unit/Services/LibreNmsServiceTest.php`.

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor: move LibreNmsService to LibreNms namespace"
```

---

## Phase 4: Rewire Service Provider & Consumers

### Task 21: Rewrite `IntegrationServiceProvider` bindings

**Files:**
- Modify: `app/Providers/IntegrationServiceProvider.php`
- Create: `tests/Unit/Providers/IntegrationServiceProviderTest.php`

Replace all bindings to use the new interfaces. Gate every capability through `CapabilityAssignment::isActiveProvider()`. Register `OpnSenseClient` and `PrometheusService` as shared singletons.

- [ ] **Step 1: Write failing tests** — test that each capability resolves to the null provider when no capability is assigned, and resolves to the real implementation when the capability IS assigned (using `CapabilityAssignment::factory()`). One test per capability (9 capabilities = 18 tests minimum).

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Rewrite `IntegrationServiceProvider::register()`** — remove old bindings for `FirewallBackendInterface`, `TrafficMonitorInterface`, `HostStatsProviderInterface`, `MetricsProviderInterface`, `NetworkInventoryInterface`. Add new bindings for: `OpnSenseClient` (singleton), `CaptivePortalInterface`, `RateLimitingInterface`, `IpBandwidthInterface`, `PortBandwidthInterface`, `PortErrorsInterface`, `IpMacResolverInterface`, `PortMacInterface`. Keep `DhcpInterface` and `DnsFilteringInterface` bindings but gate them through `isActiveProvider`. Keep `BorealisService` binding unchanged.

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Providers/IntegrationServiceProvider.php tests/Unit/Providers/IntegrationServiceProviderTest.php
git commit -m "feat: rewrite IntegrationServiceProvider with capability-gated bindings"
```

### Task 22: Update `IpAddressActionService`

**Files:**
- Modify: `app/Services/IpAddressActionService.php`
- Modify: `tests/Unit/Services/IpAddressActionServiceTest.php`

Replace `FirewallBackendInterface` with `CaptivePortalInterface` + `RateLimitingInterface`. Remove `HostStatsProviderInterface` dependency and `updateUsage()` method. Remove `NetworkInventoryInterface` dependency and `getPortInfo()` method (this uses `resolveIpToPort` and `getPortDetail` which are LibreNMS-specific — move to `IpAddressController` directly or a dedicated service).

- [ ] **Step 1: Update tests** — replace `FirewallBackendInterface` mock with `CaptivePortalInterface` and `RateLimitingInterface` mocks. Remove `updateUsage` tests. Update `createService()` helper.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Update the implementation** — change constructor to take `CaptivePortalInterface $captivePortal`, `RateLimitingInterface $rateLimiter`, `SwitchServiceFactory $switchFactory`, `MacAddressResolverInterface $macResolver`. Update method bodies: `enableInternet` calls `$this->captivePortal->addIp(...)`, `disableInternet` calls `$this->captivePortal->removeIp(...)`, `enableRateLimit` calls `$this->rateLimiter->limitIp(...)`, `disableRateLimit` calls `$this->rateLimiter->unlimitIp(...)`. Remove `updateUsage()`. The `getPortInfo()` method needs `LibreNmsService` — either inject it or move to controller. Since `IpAddressController` already has the `LibreNmsService` context from the old `MetricsProviderInterface`, move `getPortInfo` logic into the controller. Remove `NetworkInventoryInterface` from constructor.

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add app/Services/IpAddressActionService.php tests/Unit/Services/IpAddressActionServiceTest.php
git commit -m "refactor: update IpAddressActionService to use CaptivePortal and RateLimiting interfaces"
```

### Task 23: Update `MacAddressResolver`

**Files:**
- Modify: `app/Services/MacAddressResolver.php`
- Modify: `tests/Unit/Services/MacAddressResolverTest.php` (if exists, otherwise `tests/Feature/` equivalent)

Replace `NetworkInventoryInterface` with `IpMacResolverInterface`.

- [ ] **Step 1: Update tests** — replace `NetworkInventoryInterface` mock with `IpMacResolverInterface` mock.

- [ ] **Step 2: Update the implementation** — change constructor: `protected IpMacResolverInterface $ipMac` instead of `protected NetworkInventoryInterface $inventory`. In `resolveIpToMac()`, change `$this->inventory->getArpTable()` to `$this->ipMac->getArpTable()`.

- [ ] **Step 3: Run tests to verify they pass**

- [ ] **Step 4: Update `NetworkServiceProvider`** — change the `MacAddressResolverInterface` binding to inject `IpMacResolverInterface` instead of `NetworkInventoryInterface`.

- [ ] **Step 5: Run full test suite**

- [ ] **Step 6: Commit**

```bash
git add app/Services/MacAddressResolver.php app/Providers/NetworkServiceProvider.php tests/
git commit -m "refactor: update MacAddressResolver to use IpMacResolverInterface"
```

### Task 24: Update controllers — `HomeController`

**Files:**
- Modify: `app/Http/Controllers/Admin/HomeController.php`

Replace `TrafficMonitorInterface` with `IpBandwidthInterface` in the `bandwidth()` method. Replace `UserBandwidth` usage with `IpBandwidthResult`.

- [ ] **Step 1: Update imports and method signature** — `TrafficMonitorInterface` → `IpBandwidthInterface`, `getUserBandwidth` → `getIpBandwidth` (in `bandwidth()` method), `getTotalBandwidth` stays same name.

- [ ] **Step 2: Run existing tests**

Run: `php artisan test --compact --filter=DashboardControllerTest`
Expected: PASS (or update test mocks if needed)

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Admin/HomeController.php
git commit -m "refactor: update HomeController to use IpBandwidthInterface"
```

### Task 25: Update controllers — `UserController`

**Files:**
- Modify: `app/Http/Controllers/Admin/UserController.php`

Replace `TrafficMonitorInterface` with `IpBandwidthInterface`. In `bandwidth()` method: `getUserBandwidth` → `getIpBandwidth`. In `show()` method: inject `IpBandwidthInterface` and call `getIpBandwidth($ip->address, '7d')` per device IP to populate real bandwidth data in `buildNetworkDevices()`.

- [ ] **Step 1: Update `bandwidth()` method** — change type hint and method call.

- [ ] **Step 2: Update `show()` method** — add `IpBandwidthInterface $ipBandwidth` parameter. After building network devices, loop through devices that have an `ip_address` and query `$ipBandwidth->getIpBandwidth($device['ip_address'], '7d')` to populate `received`/`sent`.

- [ ] **Step 3: Update `buildNetworkDevices()`** — remove `$ip->received`/`$ip->sent` references. Instead, set `received` and `sent` to `0` in all paths, then let the caller (show method) populate them from `IpBandwidthInterface`.

- [ ] **Step 4: Update tests**

Run: `php artisan test --compact --filter=UserControllerTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/UserController.php tests/
git commit -m "refactor: update UserController to use IpBandwidthInterface with per-device bandwidth"
```

### Task 26: Update controllers — `IpAddressController`

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`

Replace `MetricsProviderInterface` with `PortBandwidthInterface` + `PortErrorsInterface`. Replace `TrafficMonitorInterface` with `IpBandwidthInterface`. Update constructor and `show()` method to use `PortTimeSeries` value object. Update `bandwidth()` method.

- [ ] **Step 1: Update constructor** — `MetricsProviderInterface $metrics` → `PortBandwidthInterface $portBandwidth, PortErrorsInterface $portErrors`.

- [ ] **Step 2: Update `show()` method** — replace `$this->metrics->getPortBandwidth(...)` with `$this->portBandwidth->getPortBandwidth(...)` which returns `PortTimeSeries`. Extract `->in` and `->out` from the result. Replace `$this->metrics->getPortErrors(...)` similarly. Replace `$this->metrics->isAvailable()` with `$this->portBandwidth->isAvailable()`.

- [ ] **Step 3: Update `bandwidth()` method** — `TrafficMonitorInterface` → `IpBandwidthInterface`, `getUserBandwidth` → `getIpBandwidth`.

- [ ] **Step 4: Run existing tests**

Run: `php artisan test --compact --filter=IpAddressControllerTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/IpAddressController.php tests/
git commit -m "refactor: update IpAddressController to use capability interfaces"
```

### Task 27: Update controllers — `SwitchPortController`

**Files:**
- Modify: `app/Http/Controllers/Admin/SwitchPortController.php`

Replace `MetricsProviderInterface` with `PortBandwidthInterface` + `PortErrorsInterface`.

- [ ] **Step 1: Update constructor** — `MetricsProviderInterface $metrics` → `PortBandwidthInterface $portBandwidth, PortErrorsInterface $portErrors`.

- [ ] **Step 2: Update `show()` method** — same pattern as IpAddressController. `$this->metrics->getPortBandwidth(...)` → `$this->portBandwidth->getPortBandwidth(...)`. `$this->metrics->getPortErrors(...)` → `$this->portErrors->getPortErrors(...)`. `$this->metrics->isAvailable()` → `$this->portBandwidth->isAvailable()`. Extract `->in` / `->out` from `PortTimeSeries` results.

- [ ] **Step 3: Run existing tests**

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Admin/SwitchPortController.php tests/
git commit -m "refactor: update SwitchPortController to use PortBandwidth and PortErrors interfaces"
```

### Task 28: Update controllers — `StatsController`

**Files:**
- Modify: `app/Http/Controllers/Portal/StatsController.php`

Replace `TrafficMonitorInterface` with `IpBandwidthInterface`, `getUserBandwidth` → `getIpBandwidth`.

- [ ] **Step 1: Update the method**

- [ ] **Step 2: Run existing tests**

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Portal/StatsController.php
git commit -m "refactor: update StatsController to use IpBandwidthInterface"
```

### Task 29: Update commands — `ReconcileInternetCommand`

**Files:**
- Modify: `app/Console/Commands/ReconcileInternetCommand.php`

Replace `FirewallBackendInterface` with `CaptivePortalInterface`. Change `$firewall->reconcileInternet(...)` to `$captivePortal->reconcile(...)`.

- [ ] **Step 1: Update the command**

- [ ] **Step 2: Run existing tests**

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/ReconcileInternetCommand.php
git commit -m "refactor: update ReconcileInternetCommand to use CaptivePortalInterface"
```

### Task 30: Update commands — `ReconcileRateLimitsCommand`

**Files:**
- Modify: `app/Console/Commands/ReconcileRateLimitsCommand.php`

Replace `FirewallBackendInterface` with `RateLimitingInterface`. Change `$firewall->reconcileRateLimits(...)` to `$rateLimiter->reconcile(...)`.

- [ ] **Step 1: Update the command**

- [ ] **Step 2: Run existing tests**

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/ReconcileRateLimitsCommand.php
git commit -m "refactor: update ReconcileRateLimitsCommand to use RateLimitingInterface"
```

### Task 31: Update commands — `SyncUserBandwidthCommand`

**Files:**
- Modify: `app/Console/Commands/SyncUserBandwidthCommand.php`

Replace `TrafficMonitorInterface` with `IpBandwidthInterface`. Change `getUserBandwidth` → `getIpBandwidth`.

- [ ] **Step 1: Update the command**

- [ ] **Step 2: Run existing tests**

Run: `php artisan test --compact --filter=SyncUserBandwidthCommandTest`

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/SyncUserBandwidthCommand.php
git commit -m "refactor: update SyncUserBandwidthCommand to use IpBandwidthInterface"
```

### Task 32: Update `ScanNetworkDevices` job

**Files:**
- Modify: `app/Jobs/ScanNetworkDevices.php`
- Modify: tests in `tests/Feature/Jobs/ScanNetworkDevicesTest.php` and `tests/Feature/NetworkDeviceTracking/ScanNetworkDevicesRefactorTest.php`

Replace `NetworkInventoryInterface` with `IpMacResolverInterface` + `PortMacInterface`.

- [ ] **Step 1: Update `handle()` signature** — change to `DhcpInterface $dhcp, IpMacResolverInterface $ipMac, PortMacInterface $portMac, NetworkRangeService $rangeService`.

- [ ] **Step 2: Update data fetching** — `$arpEntries = $ipMac->getArpTable()`, `$forwardingEntries = $portMac->getForwardingDatabase()`.

- [ ] **Step 3: Update tests** — replace `NetworkInventoryInterface` mocks with `IpMacResolverInterface` and `PortMacInterface` mocks.

- [ ] **Step 4: Run tests**

Run: `php artisan test --compact --filter=ScanNetworkDevices`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ScanNetworkDevices.php tests/
git commit -m "refactor: update ScanNetworkDevices to use IpMacResolver and PortMac interfaces"
```

---

## Phase 5: Database Migration & Cleanup

### Task 33: Create database migration

**Files:**
- Create: migration via `php artisan make:migration`

Two changes:
1. Update `capability_assignments` table — remove stale rows, rename `user-bandwidth` → `ip-bandwidth`, add `ip-mac` and `port-mac`.
2. Drop `received` and `sent` columns from `ip_addresses` table.

- [ ] **Step 1: Create migration**

Run: `php artisan make:migration rationalise_capabilities_and_drop_ip_bandwidth_columns --no-interaction`

- [ ] **Step 2: Write the migration**

```php
public function up(): void
{
    // Remove stale capabilities
    DB::table('capability_assignments')
        ->whereIn('capability', [
            'authentication', 'sso', 'user-info',
            'aggregate-stats', 'device-metrics',
            'firewall', 'host-stats',
        ])
        ->delete();

    // Rename user-bandwidth → ip-bandwidth
    DB::table('capability_assignments')
        ->where('capability', 'user-bandwidth')
        ->update(['capability' => 'ip-bandwidth']);

    // Add new capabilities (only if not already present)
    if (! DB::table('capability_assignments')->where('capability', 'ip-mac')->exists()) {
        DB::table('capability_assignments')->insert([
            'capability' => 'ip-mac',
            'integration' => 'librenms',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    if (! DB::table('capability_assignments')->where('capability', 'port-mac')->exists()) {
        DB::table('capability_assignments')->insert([
            'capability' => 'port-mac',
            'integration' => 'librenms',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Drop dead columns from ip_addresses
    Schema::table('ip_addresses', function (Blueprint $table) {
        $table->dropColumn(['received', 'sent']);
    });
}

public function down(): void
{
    Schema::table('ip_addresses', function (Blueprint $table) {
        $table->unsignedBigInteger('received')->default(0);
        $table->unsignedBigInteger('sent')->default(0);
    });

    DB::table('capability_assignments')
        ->where('capability', 'ip-bandwidth')
        ->update(['capability' => 'user-bandwidth']);

    DB::table('capability_assignments')
        ->whereIn('capability', ['ip-mac', 'port-mac'])
        ->delete();
}
```

- [ ] **Step 3: Run migration**

Run: `php artisan migrate`
Expected: Migration runs successfully

- [ ] **Step 4: Commit**

```bash
git add database/migrations/
git commit -m "feat: rationalise capability assignments and drop IP bandwidth columns"
```

### Task 34: Delete `SyncBandwidthCommand` and update scheduler

**Files:**
- Delete: `app/Console/Commands/SyncBandwidthCommand.php`
- Delete: `tests/Feature/Console/SyncBandwidthCommandTest.php`
- Modify: `app/Console/Kernel.php` — remove `aperture:sync-bandwidth` schedule entry

- [ ] **Step 1: Remove the schedule entry** from `app/Console/Kernel.php`

- [ ] **Step 2: Delete the command and test files**

- [ ] **Step 3: Run tests**

Run: `php artisan test --compact --filter=KernelTest`
Expected: PASS (update kernel test if it asserts sync-bandwidth is scheduled)

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: remove SyncBandwidthCommand and schedule entry"
```

### Task 35: Delete old interfaces, implementations, and tests

**Files to delete:**
- `app/Services/Interfaces/FirewallBackendInterface.php`
- `app/Services/Interfaces/TrafficMonitorInterface.php`
- `app/Services/Interfaces/HostStatsProviderInterface.php`
- `app/Services/Interfaces/MetricsProviderInterface.php`
- `app/Services/Interfaces/NetworkInventoryInterface.php`
- `app/Services/Firewalls/OpnSense.php`
- `app/Services/NtopNgService.php`
- `app/Services/Null/NullHostStatsProvider.php`
- `app/Services/Null/NullTrafficMonitor.php`
- `app/Services/Null/NullMetricsProvider.php`
- `app/Services/Null/NullNetworkInventoryService.php`
- `app/Services/Prometheus/PrometheusTrafficMonitor.php`
- `app/Services/CachedNetworkInventoryService.php`
- `app/Services/ValueObjects/AggregateStats.php`
- `app/Services/ValueObjects/HostBytes.php`
- `app/Services/ValueObjects/UserBandwidth.php`
- All corresponding test files (see list from exploration)

Also delete ntopng integration tester:
- `app/Services/Integration/NtopNgTester.php`
- `tests/Unit/Services/Integration/NtopNgTesterTest.php`
- `tests/Unit/Services/NtopNgServiceTest.php`
- `tests/Unit/Services/NtopNgTestConnectionAuthTest.php`
- `tests/Feature/Services/NtopNgServiceWiringTest.php`

- [ ] **Step 1: Remove ntopng from `IntegrationTesterRegistry`** in `IntegrationServiceProvider` — delete the `$registry->register('ntopng', new NtopNgTester)` line.

- [ ] **Step 2: Delete all listed files**

- [ ] **Step 3: Search for any remaining imports** of the deleted classes and fix them.

Run: `grep -rn "NtopNg\|HostStatsProvider\|NullTrafficMonitor\|NullMetricsProvider\|NullNetworkInventory\|CachedNetworkInventory\|AggregateStats\|HostBytes\|UserBandwidth\|FirewallBackendInterface\|TrafficMonitorInterface\|MetricsProviderInterface\|NetworkInventoryInterface" app/ tests/ --include="*.php" | head -20`

- [ ] **Step 4: Run full test suite**

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: delete old interfaces, ntopng, and replaced implementations"
```

---

## Phase 6: Audit Logging

### Task 36: Add audit logging to IP and user state changes

**Files:**
- Modify: `app/Http/Controllers/Admin/IpAddressController.php` — `internet()`, `limit()` methods
- Modify: `app/Http/Controllers/Admin/UserController.php` — `toggleInternet()`, `toggleRateLimit()`, `toggleBlock()` methods
- Modify: `app/Console/Commands/ExpireSessionsCommand.php`
- Modify: `app/Console/Commands/ReconcileInternetCommand.php`
- Modify: `app/Console/Commands/ReconcileRateLimitsCommand.php`
- Modify: `app/Console/Commands/ReconcileDnsFilteringCommand.php`
- Modify: `app/Jobs/ScanNetworkDevices.php` — `linkSwitchPortMacs()`

- [ ] **Step 1: Write failing tests** — for each audit point, test that `AuditLog::record()` is called with the correct action key and metadata. For controllers, use feature tests that assert the audit log row was created in the database.

- [ ] **Step 2: Run tests to verify they fail**

- [ ] **Step 3: Add `AuditLog::record()` calls** at each site listed in the spec's audit logging table. Example for `IpAddressController::internet()`:

```php
$ip->internet_enabled = $request->boolean('allow');
$ip->save();

AuditLog::record(
    action: 'ip.internet_toggled',
    subject: $ip,
    process: 'admin',
    metadata: ['enabled' => $ip->internet_enabled],
);
```

- [ ] **Step 4: Run tests to verify they pass**

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add audit logging for IP, user, and reconciliation state changes"
```

---

## Phase 7: Quality & Final Verification

### Task 37: Run full quality suite

- [ ] **Step 1: Run Laravel Pint**

Run: `vendor/bin/pint --format agent`

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: 0 errors

- [ ] **Step 3: Run full PHPUnit suite**

Run: `php artisan test --compact`
Expected: All tests pass

- [ ] **Step 4: Run ESLint and Prettier** (frontend unchanged but verify)

Run: `npm run lint && npm run format:check`

- [ ] **Step 5: Run Vitest**

Run: `npm run test`

- [ ] **Step 6: Fix any failures** and commit fixes.

- [ ] **Step 7: Final commit**

```bash
git commit -m "chore: quality fixes for capability rationalisation"
```

### Task 38: Restart scheduler

After removing `aperture:sync-bandwidth`, the running `schedule:work` process has a cached schedule. It must be restarted.

- [ ] **Step 1: Kill the existing scheduler process**

Read the PID from `.dev-env/pids/scheduler.pid` and kill it.

- [ ] **Step 2: Restart the scheduler**

Run: `php artisan schedule:work` (via the dev start script or directly)

- [ ] **Step 3: Verify** the scheduler no longer runs `aperture:sync-bandwidth`.
