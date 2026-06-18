# Backend Quick Wins Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Five targeted backend improvements: fix N+1 query patterns, extract shared BandwidthResource, migrate inline validation to FormRequests, replace magic values with named constants, and remove confirmed dead code.

**Architecture:** All fixes are surgical improvements to existing code. No new architectural patterns introduced. Each task is independent.

**Tech Stack:** Laravel 13, PHP 8.3, Eloquent, PHPUnit (parallel), Laravel Pint, PHPStan Level 8

---

## Task 1: Fix N+1 queries in UserShowDataService

**Problem:** In `UserShowDataService::buildNetworkDevices()`, for each MAC address the code calls `$mac->ipAddresses()` and `$mac->switchPorts()` inside a loop without eager loading. This creates N+1 queries.

**Files:**
- Modify: `app/Services/UserShowDataService.php`
- Modify: relevant test in `tests/Feature/` (find with `grep -r "UserShowDataService" tests/`)

- [ ] **Step 1.1: Find existing tests**

```bash
grep -r "UserShowDataService\|buildNetworkDevices" tests/ --include="*.php" -l
```

- [ ] **Step 1.2: Write a failing N+1 test**

Find or create the test file. Add:

```php
public function test_build_network_devices_does_not_n_plus_one(): void
{
    $user = User::factory()->create();
    $macs = MacAddress::factory()->count(3)->create();
    $user->macAddresses()->attach($macs->pluck('id'));

    DB::enableQueryLog();

    $service = new UserShowDataService();
    $ipModels = collect();
    $service->buildNetworkDevices($user, $ipModels);

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 3 queries max: macs, ips eager load, switch ports eager load
    $this->assertLessThanOrEqual(3, $queryCount,
        "Expected ≤3 queries, got {$queryCount}. N+1 detected.");
}
```

- [ ] **Step 1.3: Run to verify it fails**

```bash
XDEBUG_MODE=coverage php artisan test --filter test_build_network_devices_does_not_n_plus_one
```

Expected: FAIL with query count > 3.

- [ ] **Step 1.4: Fix UserShowDataService::buildNetworkDevices()**

In `app/Services/UserShowDataService.php`, update `buildNetworkDevices()`:

```php
public function buildNetworkDevices(User $user, Collection $ipModels): array
{
    $macs = $user->macAddresses()
        ->with([
            'switchPorts.switchConfig',
            'ipAddresses' => fn ($q) => $q->orderByPivot('last_seen_at', 'desc'),
        ])
        ->get();

    $ipAddressSet = $ipModels->pluck('address')->flip();
    $devices = [];
    $coveredIps = [];

    foreach ($macs as $mac) {
        // Already eager loaded — no extra queries
        $macIps = $mac->ipAddresses->sortByDesc(fn ($ip) => $ip->pivot->last_seen_at);

        $switchPort = $mac->switchPorts->sortByDesc(fn ($sp) => $sp->pivot->last_seen_at)->first();

        $switchInfo = $switchPort !== null ? [
            'switch_name' => $switchPort->switchConfig->name ?? $switchPort->switchConfig->hostname,
            'switch_id' => $switchPort->switch_config_id,
            'port_name' => $switchPort->port_name,
        ] : ['switch_name' => null, 'switch_id' => null, 'port_name' => null];

        $hostname = $mac->currentHostname();

        $relevantIps = $macIps->filter(fn (IpAddress $ip) => $ipAddressSet->has($ip->address));

        if ($relevantIps->isEmpty()) {
            $devices[] = array_merge([
                'mac_address' => $mac->mac_address,
                'mac_id' => $mac->id,
                'ip_address' => null,
                'ip_id' => null,
                'hostname' => $hostname,
                'internet_enabled' => null,
                'rate_limit_enabled' => null,
                'last_seen_at' => null,
            ], $switchInfo);
        } else {
            foreach ($relevantIps as $ip) {
                $coveredIps[$ip->address] = true;
                $devices[] = array_merge([
                    'mac_address' => $mac->mac_address,
                    'mac_id' => $mac->id,
                    'ip_address' => $ip->address,
                    'ip_id' => $ip->id,
                    'hostname' => $hostname,
                    'internet_enabled' => $ip->internet_enabled,
                    'rate_limit_enabled' => $ip->rate_limit_enabled,
                    'last_seen_at' => $ip->pivot->last_seen_at->toIso8601String(),
                ], $switchInfo);
            }
        }
    }

    // Remaining IPs not covered by any MAC
    foreach ($ipModels as $ip) {
        if (! isset($coveredIps[$ip->address])) {
            $devices[] = [
                'mac_address' => null,
                'mac_id' => null,
                'ip_address' => $ip->address,
                'ip_id' => $ip->id,
                'hostname' => null,
                'internet_enabled' => $ip->internet_enabled,
                'rate_limit_enabled' => $ip->rate_limit_enabled,
                'last_seen_at' => null,
                'switch_name' => null,
                'switch_id' => null,
                'port_name' => null,
            ];
        }
    }

    return array_values($devices);
}
```

