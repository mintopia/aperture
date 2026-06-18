# Managed Network Ranges

## Summary

Add admin-configurable IPv4/IPv6 network ranges that control which IPs Aperture manages. Only IPs within these ranges get linked to users, added to the firewall, or have policies applied. IPs outside managed ranges are silently ignored. This prevents remote admin sessions or VPN connections from polluting user IP records.

## Current State

`User::addIp(string $clientIp): IpAddress` is the single entry point for IP management. It unconditionally creates/updates `IpAddress` records, links them to users via `UserIpAddress`, and applies policies via `IpPolicyService::applyUserPolicy()`. There is no concept of "managed" vs "unmanaged" networks — every IP that touches Aperture gets tracked.

### Call Sites (6 total)

| Call Site | File | Line | Return Usage |
|-----------|------|------|-------------|
| DashboardController | `Portal/DashboardController.php` | 27 | `$ip->mac`, `$ip->address`, `$ip->internet_enabled` |
| CaptivePortalController | `CaptivePortalController.php` | 92 | Passed to `$actionService->enableInternet($ip)` |
| PortalController (index) | `PortalController.php` | 22 | Entire object passed to view |
| PortalController (status) | `PortalController.php` | 38 | `$ip->address`, `$ip->internet_enabled` |
| PortalController (ipv6) | `PortalController.php` | 65 | `$ip->address`, `$ip->internet_enabled` |
| ScanNetworkDevices | `Jobs/ScanNetworkDevices.php` | 115 | `$ip->mac_address_id`, `$ip->internet_enabled` |

## Changes

### 1. NetworkRangeService

New service class: `App\Services\NetworkRangeService`

```php
class NetworkRangeService
{
    public function isManaged(string $ip): bool
}
```

**Behavior:**
- Reads `network.managed_ranges_v4` and `network.managed_ranges_v6` from `Setting::get()`.
- Parses stored JSON arrays of CIDR strings.
- Determines whether the given IP falls within any configured range using `inet_pton()` + bitwise subnet masking.
- Caches parsed ranges for the request lifecycle (bound as singleton in container).
- Returns `false` if no ranges are configured (empty = deny all).
- Detects IP version (v4 vs v6) and checks against the appropriate range set.

**CIDR matching algorithm:**
1. `inet_pton($ip)` to get binary representation.
2. For each CIDR in the matching version's range list, compute the network address and mask.
3. Bitwise AND the IP with the mask; compare to the network address.
4. Return `true` on first match.

No external packages needed — PHP's `inet_pton()` handles both v4 and v6.

### 2. User::addIp() Change

**Before:**
```php
public function addIp(string $clientIp): IpAddress
```

**After:**
```php
public function addIp(string $clientIp): ?IpAddress
```

First line becomes:
```php
if (!app(NetworkRangeService::class)->isManaged($clientIp)) {
    return null;
}
```

Rest of the method is unchanged.

### 3. Call Site Updates

Each of the 6 call sites must handle `null` return from `addIp()`.

**DashboardController (line 27):**
```php
$ip = $user->addIp((string) $request->getClientIp());
```
If `$ip` is null: skip IP-specific data in the Inertia response. Pass `null` for `currentIpv4`, `macAddress`, `internetEnabled`. The dashboard still renders — it just omits IP-specific widgets.

**CaptivePortalController (line 92):**
```php
$ip = $user->addIp($flowData['ip'] ?? $request->getClientIp() ?? '0.0.0.0');
```
If `$ip` is null: skip the `$actionService->enableInternet($ip)` call. Auth flow still completes — user logs in but no firewall rule is applied (expected for unmanaged networks).

**PortalController index (line 22):**
If `$ip` is null: pass `null` for the `ip` view variable. View handles gracefully.

**PortalController status (line 38):**
If `$ip` is null: return JSON with `ip: null`, `internetEnabled: false`.

**PortalController ipv6 (line 65):**
If `$ip` is null: return JSON with `ip: null`, `internetEnabled: false`.

**ScanNetworkDevices (line 115):**
If `$ip` is null: skip the `mac_address_id` assignment and `internet_enabled` update. The unmanaged IP is not tracked.

### 4. Settings Storage

Three setting keys, all using the existing `Setting` model:

| Key | Name | Default | Format |
|-----|------|---------|--------|
| `network.managed_ranges_v4` | Managed IPv4 Ranges | `["0.0.0.0/0"]` | JSON array of CIDR strings |
| `network.managed_ranges_v6` | Managed IPv6 Ranges | `["::/0"]` | JSON array of CIDR strings |
| `network.dns_filter_default` | DNS Filter Default | `0` | Boolean (as `"1"`/`"0"` string) |

The `general.dns_filtering_default` key is migrated to `network.dns_filter_default`. The old key is removed from `GeneralSettingsController`.

Default values (`0.0.0.0/0` and `::/0`) mean "all IPs are managed" — matching current behavior. Empty arrays mean "no IPs are managed" (deny all).

### 5. Network Settings Page

#### Route

```
GET  /admin/settings/network    → NetworkSettingsController@show
PUT  /admin/settings/network    → NetworkSettingsController@update
```

Route names: `admin.settings.network`, `admin.settings.network.update`

#### Controller: `NetworkSettingsController`

Follows the `GeneralSettingsController` pattern.

