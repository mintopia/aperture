# Plan: Dashboard DHCP pools from synced data + stale IP→MAC purge action

Date: 2026-06-10
Branch: feature/improvements

## Background

1. **Bug:** `Admin/HomeController::getDhcpPools()` (app/Http/Controllers/Admin/HomeController.php:106) injects `DhcpInterface` and calls `getRanges()` live. With Cisco active this opens an SSH session to the switch (`CiscoDhcpService::fetchSnapshot()` runs ~6 `show` commands) on every dashboard load. `SyncDhcpData` already runs every minute (app/Console/Kernel.php:36) and persists ranges into `DhcpRangeRecord`; `Admin/DhcpController::index()` already reads from that table. The dashboard is the odd one out.
2. **Feature:** Admin option, with a confirmation prompt, to clear IP→MAC mapping data (`ip_address_mac_address` pivot, model `App\Models\IpAddressMacAddress`, column `last_seen_at`) older than X days.

## Task A — Dashboard DHCP pools read DhcpRangeRecord

- Rewrite `HomeController::getDhcpPools()` to query `DhcpRangeRecord` filtered by the active DHCP integration (mirror `DhcpController::activeIntegration()` / `CapabilityAssignment where capability=dhcp`; extract a shared helper rather than duplicating if reviewer agrees).
- Remove the `DhcpInterface` injection from `index()`.
- Preserve the deferred-prop payload contract exactly: `{name, network, used, total, utilisation}` with the same semantics as `DhcpRange` VO (`utilisation` is the raw float stored by SyncDhcpData; cast DB strings: `(int) used_addresses`, `(int) total_addresses`, `(float) utilisation`, fallbacks 0/0/0.0; `name` = description ?? interface; `network` = subnet ?: prefix).
- Tests (Red first): feature test that seeds `DhcpRangeRecord` rows + capability assignment, loads the dashboard deferred props, asserts pools come from the DB; bind a `DhcpInterface` mock that **fails the test if `getRanges()` is called**.

## Task B — Admin "clear stale IP→MAC mappings" action

- **Backend:** `NetworkSettingsController::clearIpMacMappings(Request)`:
  - Validate: `days` => required|integer|min:1|max:3650; `password` => required|string.
  - Password gate mirrors `HomeController::reset()` exactly (null-password guard → error; `Hash::check` failure → error).
  - Delete `IpAddressMacAddress::where('last_seen_at', '<', now()->subDays($days))` ("more than X days old" = strict <). Capture deleted count.
  - `AuditLog::record(action: 'network.ip_mac_mappings.cleared', actor/subject: user, process: 'admin', metadata: ['days' => $days, 'deleted' => $count, 'ip' => clientIp])`.
  - Redirect back with success flash including count.
- **Route:** POST `/settings/network/ip-mac-mappings/clear`, name `admin.settings.network.ip-mac.clear`, inside the existing admin group in `routes/web.php` (ADR-008: single routes file).
- **UI:** New "Maintenance" section on `resources/js/Pages/Admin/Settings/Network.vue`:
  - Days input (default 30) + destructive-styled button.
  - Clicking opens a confirmation dialog (mirror the reset dialog in `resources/js/Pages/Admin/Dashboard.vue` ~line 215) prompting for the admin password and restating the day count.
  - `data-testid` on every element under test. Follow Dispatch design system (`docs/design-system.md`: OKLCH theme, no cards/accent-lines/border-l).
- **Tests (Red first):**
  - Feature: deletes only rows strictly older than X days (boundary row at exactly X days survives); wrong password → error + nothing deleted; user with null password → error; validation failures for days (missing, 0, non-int); audit log row recorded with days+count; success flash contains count.
  - Playwright journey: open network settings → enter days → confirm dialog with password → success state (per quality-guidelines user-journey rule).

## Task C — IPv6 addresses lowercase throughout, enforced at the OPNsense boundary

