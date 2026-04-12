# Phase 3: MAC Tracking, Xbox Auto-enable, IPv6 Automation, Cache Layer

**Date:** 2026-04-12
**Status:** Approved
**Depends on:** Phase 1 (DI refactor, session TTL, portal reset, DHCP), Phase 2 (PiHole, Cisco switch adapter, DNS detection)

---

## Overview

Phase 3 adds MAC-based device tracking as a foundation for three features:

1. **Auto-allow known devices** — when an already-authorized MAC appears with a new IP (IPv4 or IPv6), auto-allow it
2. **Xbox/console auto-enable** — detect gaming consoles by OUI prefix and auto-allow without user authentication
3. **IPv6 automation** — auto-detect IPv6 addresses via external API and NDP data, plus admin visibility
4. **Cache layer & DI cleanup** — replace direct LibreNMS DB queries with cached API calls, fix remaining DI violations

---

## 1. Schema

### New table: `mac_addresses`

| Column | Type | Constraints | Notes |
|--------|------|------------|-------|
| `id` | bigint | PK, auto-increment | |
| `mac_address` | string(17) | unique, indexed | Normalized format `AA:BB:CC:DD:EE:FF` |
| `user_id` | bigint | FK nullable → `users.id`, ON DELETE SET NULL | Null for Xbox/auto-detected devices |
| `source` | string | not null | `auth`, `xbox`, `admin` — how it was first authorized |
| `allowed` | boolean | default false | Whether this MAC's IPs should be auto-allowed |
| `description` | string | nullable | e.g. "Xbox One", user nickname, admin note |
| `allowed_at` | timestamp | nullable | When it was first allowed |
| `created_at` | timestamp | | |
| `updated_at` | timestamp | | |

### Modified table: `ip_addresses`

| Column | Type | Constraints | Notes |
|--------|------|------------|-------|
| `mac_address_id` | bigint | FK nullable → `mac_addresses.id`, ON DELETE SET NULL | Added column |

### Relationships

- `MacAddress` belongsTo `User` (nullable)
- `MacAddress` hasMany `IpAddress`
- `IpAddress` belongsTo `MacAddress` (nullable)
- `User` hasMany `MacAddress`

### MAC Normalization

A `NormalizeMacAddress` cast/helper that:
- Accepts any common format: `aa:bb:cc:dd:ee:ff`, `AA-BB-CC-DD-EE-FF`, `aabb.ccdd.eeff`, `aabbccddeeff`
- Normalizes to uppercase colon-separated: `AA:BB:CC:DD:EE:FF`
- Used as an Eloquent cast on `MacAddress::mac_address`

---

## 2. MAC Tracking Logic

### 2.1 Auth-time MAC Resolution

When `IpAddress::allow()` fires:

1. Resolve MAC via `MacAddressResolver` service (queries DHCP leases via `DhcpInterface::getLeases()` and/or ARP table via `NetworkInventoryInterface::getArpTable()`)
2. If MAC resolved:
   - Find or create `MacAddress` record (normalize MAC first)
   - Link `IpAddress.mac_address_id` to the `MacAddress`
   - If the IP has an associated user, associate user with `MacAddress` if not already set
   - Set `MacAddress.allowed = true`, `source = 'auth'`, `allowed_at = now()` if not already allowed
3. If MAC not resolved: continue without linkage (background job will retry)

### 2.2 `MacAddressResolver` Service

New service: `App\Services\MacAddressResolver`

```php
interface MacAddressResolverInterface {
    public function resolveIpToMac(string $ipAddress): ?string;
}
```

Implementation queries (in order, returns first match):
1. `DhcpInterface::getLease($ipAddress)` → extracts `mac`
2. `NetworkInventoryInterface::getArpTable()` → filters by IP

Returns normalized MAC string or null.

### 2.3 Background Job: `ScanNetworkDevices`

Scheduled: every 5 minutes via `Console\Kernel`.

**Step 1 — Resolve MACs for unlinked IPs:**
- Get all `IpAddress` records where `mac_address_id IS NULL`
- For each, attempt MAC resolution via `MacAddressResolver`
- If resolved, find-or-create `MacAddress`, link it

**Step 2 — Auto-allow for known MACs:**
- Get all DHCP leases and ARP entries
- For each entry where MAC matches an `allowed=true` `MacAddress`:
  - If IP doesn't exist in `ip_addresses`: create it, link to MAC, call `allow()`
  - If IP exists but `allowed=false` and MAC is allowed: link to MAC, call `allow()`

