# Service Value Objects Design

**Date:** 2026-04-13  
**Status:** Draft  
**Scope:** Replace loosely-typed array and stdClass returns from services with strongly-typed readonly value objects

---

## Problem Statement

Services across the application return `array<string, mixed>`, typed array shapes, and `stdClass` from their methods. This causes:

1. **No IDE autocomplete** — consumers access array keys by string, no refactoring support
2. **No compile-time safety** — typo in `$result['sucess']` silently returns null
3. **PHPStan limitations** — array shapes help but don't compose, nest poorly, and force `@var` annotations at every consumer
4. **Inconsistency** — `Auth` subsystem already uses value objects (AuthResult, DeviceFlowResponse, UserInfo); other subsystems use arrays
5. **stdClass fragility** — `BorealisService` and `NtopNgService` return `stdClass` with no guaranteed shape

## Approach

Introduce readonly PHP 8.2+ value objects with constructor property promotion. Follow the pattern already established in `App\Services\Auth\`. Each VO lives in the same namespace as its service. Interfaces are updated to return VOs. All existing tests are updated to match.

---

## Design Principles

1. **Readonly classes** — all VOs use `readonly class` (PHP 8.2+)
2. **Constructor promotion** — all properties via `public function __construct(public ...)`
3. **Namespace placement** — Domain-specific VOs live in the service namespace (e.g., `App\Services\SshProxy\CommandResult`); shared VOs used across multiple services/interfaces live in `App\Services\ValueObjects`
4. **Collections preserve generics** — `Collection<int, array{...}>` becomes `Collection<int, ArpEntry>` etc.
5. **Inertia compatibility** — readonly public properties serialize cleanly through Inertia's default serialization; no `JsonSerializable` needed
6. **No behavioral methods** — VOs are pure data carriers, no business logic
7. **Named constructors where useful** — static `fromArray()` or `fromStdClass()` factory methods where the VO is constructed from external API responses

---

## Value Objects by Service Domain

### 1. SSH Proxy (`App\Services\SshProxy`)

#### `CommandOutput`

Represents a single command's execution result.

```php
readonly class CommandOutput
{
    public function __construct(
        public string $command,
        public string $output,
    ) {}
}
```

**Replaces:** `array{command: string, output: string}` elements in `CommandExecutor::execute()` output array.

#### `CommandResult`

Represents the full result of executing a batch of SSH commands.

```php
readonly class CommandResult
{
    /**
     * @param array<int, CommandOutput> $output
     */
    public function __construct(
        public bool $success,
        public array $output,
        public ?string $error = null,
    ) {}
}
```

**Replaces:** `array{success: bool, output: array, error?: string}` from `CommandExecutor::execute()`.

**Consumers:**
- `RequestHandler::handleExecute()` — serializes to JSON response body
- `SshProxyClientInterface::execute()` — return type changes
- `SshProxyClient::execute()` — constructs from JSON response
- `TestConnectionController::testSwitch()` — accesses `->success` and `->error`

#### `ProxyResponse`

HTTP-level response from the SSH proxy daemon.

```php
readonly class ProxyResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}
}
```

**Replaces:** `array{status: int, body: array<string, mixed>}` from `RequestHandler::handle()`.

**Note:** The `body` remains `array<string, mixed>` because it is immediately JSON-encoded for HTTP transport. The body content varies (error messages, command results, status info).

#### `ConnectionStatus`

Status of a single pooled SSH connection.

```php
readonly class ConnectionStatus
{
    public function __construct(
        public string $hostname,
        public int $connectedSeconds,
        public int $lastUsedSecondsAgo,
        public bool $locked,
    ) {}
}
```

**Replaces:** elements in the `connections` array from `SshConnectionPool::getStatus()`.

#### `ProxyStatus`

Overall SSH proxy daemon status.

```php
readonly class ProxyStatus
{
    /**
     * @param array<int, ConnectionStatus> $connections
     */
    public function __construct(
        public int $uptimeSeconds,
        public array $connections,
    ) {}
}
```

**Replaces:** `array{uptime_seconds: int, connections: array}` from `SshProxyClientInterface::status()`.

#### Interface Changes

```php
interface SshProxyClientInterface
{
    public function execute(string $hostname, string $username, string $password, array $commands): CommandResult;
    public function status(): ProxyStatus;
}
```

---

### 2. Network Inventory (`App\Services\Interfaces`)

These VOs live alongside the interfaces they serve, in `App\Services\ValueObjects`. This is a new namespace because the interfaces are in `App\Services\Interfaces` and VOs are shared across multiple implementations.

#### `ArpEntry`

```php
readonly class ArpEntry
{
    public function __construct(
        public string $ip,
        public string $mac,
    ) {}
}
```

**Replaces:** `array{ip: string, mac: string}` in `getArpTable()` and `getIpv6Neighbors()`.

#### `ForwardingEntry`

```php
readonly class ForwardingEntry
{
    public function __construct(
        public string $mac,
        public string $port,
        public int $vlan,
    ) {}
}
```

**Replaces:** `array{mac: string, port: string, vlan: int}` in `getForwardingDatabase()`.

#### `ResolvedPort`

```php
readonly class ResolvedPort
{
    public function __construct(
        public string $ip,
        public string $mac,
        public string $port,
        public string $switch,
    ) {}
}
```

**Replaces:** `array{ip: string, mac: string, port: string, switch: string}|null` from `resolveIpToPort()`.

#### `PortDetail`

```php
readonly class PortDetail
{
    public function __construct(
        public string $hostname,
        public string $interface,
        public string $status,
        public string $adminStatus,
        public int $speed,
    ) {}
}
```

**Replaces:** `array{hostname, interface, status, adminStatus, speed}|null` from `getPortDetail()`.  
**Also replaces:** the `stdClass` returned by `IpAddress::getPortInfo()` — that method changes to return `?PortDetail`.

#### `NetworkDevice`

```php
readonly class NetworkDevice
{
    public function __construct(
        public string $hostname,
        public string $ip,
        public string $type,
    ) {}
}
```

**Replaces:** `array{hostname: string, ip: string, type: string}` in `getDeviceList()`.

#### Interface Changes

```php
interface NetworkInventoryInterface
{
    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection;