User report: IPv6 addresses must be lowercase everywhere, and DEFINITELY when sent to OPNsense. Prior commits (fc3cdc2, 4d9bd88) normalized stored prefixes and DHCP lease lookups; the OPNsense boundary is still raw.

Canonical helper: `App\Models\IpAddress::normalize()` (lowercases IPv6, passes IPv4 through). Use it everywhere below — no new helper.

- **Correction (2026-06-10):** `app/Services/Firewalls/OpnSense.php` was deleted in 31166de; the live OPNsense boundaries are ONLY the two below (current code uses array_flip/isset diffing from e05100e and contains zero normalize() calls).
- `app/Services/OpnSense/OpnSenseCaptivePortal.php` — normalize `$ip` before POSTing (`addIp`), compare sessions case-insensitively (`removeIp`), normalize fetched IPs (`fetchConnectedIps`), normalize both sides of the `reconcile` diff.
- `app/Services/OpnSense/OpnSenseRateLimiter.php` — same treatment for its add/remove/fetch/reconcile methods (no duplicate shaper hosts on case mismatch; stale uppercase hosts rewritten lowercase).
- Sweep (verified against working tree 2026-06-10; lease lookup in `OpnSense/OpnSenseDhcpService` already fixed by 4d9bd88):
  - `app/Http/Controllers/Api/CaptivePortalApiController.php:18` — `where('address', $clientIp)` raw; normalize input.
  - `app/Http/Controllers/Portal/StatsController.php:36` — same.
  - `app/Http/Controllers/Admin/UserController.php:50` — IP filter, exact match raw; normalize.
  - `app/Http/Controllers/Admin/IpAddressController.php:39` — address filter, exact match raw; normalize.
  - `app/Services/LibreNms/LibreNmsIpMacResolver.php` — dedupes `$entry->ip` raw and passes entries downstream unnormalized; normalize IPv6 in entries from `getArpTable()`/`getIpv6Neighbors()` (at the LibreNMS ingestion point so `ScanNetworkDevices`/`linkIpMac` never see uppercase).

Tests: unit tests on the OPNsense services using mocked HTTP clients, asserting (a) uppercase IPv6 input is sent lowercase in request payloads, (b) removals/dedup match case-insensitively against uppercase entries returned by the firewall, (c) reconcile treats `2001:DB8::1` (firewall) and `2001:db8::1` (DB) as unchanged, (d) IPv4 passes through untouched.

## Quality gates (CLAUDE.md)

Pint, PHPStan L8 (no unjustified baseline), Rector (Laravel set), PHPUnit parallel + coverage (`XDEBUG_MODE=coverage`; new/changed code 100% — note ~96.4% pre-existing repo debt is out of scope), eslint + prettier + vitest for JS, impeccable audit for the UI section.

Test quirk: `artisan test` output must be redirected to a file (mock server interference). SQLite DB, array cache/session.

## Workflow state (handover)

