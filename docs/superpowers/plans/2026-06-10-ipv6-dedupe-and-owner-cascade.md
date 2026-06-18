# IPv6 normalization, IP dedupe, and MAC-owner cascade

Date: 2026-06-10
Branch: feature/improvements

## Problem (production-verified)

MAC `36:e2:de:7f:ce:8b` on production has DHCPv6-discovered IPv6
`2a0f:85c1:d91:2100:7485:ac59:8bc9:72e6`, but the IP page showed no MAC link and
no user. Production audit log shows the same IPv6 "discovered" as a NEW
IpAddress row every 5-minute scan (#176 16:10, #177 16:15, #178 16:20 on
2026-06-10) and no `ip_mac.linked` entries from `scan_network`; the only pivot
was a side effect of an admin internet toggle (source `auth`).

### Root cause

1. `DhcpInterface::getLeases()` (OPNsense active via `OpnSenseBootstrapper`;
   Cisco/VyOs likewise) returns IPv6 strings unnormalized (uppercase observed).
   Only `getLease()` and prefix paths were normalized in earlier fixes.
2. `IpAddress` lowercases on save (attribute setter), so stored addresses are
   lowercase. Scan steps key in-memory collections by raw feed string:
   - `PersistIpsStep`: `whereIn(...)->get()->keyBy('address')` then
     `->get($rawIp)` — SQL matches (MariaDB ci collation) but the PHP key
     lookup is case-sensitive → miss → creates a duplicate row each scan
     (`ip_addresses.address` has no unique index).
   - `LinkIpMacStep`: same miss → pivot never created by the scan.
   - `PersistDhcpLeasesStep:21`: same pattern.
3. user↔IP association only happens at portal login (one-hop cascade snapshot)
   or portal IPv6 detection. An IPv6 (SLAAC privacy address) appearing after
   login never gets the user associated, even once linked to a user-owned MAC.

## Fix design

### A. Normalize at boundaries and consumers
- `App\Services\ValueObjects\DhcpLease` and `ArpEntry`: normalize `ip` in the
  constructor via `IpAddress::normalize()` (single chokepoint for all
  integrations; keep promoted readonly for other fields).
- `PersistIpsStep`, `LinkIpMacStep`, `PersistDhcpLeasesStep`: wrap feed IPs
  with `IpAddress::normalize()` when building keys/lookups (defense in depth).

### B. Dedupe migration + unique index
New migration ordered AFTER `2026_06_10_000001_normalize_ipv6_addresses_lowercase`:
- Group `ip_addresses` by `LOWER(address)`; keeper = lowest id per group.
- For each duplicate row, repoint references respecting unique keys:
  - `user_ip_addresses.ip_address_id` (skip/delete if (user_id, keeper) exists;
    keep max last_seen_at)
  - `ip_address_mac_address` (unique (ip,mac): merge into keeper row, keep max
    last_seen_at, keep earliest-created source)
  - `dhcp_leases` (unique (integration, ip_address_id): keep most recently
    updated row per (integration, keeper))
  - `audit_logs` morphs: `subject_{type,id}` and `related_{type,id}` where type
    is the IpAddress morph class
- Merge scalars into keeper: `internet_enabled` = logical OR, `last_seen_at` =
  max, `comment` = keeper's unless null. Delete duplicates.
- Add unique index on `ip_addresses.address`.
- Must work on MariaDB (prod) and SQLite (tests).

### C. Cascade MAC owner to linked IPs (behavior change — ADR-011)
- New event `App\Events\IpMacLinked(IpAddress $ip, MacAddress $mac,
  string $source, string $process)`.
- New listener `App\Listeners\CascadeMacOwnershipOnLink`:
  - no-op if `mac->user_id === null`
  - no-op if the IP is associated with a DIFFERENT user (matches
    `UserNetworkAssociationService::cascadeMacOwnership` semantics)
  - no-op if owner already associated with the IP
  - else `UserNetworkAssociationService::addIp(owner, ip->address,
    cascade: false)` + `AuditLog ip.user_cascaded` (process from event,
    metadata includes link source and mac)
  - listener resolves UNAS from container (avoids circular DI with
    IpAddressActionService)
- Dispatch from BOTH the attach and update-existing branches (heals
  production rows linked via `auth` that never got the user) in:
  - `LinkIpMacStep`
  - `IpAddressActionService::enableInternet`
  - `PortalController::ipv6`

## Out of scope
- Compressed/expanded IPv6 canonicalization (inet_pton round-trip) — only
  case mismatches are observed; note for future ADR if it bites.
- Merging `App\Services\Dhcp\OpnSenseDhcpService` vs
  `App\Services\OpnSense\OpnSenseDhcpService` duplication.