    /** @return Collection<int, ArpEntry> */
    public function getArpTable(): Collection;

    public function resolveIpToPort(string $ipAddress): ?ResolvedPort;

    /** @return Collection<int, NetworkDevice> */
    public function getDeviceList(): Collection;

    /** @return Collection<int, ArpEntry> */
    public function getIpv6Neighbors(): Collection;

    public function getPortDetail(string $portId): ?PortDetail;
}
```

---

### 3. Network Switch (`App\Services\ValueObjects`)

#### `PortStatus`

```php
readonly class PortStatus
{
    public function __construct(
        public string $interface,
        public string $status,
        public string $speed,
        public string $duplex = '',
        public string $vlan = '',
    ) {}
}
```

**Replaces:** `array{interface, status, speed, duplex, vlan}` from `getPortStatus()`.  
**Also used by:** `getAllPorts()` — the `duplex` default handles the slimmer shape.

#### `PortStatistics`

```php
readonly class PortStatistics
{
    public function __construct(
        public int $inBytes,
        public int $outBytes,
        public int $inErrors,
        public int $outErrors,
    ) {}
}
```

**Replaces:** `array{in_bytes, out_bytes, in_errors, out_errors}` from `getPortStatistics()`.

#### Interface Changes

```php
interface NetworkSwitchInterface
{
    public function getPortStatus(string $portId): PortStatus;

    /** @return Collection<int, PortStatus> */
    public function getAllPorts(): Collection;

    public function shutdownPort(string $portId): bool;
    public function enablePort(string $portId): bool;

    public function getPortStatistics(string $portId): PortStatistics;

    /** @return Collection<int, ForwardingEntry> */
    public function getForwardingDatabase(): Collection;
}
```

---

### 4. DHCP (`App\Services\ValueObjects`)

#### `DhcpPoolStatus`

```php
readonly class DhcpPoolStatus
{
    public function __construct(
        public int $total,
        public int $used,
        public int $available,
        public float $utilisation,
    ) {}
}
```

**Replaces:** `array{total, used, available, utilisation}` from `getPoolStatus()`.

#### `DhcpLease`

```php
readonly class DhcpLease
{
    public function __construct(
        public string $ip,
        public string $mac,
        public string $hostname,
        public string $expires,
    ) {}
}
```

**Replaces:** `array{ip, mac, hostname, expires}` from `getLeases()` and `getLease()`.

#### Interface Changes

```php
interface DhcpInterface
{
    public function getPoolStatus(): DhcpPoolStatus;