**show():**
- Reads `network.managed_ranges_v4`, `network.managed_ranges_v6`, `network.dns_filter_default` from `Setting`.
- Decodes JSON arrays into newline-separated strings for the textarea.
- Falls back to `["0.0.0.0/0"]` / `["::/0"]` if settings don't exist yet.
- Passes `breadcrumbs`: Admin → Services → Network.

**update():**
- Validates `managed_ranges_v4` and `managed_ranges_v6` as nullable strings.
- Splits by newline, trims whitespace, filters empty lines.
- Validates each line is valid CIDR notation (custom validation rule or closure).
- Validates `dns_filter_default` as boolean.
- Stores ranges as JSON arrays via `Setting::set()`.
- Redirects back with success message.

**CIDR validation:** Each line must match `ip/prefix` format where `ip` is a valid IPv4 or IPv6 address and prefix is a valid integer for the address family (0-32 for v4, 0-128 for v6). Use `inet_pton()` to validate the IP portion.

#### Vue Page: `Admin/Settings/Network.vue`

Props: `settings` object with `managed_ranges_v4` (string), `managed_ranges_v6` (string), `dns_filter_default` (boolean).

Layout:
- Page title: "Network Settings"
- Section: **Managed Network Ranges**
  - Hint text explaining the purpose
  - **IPv4 Ranges** — textarea, one CIDR per line, placeholder: `e.g. 10.0.0.0/8`
  - **IPv6 Ranges** — textarea, one CIDR per line, placeholder: `e.g. fc00::/7`
- Section: **Network Defaults**
  - **DNS Filter Default** — checkbox with label "Enable DNS filtering for new connections"
- Save button

Uses `useForm` from Inertia. Validation errors display per-field via `FormField` component.

### 6. Sidebar Navigation

Add "Network" to the SERVICES group in `Sidebar.vue`:

```javascript
{ label: 'Network', href: route('admin.settings.network'), icon: SettingsIcon }
```

### 7. Remove DNS Filter Default from General Settings

**GeneralSettingsController:**
- Remove `dns_filtering_default` from `show()` response.
- Remove `dns_filtering_default` from `update()` validation and persistence.

**Admin/Content/Settings.vue:**
- Remove the "Network Defaults" section and `dns_filtering_default` form field.

### 8. Setting Defaults on First Load

When `NetworkSettingsController::show()` is called and the settings don't exist in the database yet, use these defaults:
- `network.managed_ranges_v4`: `["0.0.0.0/0"]`
- `network.managed_ranges_v6`: `["::/0"]`
- `network.dns_filter_default`: `false`

These match current behavior — all IPs managed, DNS filtering off by default.

## Files Modified

- `app/Services/NetworkRangeService.php` — **NEW**: CIDR matching service
- `app/Http/Controllers/Admin/NetworkSettingsController.php` — **NEW**: settings page controller
- `resources/js/Pages/Admin/Settings/Network.vue` — **NEW**: settings page
- `app/Models/User.php` — update `addIp()` return type and add guard
- `app/Http/Controllers/Portal/DashboardController.php` — handle null from `addIp()`
- `app/Http/Controllers/CaptivePortalController.php` — handle null from `addIp()`
- `app/Http/Controllers/PortalController.php` — handle null from `addIp()` (3 methods)
- `app/Jobs/ScanNetworkDevices.php` — handle null from `addIp()`
- `app/Http/Controllers/Admin/GeneralSettingsController.php` — remove DNS filter default
- `resources/js/Pages/Admin/Content/Settings.vue` — remove DNS filter default section
- `resources/js/Components/Admin/Sidebar.vue` — add Network nav item
- `routes/web.php` — add network settings routes
- `app/Providers/AppServiceProvider.php` — register `NetworkRangeService` as singleton

## Files NOT Modified

- `app/Services/IpPolicyService.php` — no changes; policy is only applied after the `addIp()` guard passes
- `app/Http/Controllers/Admin/IpAddressController.php` — admin IP management (create/edit) is independent of managed ranges
- `app/Models/IpAddress.php` — no model changes needed

## Test Coverage

### NetworkRangeService Tests (Unit)
- IPv4 in range → `true`
- IPv4 outside range → `false`
- IPv6 in range → `true`
- IPv6 outside range → `false`
- Multiple ranges, IP matches second → `true`
- Empty ranges → `false` (deny all)
- `0.0.0.0/0` matches any IPv4 → `true`
- `::/0` matches any IPv6 → `true`
- Edge cases: /32, /128 single-host ranges
- Invalid IP string → `false`

### User::addIp() Tests (Feature)
- IP in managed range → returns IpAddress, creates records
- IP outside managed range → returns null, no records created
- Default settings (0.0.0.0/0 + ::/0) → all IPs managed (backwards compatible)

### NetworkSettingsController Tests (Feature)
- Show page loads with current settings
- Update with valid CIDRs → saves correctly
- Update with invalid CIDR → validation error
- Update with empty ranges → saves empty arrays
- DNS filter default toggle
- Non-admin cannot access

### Call Site Tests (Feature)
- DashboardController with unmanaged IP → dashboard renders without IP data
- CaptivePortalController with unmanaged IP → auth completes without firewall rule
- ScanNetworkDevices with unmanaged IP → IP skipped

## Out of Scope

- Migration of existing IPs when ranges change (existing IPs stay; only new `addIp()` calls are gated)
- Bulk cleanup tool for IPs outside managed ranges
- Per-user range overrides
- DHCP integration