```json
{
  "task": "Dashboard DHCP pools from DhcpRangeRecord; admin purge of stale IP→MAC mappings; IPv6 lowercase at OPNsense boundary + sweep",
  "gates": {"coverage": true, "tests": true, "formatting": true, "review": true, "ui": true},
  "completed": "2026-06-10",
  "actions": [
    {"type": "test-creation", "agent": "test-automator", "description": "Red: 16 tests (dashboard-from-DB guard incl. throwing DhcpInterface; purge validation/password/boundary/audit)", "created": ["tests/Feature/Admin/HomeControllerDhcpPoolsTest.php", "tests/Feature/Admin/NetworkSettingsClearIpMacTest.php"], "success": true},
    {"type": "test-creation", "agent": "test-automator", "description": "Red: 10 OPNsense boundary IPv6-case tests; discovered Firewalls/OpnSense.php already deleted (31166de)", "updated": ["tests/Unit/Services/OpnSense/OpnSenseCaptivePortalTest.php", "tests/Unit/Services/OpnSense/OpnSenseRateLimiterTest.php"], "success": true},
    {"type": "test-creation", "agent": "test-automator", "description": "Red: 7 sweep tests (4 controllers raw where('address'); LibreNms resolver normalization/dedupe)", "updated": ["tests/Feature/Api/CaptivePortalApiControllerTest.php", "tests/Feature/Portal/StatsControllerTest.php", "tests/Feature/Admin/UserControllerTest.php", "tests/Feature/Admin/IpAddressControllerTest.php", "tests/Unit/Services/LibreNms/LibreNmsIpMacResolverTest.php"], "success": true},
    {"type": "implementation", "agent": "laravel-expert", "description": "Tasks A+B green: HomeController reads DhcpRangeRecord via shared CapabilityAssignment::activeIntegration(); clearIpMacMappings + route + Network.vue Maintenance UI", "updated": ["app/Http/Controllers/Admin/HomeController.php", "app/Http/Controllers/Admin/NetworkSettingsController.php", "app/Http/Controllers/Admin/DhcpController.php", "app/Models/CapabilityAssignment.php", "routes/web.php", "resources/js/Pages/Admin/Settings/Network.vue"], "success": true},
    {"type": "implementation", "agent": "laravel-expert", "description": "Task C boundary green: IpAddress::normalize at all OpnSenseCaptivePortal/OpnSenseRateLimiter add/remove/fetch/reconcile paths", "updated": ["app/Services/OpnSense/OpnSenseCaptivePortal.php", "app/Services/OpnSense/OpnSenseRateLimiter.php"], "success": true},
    {"type": "implementation", "agent": "laravel-expert", "description": "Task C sweep green: normalized client-IP/filter lookups + LibreNms ingestion", "updated": ["app/Http/Controllers/Api/CaptivePortalApiController.php", "app/Http/Controllers/Portal/StatsController.php", "app/Http/Controllers/Admin/UserController.php", "app/Http/Controllers/Admin/IpAddressController.php", "app/Services/LibreNms/LibreNmsIpMacResolver.php"], "success": true},
    {"type": "code-review", "agent": "code-reviewer", "description": "Sound; verified Inertia float-assertion claim + dashboard test rewrite; HIGH: missing Playwright journey test; 4 lows", "success": false},
    {"type": "implementation", "agent": "implementation-round2", "description": "Added e2e journey spec (ran 4/4 vs artisan-serve fallback), days-empty guard, removed dup error, no-assignment test", "created": ["tests/e2e/admin-network-maintenance.spec.js"], "updated": ["resources/js/Pages/Admin/Settings/Network.vue", "tests/Feature/Admin/HomeControllerDhcpPoolsTest.php"], "success": true},
    {"type": "qa", "agent": "qa-expert", "description": "2688 PHP tests / 1538 vitest / 4 e2e green; 100% coverage on all changed lines (sub-100% files = pre-existing untouched code, hunk-verified)", "success": true},
    {"type": "ui-audit", "agent": "audit", "description": "17/20; design-system clean; 2 MEDIUM a11y (modal focus trap after failed confirm; silent SR error)", "success": false},
    {"type": "implementation", "agent": "implementation-round3", "description": "ConfirmModal document-level keydown + loading-watch refocus (fixes Dashboard reset too); aria-invalid/describedby + role=alert; days required; Enter-while-processing guard; 9 new vitest tests red→green", "updated": ["resources/js/Components/UI/ConfirmModal.vue", "resources/js/Pages/Admin/Settings/Network.vue", "resources/js/Pages/Admin/Dashboard.vue", "tests/js/Components/UI/ConfirmModal.spec.js", "tests/js/Pages/Admin/Settings/Network.spec.js", "tests/js/Pages/Admin/Dashboard.spec.js"], "success": true},
    {"type": "ui-audit", "agent": "audit", "description": "Re-audit live: both mediums verified fixed in both dialogs; 7/7 e2e; one informational low (dual-modal Escape, unreachable)", "success": true}
  ]
}
```