    /** @return Collection<int, DhcpLease> */
    public function getLeases(): Collection;

    public function getLease(string $ipAddress): ?DhcpLease;
}
```

---

### 5. Traffic Monitor (`App\Services\ValueObjects`)

#### `UserBandwidth`

```php
readonly class UserBandwidth
{
    /**
     * @param array<int, string> $timestamps
     * @param array<int, int> $download
     * @param array<int, int> $upload
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

**Replaces:** `array{received, sent, timestamps, download, upload}` from `getUserBandwidth()`.

#### `AggregateStats`

```php
readonly class AggregateStats
{
    public function __construct(
        public int $totalUsers,
        public int $totalDevices,
        public int $totalBandwidth,
    ) {}
}
```

**Replaces:** `array{totalUsers, totalDevices, totalBandwidth}` from `getAggregateStats()`.

#### `TopTalker`

```php
readonly class TopTalker
{
    public function __construct(
        public string $ip,
        public int $received,
        public int $sent,
        public ?string $nickname = null,
    ) {}
}
```

**Replaces:** `array{ip, received, sent, nickname}` from `getTopTalkers()`.

#### Interface Changes

```php
interface TrafficMonitorInterface
{
    public function getUserBandwidth(string $ipAddress, string $range = '24h'): UserBandwidth;
    public function getAggregateStats(): AggregateStats;

    /** @return Collection<int, TopTalker> */
    public function getTopTalkers(int $limit = 10): Collection;
}
```

---

### 6. Captive Portal (`App\Services\ValueObjects`)

#### `ActiveSession`

```php
readonly class ActiveSession
{
    public function __construct(
        public string $ip,
        public string $user,
    ) {}
}
```

**Replaces:** `array{ip, user}` from `listActiveSessions()`.

#### Interface Changes

```php
interface CaptivePortalInterface
{
    public function grantAccess(string $ipAddress, User $user): bool;
    public function revokeAccess(string $ipAddress, User $user): bool;
    public function isAllowed(string $ipAddress): bool;