## Workflow log / handover

```json
{
  "task": "IPv6 normalize at boundaries, dedupe IpAddress rows + unique index, cascade MAC owner to linked IPs",
  "gates": {
    "coverage": true,
    "tests": true,
    "formatting": true,
    "review": true,
    "ui": true
  },
  "gate_notes": "ui: no user-interface changes in this set; impeccable audit step not applicable. coverage: all touched files 100% lines (incl. 4 pre-existing guard lines closed); overall 96.7% reflects known pre-existing debt.",
  "actions": [
    {
      "type": "test-creation",
      "agent": "test-automator",
      "description": "Red tests: VO normalization, case-insensitive scan steps, dedupe migration, IpMacLinked dispatches, cascade listener",
      "created": [
        "tests/Unit/Services/ValueObjects/ValueObjectIpNormalizationTest.php",
        "tests/Unit/Services/IpAddressActionServiceEventTest.php",
        "tests/Feature/NetworkDeviceTracking/ScanStepsCaseInsensitiveIpv6Test.php",
        "tests/Feature/NetworkDeviceTracking/CascadeMacOwnershipOnLinkTest.php",
        "tests/Feature/NetworkDeviceTracking/ScanNetworkDevicesOwnerCascadeTest.php",
        "tests/Feature/Migrations/DedupeIpAddressesAndAddUniqueIndexMigrationTest.php"
      ],
      "updated": ["tests/Feature/PortalControllerTest.php"],
      "success": true
    },
    {
      "type": "implementation",
      "agent": "laravel-expert",
      "description": "VO-boundary + scan-step normalization, IpMacLinked event, CascadeMacOwnershipOnLink listener, dispatches at 3 sites, dedupe migration + unique index; 4 stale uppercase assertions in DHCP service tests updated",
      "created": [
        "app/Events/IpMacLinked.php",
        "app/Listeners/CascadeMacOwnershipOnLink.php",
        "database/migrations/2026_06_10_100000_dedupe_ip_addresses_and_add_unique_index.php"
      ],
      "updated": [
        "app/Services/ValueObjects/DhcpLease.php",
        "app/Services/ValueObjects/ArpEntry.php",
        "app/Jobs/NetworkScan/PersistIpsStep.php",
        "app/Jobs/NetworkScan/LinkIpMacStep.php",
        "app/Jobs/NetworkScan/PersistDhcpLeasesStep.php",
        "app/Services/IpAddressActionService.php",
        "app/Http/Controllers/PortalController.php",
        "app/Providers/EventServiceProvider.php",
        "tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php",
        "tests/Unit/Services/Dhcp/OpnSenseDhcpServiceTest.php",
        "tests/Unit/Services/VyOs/VyOsDhcpServiceTest.php"
      ],
      "success": true
    },
    {
      "type": "code-review",
      "agent": "code-reviewer",
      "description": "Approved except: dedupe migration early-returned on empty table so fresh installs never got the unique index (high). Lows: swallowed dispatch logging, untested unmanaged-IP listener branch.",
      "problems": {
        "high": ["migration empty-table early return skips unique index on fresh installs"],
        "low": ["no log on swallowed auth-path cascade failures", "listener unmanaged-IP branch untested"]
      },
      "success": false
    },
    {
      "type": "implementation",
      "agent": "laravel-expert",
      "description": "Removed early return (index always created), migration test drops index before seeding legacy duplicates, Log::warning in enableInternet catch, unmanaged-IP listener test",
      "updated": [
        "database/migrations/2026_06_10_100000_dedupe_ip_addresses_and_add_unique_index.php",
        "tests/Feature/Migrations/DedupeIpAddressesAndAddUniqueIndexMigrationTest.php",
        "app/Services/IpAddressActionService.php",
        "tests/Feature/NetworkDeviceTracking/CascadeMacOwnershipOnLinkTest.php"
      ],
      "success": true
    },
    {
      "type": "qa",
      "agent": "qa-expert",
      "description": "Full suite 2717 green; touched-file coverage 100% except 4 pre-existing guard lines; MariaDB 10.11 smoke: dedupe merged seeded case-variant duplicates, real BTREE unique index, ERROR 1062 on dup insert, no data loss",
      "success": true
    },
    {
      "type": "test-creation",
      "agent": "test-automator",
      "description": "Closed the 4 pre-existing guard lines: LinkIpMacStep 95%→100%, PersistDhcpLeasesStep 88.9%→100%. Full suite 2721 green, pint pass.",
      "updated": ["tests/Feature/NetworkDeviceTracking/ScanStepsCaseInsensitiveIpv6Test.php"],
      "success": true
    }
  ]
}
```