**Step 3 — Xbox/console OUI detection:**
- For each DHCP/ARP entry, check if MAC prefix (first 3 octets) matches any configured OUI prefix
- If match and no `MacAddress` record exists:
  - Create `MacAddress` with `source='xbox'`, `allowed=true`, `user_id=null`, `description` derived from OUI match (e.g. "Xbox Console")
  - Auto-allow the IP (same as step 2)

### 2.4 Xbox OUI Configuration

```php
// config/aperture.php
'auto_allow' => [
    'enabled' => env('APERTURE_AUTO_ALLOW_ENABLED', false),
    'oui_prefixes' => [
        // Microsoft/Xbox OUI prefixes
        '98:5F:D3',
        '7C:ED:8D',
        '00:50:F2',
        '28:18:78',
        'C8:3F:26',
        '60:45:BD',
        '94:9A:A9',
        '48:4D:7E',
        'B4:09:31',
        'DC:B4:C4',
    ],
    'scan_interval' => 5, // minutes
],
```

Admins can add additional prefixes in the config. The default list covers common Xbox/Microsoft console OUI prefixes.

---

## 3. IPv6 Automation

### 3.1 Portal JS IPv6 Detection

On portal page load:
1. JS makes a fetch request to the configured external IPv6 API endpoint (e.g. `https://<random>.ipv6.test.entropylan.party`)
2. If the response returns an IPv6 address (and it differs from the current session IP), POST it to the existing `/ipv6` route
3. `PortalController::ipv6()` creates the `IpAddress` and calls `allow()`, which triggers MAC resolution (section 2.1)

Configuration:
```php
// config/aperture.php
'ipv6' => [
    'detection_enabled' => env('APERTURE_IPV6_DETECTION_ENABLED', false),
    'detection_endpoint' => env('APERTURE_IPV6_DETECTION_ENDPOINT'),
],
```

The endpoint URL supports a `{random}` placeholder that gets replaced with a random string to bypass caching.

### 3.2 IPv6 in LibreNMS

Extend `NetworkInventoryInterface` with:

```php
/**
 * @return Collection<int, array{ip: string, mac: string}>
 */
public function getIpv6Neighbors(): Collection;
```

`LibreNmsService` implements this by querying `/api/v0/resources/ip/arp` (which includes IPv6 in LibreNMS) or a dedicated IPv6 endpoint.

`MacAddressResolver` also checks IPv6 neighbors when resolving IPv6 addresses.

### 3.3 Admin IPv6 Visibility

The existing `IpAddress::getLNMSData()` (which will be replaced in section 4) currently only queries `ipv4_mac`. The replacement via `NetworkInventoryInterface` will naturally include IPv6 when the service queries both ARP and IPv6 neighbor tables.

---

## 4. Cache Layer & DI Cleanup

### 4.1 CachedNetworkInventoryService

Decorator pattern wrapping `LibreNmsService`:

```php
class CachedNetworkInventoryService implements NetworkInventoryInterface
{
    public function __construct(
        protected NetworkInventoryInterface $inner,
        protected Repository $cache,
        protected int $ttlMinutes = 5,
    ) {}

    public function getArpTable(): Collection
    {
        return $this->cache->remember('network_inventory.arp', $this->ttlMinutes * 60, 
            fn () => $this->inner->getArpTable()
        );
    }
    // ... same pattern for getForwardingDatabase(), getIpv6Neighbors(), etc.
    // resolveIpToPort() uses the cached ARP+FDB, not its own cache key
    // getDeviceList() cached separately
}
```

Registered in `AppServiceProvider`:

```php
$this->app->singleton(NetworkInventoryInterface::class, function () {
    $inner = new LibreNmsService(...);
    return new CachedNetworkInventoryService($inner, app('cache.store'));
});
```

### 4.2 Replace IpAddress::getLNMSData()

Current: raw SQL on `lnms` DB connection returning `{mac, port: {switch, interface, status, adminStatus, speed}, portUpdatedAt}`.

Replacement approach:
1. Resolve `NetworkInventoryInterface` from container
2. Call `resolveIpToPort()` for IP→MAC→port
3. For port details (status, speed, etc.), call `NetworkSwitchInterface::getPortStatus()` if the switch adapter is available
4. Compose the same shape of data for backward compatibility with existing views

The `__get` magic method on `IpAddress` for `mac`, `port`, `portUpdatedAt` will be updated to use the service-based approach.

### 4.3 Fix DI in IpAddress