- [ ] **Step 1.5: Run tests**

```bash
XDEBUG_MODE=coverage php artisan test --filter UserShowDataService
./vendor/bin/pint app/Services/UserShowDataService.php
./vendor/bin/phpstan analyse app/Services/UserShowDataService.php --no-progress
```

Expected: All pass.

- [ ] **Step 1.6: Commit**

```bash
git add app/Services/UserShowDataService.php
git commit -m "perf: eager-load MAC relations in UserShowDataService::buildNetworkDevices

Eliminates N+1 queries — ipAddresses and switchPorts now loaded with
the MAC collection instead of per-MAC inside the loop.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 2: Extract BandwidthResource

**Problem:** Three controllers (`HomeController`, `UserController`, `IpAddressController`) all return identical bandwidth JSON shapes:
```php
return response()->json([
    'timestamps' => $bandwidth->timestamps,
    'download'   => $bandwidth->download,
    'upload'     => $bandwidth->upload,
    'totalReceived' => $bandwidth->received,
    'totalSent'     => $bandwidth->sent,
]);
```
This is duplicated. Extract to a shared `BandwidthResource`.

**Files:**
- Create: `app/Http/Resources/BandwidthResource.php`
- Modify: `app/Http/Controllers/Admin/HomeController.php`
- Modify: `app/Http/Controllers/Admin/UserController.php`
- Modify: `app/Http/Controllers/Admin/IpAddressController.php`
- Create: `tests/Unit/Http/Resources/BandwidthResourceTest.php`

- [ ] **Step 2.1: Write failing test**

Create `tests/Unit/Http/Resources/BandwidthResourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\BandwidthResource;
use App\Services\ValueObjects\IpBandwidthResult;
use Tests\TestCase;

class BandwidthResourceTest extends TestCase
{
    public function test_resource_returns_correct_json_structure(): void
    {
        $result = new IpBandwidthResult(
            received: 1024,
            sent: 2048,
            timestamps: ['2024-01-01T00:00:00Z'],
            download: [100.0],
            upload: [200.0],
        );

        $resource = new BandwidthResource($result);
        $json = $resource->toArray(request());

        $this->assertSame(1024, $json['totalReceived']);
        $this->assertSame(2048, $json['totalSent']);
        $this->assertSame(['2024-01-01T00:00:00Z'], $json['timestamps']);
        $this->assertSame([100.0], $json['download']);
        $this->assertSame([200.0], $json['upload']);
    }
}
```

- [ ] **Step 2.2: Run to verify it fails**

```bash
php artisan test --filter BandwidthResourceTest
```

Expected: FAIL — class not found.

- [ ] **Step 2.3: Create BandwidthResource**

Create `app/Http/Resources/BandwidthResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\ValueObjects\IpBandwidthResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @extends JsonResource<IpBandwidthResult> */
class BandwidthResource extends JsonResource
{
    /**
     * @return array{timestamps: array<int, string>, download: array<int, float>, upload: array<int, float>, totalReceived: int, totalSent: int}
     */
    public function toArray(Request $request): array
    {
        /** @var IpBandwidthResult $result */
        $result = $this->resource;

        return [
            'timestamps'    => $result->timestamps,
            'download'      => $result->download,
            'upload'        => $result->upload,
            'totalReceived' => $result->received,
            'totalSent'     => $result->sent,
        ];
    }
}
```

- [ ] **Step 2.4: Update HomeController**

In `app/Http/Controllers/Admin/HomeController.php`, replace the bandwidth method return:

```php
use App\Http\Resources\BandwidthResource;