    /** @return Collection<int, ActiveSession> */
    public function listActiveSessions(): Collection;
}
```

---

## Out of Scope

### BorealisService `stdClass` Returns

`BorealisService::check()`, `getDeviceCodeRaw()`, and `getUserWithToken()` return `stdClass`. These are consumed exclusively by `BorealisDeviceFlowService`, which already converts them to `AuthResult`, `DeviceFlowResponse`, and `UserInfo` VOs. The public interface (`AuthProviderInterface`) already returns VOs. Converting `BorealisService` internals is low-value busywork.

### NtopNgService `stdClass` Returns

`NtopNgService::getStats()` returns raw ntopng API response as `stdClass`. The shape is defined by an external API and varies. If `NtopNgService` is wrapped by a `TrafficMonitorInterface` implementation, that wrapper returns the VOs defined above. Leaving `getStats()` as `stdClass` is acceptable.

### OpnSense Internal `stdClass`

`OpnSense` uses `stdClass` internally for API requests/responses via `get()`/`post()` helpers. These are private/protected methods consuming external API shapes. The public methods on `FirewallBackendInterface` return `self` (fluent) or scalars — no arrays to replace.

---

## File Structure

```
app/Services/
├── SshProxy/
│   ├── CommandOutput.php          (NEW)
│   ├── CommandResult.php          (NEW)
│   ├── ConnectionStatus.php       (NEW)
│   ├── ProxyResponse.php          (NEW)
│   ├── ProxyStatus.php            (NEW)
│   ├── CommandExecutor.php        (MODIFIED — return type)
│   ├── RequestHandler.php         (MODIFIED — return type)
│   ├── SshProxyClient.php         (MODIFIED — return type + construction)
│   └── SshProxyClientInterface.php (MODIFIED — return types)
├── ValueObjects/
│   ├── ActiveSession.php          (NEW)
│   ├── AggregateStats.php         (NEW)
│   ├── ArpEntry.php               (NEW)
│   ├── DhcpLease.php              (NEW)
│   ├── DhcpPoolStatus.php         (NEW)
│   ├── ForwardingEntry.php        (NEW)
│   ├── NetworkDevice.php          (NEW)
│   ├── PortDetail.php             (NEW)
│   ├── PortStatistics.php         (NEW)
│   ├── PortStatus.php             (NEW)
│   ├── ResolvedPort.php           (NEW)
│   ├── TopTalker.php              (NEW)
│   └── UserBandwidth.php          (NEW)
├── Interfaces/
│   ├── CaptivePortalInterface.php (MODIFIED)
│   ├── DhcpInterface.php          (MODIFIED)
│   ├── NetworkInventoryInterface.php (MODIFIED)
│   ├── NetworkSwitchInterface.php (MODIFIED)
│   └── TrafficMonitorInterface.php (MODIFIED)
├── Dhcp/
│   └── OpnSenseDhcpService.php    (MODIFIED — returns VOs)
├── NetworkSwitch/
│   ├── CiscoSwitchAdapter.php     (MODIFIED — returns VOs)
│   └── IosOutputParser.php        (MODIFIED — returns VOs)
├── LibreNmsService.php            (MODIFIED — returns VOs)
├── CachedNetworkInventoryService.php (MODIFIED — passthrough types)
├── MacAddressResolver.php         (MODIFIED — accesses VO properties)
└── IpAddressActionService.php     (MODIFIED — uses PortDetail instead of stdClass)
```

Also modified:
- `app/Models/IpAddress.php` — `getPortInfo()` returns `?PortDetail` instead of `?stdClass`
- `app/Http/Controllers/Admin/TestConnectionController.php` — accesses `->success` / `->error` instead of `['success']` / `['error']`
- `app/Http/Controllers/Admin/PortController.php` — no code change needed (Inertia serializes VOs)
- `app/Http/Controllers/Admin/DhcpController.php` — no code change needed (Inertia serializes VOs)

---

## Consumer Impact

### Controllers Passing VOs to Inertia

`PortController`, `DhcpController`, `StatsController` pass service responses directly to Inertia. Readonly public properties serialize to JSON identically to associative arrays, **one caveat**: property names use camelCase (e.g., `$inBytes`) whereas the old arrays used snake_case keys (e.g., `in_bytes`).

**Action required:** Check all frontend JavaScript that consumes these props and update key references (e.g., `statistics.in_bytes` → `statistics.inBytes`). Alternatively, implement `JsonSerializable` on VOs that need snake_case keys for backward compatibility. The implementation plan should audit each frontend consumer.

### IpAddress Model

`getPortInfo()` currently returns `?stdClass`. It changes to `?PortDetail`. Consumers use `$portInfo->interface`, `$portInfo->switch` etc. — `PortDetail` has the same property names, so this is seamless.

### MacAddressResolver

Currently accesses `$lease['mac']`. Changes to `$lease->mac`. Simple property access change.

### SshProxyClient

Currently calls `json_decode(..., true)` and returns the array. Changes to construct `CommandResult` from the decoded JSON:

```php
$data = json_decode((string) $response->getBody(), true);
return new CommandResult(
    success: $data['success'],
    output: array_map(
        fn (array $item) => new CommandOutput($item['command'], $item['output']),
        $data['output'] ?? [],
    ),
    error: $data['error'] ?? null,
);
```

---

## Testing Impact

533 tests at 100% coverage. Changes required:

1. **Array access → property access** in test assertions: `$result['success']` → `$result->success`
2. **Mock return values** change from arrays to VO instances
3. **New VO unit tests** — verify construction and readonly behavior (lightweight, primarily for coverage)
4. **Frontend E2E tests** may need key name updates if snake_case → camelCase

Estimated test files affected: ~15-20 across Unit and Feature directories.

---

## Migration Strategy

This is a pure refactor with no database or API changes. Recommended order:

1. **Create all VO classes** — no existing code breaks
2. **Update interfaces** — return types change (breaks implementations)
3. **Update implementations** — construct and return VOs
4. **Update consumers** — array access → property access
5. **Update tests** — match new return types
6. **Audit frontend** — update any snake_case key references
7. **Run full test suite + quality checks**

Each domain (SSH Proxy, Network Inventory, Switch, DHCP, Traffic, Captive Portal) can be done independently as a separate commit.
