# Exact IPv6 DHCP range totals (BCMath end-to-end)

Date: 2026-06-11
Branch: feature/improvements

## Problem (production-verified)

Dashboard DHCP pool table shows `Used 3 / Total 0 / 0%` for the two /64
DHCPv6 pools (and the IPv4 pools show correct totals 117/155 — exclusion-aware
Cisco effective ranges, proving Cisco is the active dhcp integration).
Root cause: `CiscoDhcpService::ipv6TotalAddresses()` returns null for any
prefix wider than /96 (`$bits <= 32` guard — int shift), so /64 pools sync
`total_addresses = null`; `HomeController::getDhcpPools()` renders null as 0
and utilisation 0%, which reads as broken. `DhcpRange::totalAddresses` is
`?int` and cannot represent 2^64 (PHP_INT_MAX = 2^63-1) even though the
`dhcp_range_records.total_addresses` column is already a string and the pool
status path already uses BCMath.

## Fix design

### A. Value object and producers
- `DhcpRange::totalAddresses` becomes `?string` (exact decimal
  numeric-string). All producers cast:
  - Cisco v4: `(string) $total`.
  - Cisco v6: new shared helper computes `bcpow('2', 128 - len)` for ANY
    prefix length (cap removed); utilisation = `(float) bcdiv(used, total, 6)`
    when total > 0.
  - OPNsense (`app/Services/OpnSense/` — the legacy `app/Services/Dhcp/`
    variant no longer exists on disk) and VyOS `buildEnrichedRange`: cast int
    math to string (no behavior change otherwise).
  - Read-path survey addition: `Admin\DhcpController::index` also casts
    totals to int and must switch to string passthrough (pinned by tests).
- Shared helper: `App\Support\Ipv6Prefix::totalAddresses(string $cidr): ?string`
  (null for malformed/no-/len input). Cisco uses it; available for
  OPNsense/VyOS prefix fallbacks later (out of scope).
- `SyncDhcpData::performRangeSync` stores the string verbatim (drop the
  `(string)` cast conditional).

### B. Read path and display
- `HomeController::getDhcpPools()` returns `total` as string ('0' when null)
  and keeps `used`/`utilisation` numeric.
- Admin DHCP pages that render range totals (find all consumers of
  totalAddresses / total_addresses, e.g. resources/js/Pages/Admin/Dhcp/*)
  use ONE shared JS formatter: totals < 10^6 render with locale grouping;
  larger render in scientific notation with 2 significant digits (e.g.
  2^64 -> "1.8e19") with the exact value in the title attribute.
- Utilisation for huge pools correctly shows ~0%.

## Out of scope
- Prefix-derived total fallback for OPNsense/VyOS prefix-only pools.
- The "—" stateless (prefix-less) v6 pool row display.

## Workflow log / handover

```json
{
  "task": "Exact IPv6 DHCP range totals via numeric-string VO + bcpow, compact display",
  "gates": {
    "coverage": true,
    "tests": true,
    "formatting": true,
    "review": true,
    "ui": true
  },
  "gate_notes": "coverage: all touched PHP files 100% lines (VyOs 344/346 pre-existing exempt). ui: audit 19/20, no medium+ in changed code; P3 notes recorded (title-only tooltip, formatter undefined fallback, grouping inconsistency, bar floor) plus pre-existing NaN-width bar on zero-total pools.",
  "actions": [
    {"type": "test-creation", "agent": "test-automator", "description": "Red tests: Ipv6Prefix helper contract, Cisco /64-/96 exact totals, int-to-string flips across providers, 2^64 persistence, dashboard+DHCP controller string totals, formatPoolTotal + page rendering", "created": ["tests/Unit/Support/Ipv6PrefixTest.php"], "updated": ["tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php", "tests/Unit/Services/Dhcp/OpnSenseDhcpServiceRangeUsageTest.php", "tests/Unit/Services/Dhcp/OpnSenseDhcpServiceTest.php", "tests/Unit/Services/VyOs/VyOsDhcpServiceTest.php", "tests/Feature/Jobs/SyncDhcpDataTest.php", "tests/Feature/Admin/HomeControllerDhcpPoolsTest.php", "tests/Feature/Admin/DhcpControllerTest.php", "tests/js/helpers.spec.js", "tests/js/Components/Admin/DhcpPoolsCard.spec.js", "tests/js/Pages/Admin/Dhcp/Index.spec.js"], "success": true},
    {"type": "implementation", "agent": "laravel-expert", "description": "Ipv6Prefix bcpow helper, DhcpRange totals ?string, Cisco cap removed + bcdiv utilisation, providers cast, controllers passthrough, formatPoolTotal + BigInt sums, migration DECIMAL(39,0)->VARCHAR(64) (column was not string as planned; SQLite NUMERIC affinity corrupted 2^64)", "created": ["app/Support/Ipv6Prefix.php", "database/migrations/2026_06_11_000001_change_dhcp_range_records_total_addresses_to_string.php"], "updated": ["app/Services/ValueObjects/DhcpRange.php", "app/Services/Cisco/CiscoDhcpService.php", "app/Services/OpnSense/OpnSenseDhcpService.php", "app/Services/VyOs/VyOsDhcpService.php", "app/Jobs/SyncDhcpData.php", "app/Http/Controllers/Admin/HomeController.php", "app/Http/Controllers/Admin/DhcpController.php", "resources/js/helpers.js", "resources/js/Components/Admin/DhcpPoolsCard.vue", "resources/js/Pages/Admin/Dhcp/Index.vue", "tests/Feature/Admin/DashboardControllerTest.php"], "success": true},
    {"type": "code-review", "agent": "code-reviewer", "description": "Approved except one medium: uncovered zero-total ternary branch CiscoDhcpService:134. Migration verified MariaDB-safe incl. rollback; dhcp_pool_statuses DECIMAL deferral verified safe (int-bounded writers).", "problems": {"medium": ["CiscoDhcpService:134 ': 0.0;' branch uncovered"]}, "success": false},
    {"type": "test-creation", "agent": "test-automator", "description": "Zero-total branch covered via mocked parser; CiscoDhcpService 179/179 (100%)", "updated": ["tests/Unit/Services/Cisco/CiscoDhcpServiceTest.php"], "success": true},
    {"type": "qa", "agent": "qa-expert", "description": "PHP 2760 green x5 runs, vitest 1570 green, build OK; touched files 100% (VyOs pre-existing gap exempt); MariaDB migration up/rollback/re-up with exact value preservation incl. 2^64", "success": true},
    {"type": "ui-audit", "agent": "audit", "description": "Code-level impeccable audit 19/20, anti-patterns pass, no medium+ in changed code; BigInt sum also fixed a pre-existing string-concatenation aggregate bug", "success": true}
  ]
}
```