// In bandwidth():
return BandwidthResource::make($bandwidth)->response();
```

- [ ] **Step 2.5: Update UserController**

In `app/Http/Controllers/Admin/UserController.php`, replace the bandwidth method return:

```php
use App\Http\Resources\BandwidthResource;

// In bandwidth():
return BandwidthResource::make($bandwidth)->response();
```

- [ ] **Step 2.6: Update IpAddressController**

In `app/Http/Controllers/Admin/IpAddressController.php`, replace the bandwidth method return and convert the inline validate to use the existing `BandwidthRequest`:

```php
use App\Http\Resources\BandwidthResource;
use App\Http\Requests\Admin\BandwidthRequest;

// Change signature:
public function bandwidth(BandwidthRequest $request, IpAddress $ip, IpBandwidthInterface $ipBandwidth): JsonResponse

// In bandwidth():
$range = $request->validated()['range'] ?? '24h';
$bandwidth = $ipBandwidth->getIpBandwidth([$ip->address], $range);
return BandwidthResource::make($bandwidth)->response();
```

Note: Check `app/Http/Requests/Admin/BandwidthRequest.php` to confirm the request class exists and its validated rules match. If not, create it:

```php
<?php
declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BandwidthRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['range' => 'nullable|string|in:1h,24h,4d'];
    }
}
```

- [ ] **Step 2.7: Run tests**

```bash
php artisan test --filter BandwidthResource
./vendor/bin/pint app/Http/Resources/BandwidthResource.php app/Http/Controllers/Admin/HomeController.php app/Http/Controllers/Admin/UserController.php app/Http/Controllers/Admin/IpAddressController.php
./vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 2.8: Commit**

```bash
git add app/Http/Resources/BandwidthResource.php app/Http/Controllers/Admin/HomeController.php app/Http/Controllers/Admin/UserController.php app/Http/Controllers/Admin/IpAddressController.php tests/Unit/Http/Resources/BandwidthResourceTest.php
git commit -m "refactor: extract BandwidthResource to eliminate duplicated JSON shape

Three controllers returned identical bandwidth JSON. Now all use
BandwidthResource::make(\$result)->response().

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 3: Migrate high-impact inline validation to FormRequests

**Problem:** Several controllers use `$request->validate()` inline. Extract the most impactful ones to FormRequest classes for reusability, testability, and separation of concerns.

Target controllers with multiple inline validates:
- `app/Http/Controllers/Admin/ContentController.php` (3 inline validates)
- `app/Http/Controllers/Admin/PageController.php` (2 inline validates)

**Files:**
- Create: `app/Http/Requests/Admin/StoreContentRequest.php`
- Create: `app/Http/Requests/Admin/UpdateContentRequest.php`
- Create: `app/Http/Requests/Admin/StorePageRequest.php`
- Create: `app/Http/Requests/Admin/UpdatePageRequest.php`
- Modify: `app/Http/Controllers/Admin/ContentController.php`
- Modify: `app/Http/Controllers/Admin/PageController.php`

- [ ] **Step 3.1: Read existing validation rules**

```bash
cat app/Http/Controllers/Admin/ContentController.php
cat app/Http/Controllers/Admin/PageController.php
```

- [ ] **Step 3.2: Write failing tests for FormRequest rules**

Create `tests/Unit/Http/Requests/Admin/StoreContentRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\StoreContentRequest;
use Tests\TestCase;

class StoreContentRequestTest extends TestCase
{
    public function test_title_is_required(): void
    {
        $request = new StoreContentRequest();
        $rules = $request->rules();

        $this->assertArrayHasKey('title', $rules);
        $this->assertStringContainsString('required', is_array($rules['title']) ? implode('|', $rules['title']) : $rules['title']);
    }