**`shutPort()` / `unshutPort()`:**

Current:
```php
$cisco = new CiscoService($this->port->switch);
$cisco->shutInterface($this->port->interface);
```

Replace with:
```php
$switch = app(NetworkSwitchInterface::class);
$switch->shutdownPort($this->port->interface);
```

This uses the already-registered `CiscoSwitchAdapter` via DI, making it testable and removing the hard-coded `CiscoService` instantiation.

**Note:** The current system is configured for a single Cisco switch (`APERTURE_CISCO_HOSTNAME`). The `$this->port->switch` value from LibreNMS data may include the switch hostname, but the `NetworkSwitchInterface` singleton targets the configured switch. If multi-switch support is needed in the future, the interface can be extended to accept a switch identifier, but for now the single-switch assumption matches the existing config.

### 4.4 Remove `lnms` Database Connection

After 4.2 is complete, the `lnms` database connection is no longer needed in `IpAddress`. It should be removed from the model. The connection config itself stays in `config/database.php` in case other parts of the system need it, but `IpAddress` no longer queries it directly.

---

## 5. Implementation Order

### 3A: Foundation — MacAddress Model & MAC Resolution
- Migration: `mac_addresses` table + `mac_address_id` FK on `ip_addresses`
- `MacAddress` model with factory, relationships
- `NormalizeMacAddress` cast
- `MacAddressResolverInterface` + `MacAddressResolver` implementation
- Wire into `IpAddress::allow()` for auth-time MAC linkage
- Tests: unit (model, normalization, relationships), feature (MAC resolution, allow-time linkage)

### 3B: Background Scan Job
- `ScanNetworkDevices` job: resolve unlinked IPs, auto-allow known MACs, Xbox OUI detection
- Config: `aperture.auto_allow.*`
- Schedule in `Console\Kernel`
- Tests: feature tests with mocked DHCP/ARP/OUI data

### 3C: IPv6 Automation
- Portal JS: fetch external IPv6 API, POST to `/ipv6`
- Config: `aperture.ipv6.detection_enabled`, `aperture.ipv6.detection_endpoint`
- `getIpv6Neighbors()` on `NetworkInventoryInterface` and `LibreNmsService`
- `MacAddressResolver` includes IPv6 neighbor lookup
- Tests: vitest (JS fetch logic), feature (IPv6 auto-allow, IPv6 in admin)

### 3D: Cache Layer & DI Cleanup
- `CachedNetworkInventoryService` decorator
- Replace `IpAddress::getLNMSData()` with service-based resolution
- Fix `shutPort()`/`unshutPort()` DI
- Remove direct `lnms` DB dependency from `IpAddress`
- Tests: unit (cache decorator), feature (IP resolution via cached service, shut/unshut via DI)

### Dependency Graph

```
3A (MacAddress model) ─┬──→ 3B (Background scan job)
                       └──→ 3C (IPv6 automation)

3D (Cache/DI cleanup) ──── independent, can run in parallel with 3B/3C
```

3A must complete first. 3B, 3C, and 3D can proceed in any order after.

---

## 6. Files Created/Modified (Expected)

### New Files
- `database/migrations/xxxx_create_mac_addresses_table.php`
- `database/migrations/xxxx_add_mac_address_id_to_ip_addresses_table.php`
- `app/Models/MacAddress.php`
- `database/factories/MacAddressFactory.php`
- `app/Casts/NormalizeMacAddress.php`
- `app/Services/Interfaces/MacAddressResolverInterface.php`
- `app/Services/MacAddressResolver.php`
- `app/Services/CachedNetworkInventoryService.php`
- `app/Jobs/ScanNetworkDevices.php`
- Tests for all of the above

### Modified Files
- `app/Models/IpAddress.php` — add `mac_address_id` relationship, replace `getLNMSData()`, fix `shutPort()`/`unshutPort()` DI
- `app/Models/User.php` — add `macAddresses()` relationship
- `app/Providers/AppServiceProvider.php` — bind new services, register cached decorator
- `app/Console/Kernel.php` — schedule `ScanNetworkDevices`
- `config/aperture.php` — add `auto_allow`, `ipv6` config sections
- `app/Services/Interfaces/NetworkInventoryInterface.php` — add `getIpv6Neighbors()`
- `app/Services/LibreNmsService.php` — implement `getIpv6Neighbors()`
- Portal Vue component(s) — add IPv6 detection JS fetch
- Existing tests — update for DI changes in `IpAddress`