    public function test_authorize_returns_true(): void
    {
        $request = new StoreContentRequest();
        $this->assertTrue($request->authorize());
    }
}
```

- [ ] **Step 3.3: Run to verify it fails**

```bash
php artisan test --filter StoreContentRequestTest
```

- [ ] **Step 3.4: Create StoreContentRequest**

Read ContentController to find the exact rules, then create `app/Http/Requests/Admin/StoreContentRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string|array<int, string>> */
    public function rules(): array
    {
        // Copy exact rules from ContentController::store() $request->validate() call
        return [
            // Fill from ContentController after reading it
        ];
    }
}
```

Repeat for `UpdateContentRequest`, `StorePageRequest`, `UpdatePageRequest`.

- [ ] **Step 3.5: Update controllers to use FormRequests**

Replace `$request->validate([...])` with typed FormRequest injection in method signature. Example:

```php
// Before:
public function store(Request $request): RedirectResponse
{
    $validated = $request->validate([...]);

// After:
public function store(StoreContentRequest $request): RedirectResponse
{
    $validated = $request->validated();
```

- [ ] **Step 3.6: Run full test suite**

```bash
XDEBUG_MODE=coverage php artisan test --parallel
./vendor/bin/pint
./vendor/bin/phpstan analyse --no-progress
```

- [ ] **Step 3.7: Commit**

```bash
git add app/Http/Requests/Admin/ app/Http/Controllers/Admin/ContentController.php app/Http/Controllers/Admin/PageController.php tests/Unit/Http/Requests/
git commit -m "refactor: extract ContentController and PageController validation to FormRequests

Moves inline \$request->validate() calls to typed FormRequest classes
improving testability and reusability.

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Task 4: Remove confirmed dead code

**Problem:** Three pieces of confirmed dead code:
1. `app/Services/ValueObjects/ActiveSession.php` — class exists but is referenced nowhere in app/ or tests/
2. `resources/js/Pages/Admin/Settings/Integrations.vue` — `service.readonly` branch: `'readonly'` key is never set in `config/integrations.php` or any PHP code
3. `app/Models/User.php` — commented-out import on first few lines

**Files:**
- Delete: `app/Services/ValueObjects/ActiveSession.php`
- Modify: `resources/js/Pages/Admin/Settings/Integrations.vue`
- Modify: `app/Models/User.php`

- [ ] **Step 4.1: Verify ActiveSession is unused**

```bash
grep -r "ActiveSession" app/ tests/ --include="*.php" | grep -v "ActiveSession.php"
```

Expected: no output. If there are references, do NOT delete — investigate first.

- [ ] **Step 4.2: Verify readonly is never set**

```bash
grep -r "'readonly'" app/ config/ --include="*.php"
```

Expected: no output. If found, do NOT remove — investigate first.

- [ ] **Step 4.3: Delete ActiveSession.php**

```bash
git rm app/Services/ValueObjects/ActiveSession.php
```

- [ ] **Step 4.4: Remove readonly branch from Integrations.vue**

In `resources/js/Pages/Admin/Settings/Integrations.vue`:

Remove the `if (service.readonly)` guard in the script (line ~30), simplifying the click handler.
Remove `:tabindex="service.readonly ? undefined : 0"` → `:tabindex="0"` (lines ~76).
Remove `:role="service.readonly ? undefined : 'link'"` → `role="link"` (line ~77).
Remove the ternary classes conditioned on `service.readonly` (lines ~80, 91).

- [ ] **Step 4.5: Remove commented import from User.php**

In `app/Models/User.php`, find and remove the commented-out import line (around line 7). Run:

```bash
grep -n "^//" app/Models/User.php | head -5
```

Then remove the commented line.

- [ ] **Step 4.6: Run all checks**

```bash
XDEBUG_MODE=coverage php artisan test --parallel
./vendor/bin/pint
./vendor/bin/phpstan analyse --no-progress
npm run lint
```

Expected: All pass. PHPStan should have fewer symbols to analyse.

- [ ] **Step 4.7: Commit**

```bash
git add -A
git commit -m "chore: remove confirmed dead code

- Delete ActiveSession value object (zero references in app/tests)
- Remove service.readonly branch from Integrations.vue ('readonly'
  key is never set in integration config)
- Remove commented import from User.php

Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>"
```

---

## Final verification

- [ ] **Run complete test suite with coverage**

```bash
XDEBUG_MODE=coverage php artisan test --parallel
```

- [ ] **Run all linters**

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --no-progress
npm run lint
```

- [ ] **Check git log**

```bash
git log --oneline -10
```
