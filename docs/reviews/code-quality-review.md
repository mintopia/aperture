# Aperture Code Quality Review

**Date:** 2026-04-22
**Reviewer:** Code Quality Audit (Automated)
**Scope:** `app/` and `resources/js/` directories
**Branch:** `feature/improvements`

---

## Executive Summary

Aperture is a well-structured Laravel 12 + Vue 3 + Inertia.js network management application with clean separation of concerns through interfaces, value objects, and a service layer. The codebase demonstrates strong architectural foundations -- particularly the firewall/DHCP service abstraction via interfaces and the null-object pattern for optional integrations.

However, the review identified **4 Critical**, **8 High**, **12 Medium**, and **9 Low** severity issues across DRY violations, code smells, potential N+1 queries, security concerns, and inconsistent patterns. The most impactful findings are the triplicated `saveSetting` method, duplicated fallback SwitchConfig construction, the IpAddress model's use of the service locator antipattern, and the massive `AppServiceProvider.register()` method acting as a god method.

---

## 1. DRY Violations

### 1.1 Triplicated `saveSetting()` Method

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Critical** |
| **Files** | `app/Http/Controllers/Admin/ThemeSettingsController.php:61-72` |
| | `app/Http/Controllers/Admin/GeneralSettingsController.php:58-70` |
| | `app/Http/Controllers/Admin/DnsDetectionSettingsController.php:59-71` |

**Description:** The exact same `saveSetting(string $code, string $name, mixed $value): void` method is copy-pasted across three controllers. All three perform identical logic: find-or-create a `Setting` record by code and update its value.

**Suggested Fix:** Add a `set(string $code, string $name, mixed $value): void` static method to the `Setting` model (which already has `get()`), or extract a `SavesSettings` trait / base controller. The `Setting` model approach is most consistent:

```php
// In Setting model:
public static function set(string $code, string $name, mixed $value): void
{
    $setting = static::whereCode($code)->first();
    if (! $setting) {
        $setting = new static;
        $setting->code = $code;
        $setting->name = $name;
    }
    $setting->value = $value;
    $setting->save();
}
```

### 1.2 Duplicated Fallback SwitchConfig Construction

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Providers/AppServiceProvider.php:384-395` |
| | `app/Http/Controllers/Admin/IpAddressController.php:205-216` |

**Description:** Two locations construct identical fallback `SwitchConfig` objects with hardcoded `'Default Cisco Switch'` names and the same config key references (`aperture.cisco.hostname`, `aperture.cisco.username`, etc.). If any config key changes, both locations must be updated.

**Suggested Fix:** Add a static factory method to `SwitchConfig`, e.g. `SwitchConfig::defaultFallback()` or `SwitchConfig::fromConfigFallback(?string $hostname = null)`, and call it from both locations.

### 1.3 Duplicated Reconciliation Logic in OpnSense

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Services/Firewalls/OpnSense.php:339-399` (`reconcileInternet`) |
| | `app/Services/Firewalls/OpnSense.php:401-461` (`reconcileRateLimits`) |

**Description:** `reconcileInternet()` and `reconcileRateLimits()` share nearly identical structure -- both fetch current state, compare against desired state from the database, categorize IPs into added/removed/unchanged/errors arrays, and conditionally apply changes. The only differences are the field name queried (`internet_enabled` vs `rate_limit_enabled`), the fetch method (`fetchConnectedIps` vs `fetchRateLimitedIps`), and the apply methods (`updateIp`/`removeIp` vs `limitIp`/`unlimitIp`).

**Suggested Fix:** Extract a generic `reconcile(array $currentIps, string $desiredField, callable $enableAction, callable $disableAction, bool $dryRun): ReconcileResult` method.

### 1.4 Duplicated OpnSenseApiService Validation

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Services/Firewalls/OpnSenseApiService.php:28-41` |
| | `app/Services/Firewalls/OpnSenseApiService.php:74-87` |

**Description:** `getShaperRules()` and `getZones()` both perform the same config validation (check for empty endpoint, check for empty key/secret) with near-identical early-return patterns. This guard logic should be extracted.

**Suggested Fix:** Create a private `validateConfig()` method that returns an error array or null.

### 1.5 Repeated enrichRange Pattern in OpnSenseDhcpService

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Services/Dhcp/OpnSenseDhcpService.php:241-273` |
| | `app/Services/Dhcp/OpnSenseDhcpService.php:278-310` |

**Description:** `enrichIpv4RangeWithUsage()` and `enrichIpv6RangeWithUsage()` have nearly identical structure. The only difference is the IP comparison logic (`ip2long` vs `inet_pton`). Both construct the same `DhcpRange` return with 11 parameters.

**Suggested Fix:** Extract a common `buildEnrichedRange(DhcpRange $range, int $totalAddresses, int $usedAddresses): DhcpRange` helper, keeping only the IP arithmetic in the version-specific methods.

---

## 2. Code Smells

### 2.1 God Method: AppServiceProvider::register()

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Critical** |
| **Files** | `app/Providers/AppServiceProvider.php:60-178` |

**Description:** The `register()` method is 118 lines with a cyclomatic complexity of 64 (the highest in the codebase per hotspot analysis). It registers 8 different singletons with inline factory closures that contain complex configuration logic, including SSH host validation with 6+ conditions, DHCP server type matching with 3 large `match` expressions, and Prometheus/TrafficMonitor wiring with conditional logic. Combined with `boot()` (another 175 lines), this is a 340-line file that is the single highest-risk hotspot in the codebase.

**Suggested Fix:** Break into dedicated service providers or a series of private registration methods. Consider:
- `SshProxyServiceProvider` for SSH proxy client registration
- `IntegrationServiceProvider` for OpnSense, PiHole, LibreNMS, NtopNG, Borealis, Prometheus
- `DhcpServiceProvider` for the complex DHCP server mapping logic (which alone is 100+ lines)

### 2.2 Service Locator Antipattern in IpAddress Model

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Critical** |
| **Files** | `app/Models/IpAddress.php:166, 177, 183, 191` |

**Description:** The `IpAddress` model calls `app(IpAddressActionService::class)` four times across `shutPort()`, `unshutPort()`, `updateUsage()`, and `getStats()`. Additionally, `getPortInfo()` at line 139 calls `app(NetworkInventoryInterface::class)`. This is the service locator antipattern -- the model directly resolves dependencies from the container rather than having them injected, making the code harder to test and creating hidden dependencies.

**Suggested Fix:** Move these action methods to `IpAddressActionService` (which already has `shutPort()` and `unshutPort()`) and remove them from the model. If model-level convenience methods are truly needed, at minimum accept the service as a parameter:
```php
public function shutPort(IpAddressActionService $service, bool $queue = false): void
```

### 2.3 Overridden `__get()` Magic Method in IpAddress

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Models/IpAddress.php:103-115` |

**Description:** The `__get()` override handles `mac`, `port`, and `portUpdatedAt` with a `switch` statement, falling back to `parent::__get()`. This bypasses Eloquent's attribute/accessor system, making behavior opaque to static analysis and IDE support. The `port` case triggers an API call via `getPortInfo()`, which is an unexpected side effect for a property access. The `portUpdatedAt` case always returns `null`, which is dead code.

**Suggested Fix:** Use proper Eloquent accessors (`getPortAttribute()`, `getMacAttribute()`) or computed properties. Remove the dead `portUpdatedAt` case. Consider whether `port` should be a lazy-loaded API call at all versus an explicit method call.

### 2.4 Large SwitchPortController::show() Method

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Http/Controllers/Admin/SwitchPortController.php:29-157` |

**Description:** This method is 128 lines and handles port data assembly, MAC address resolution with nested loops, IP record lookups, bandwidth metrics, error metrics, and port navigation. The MAC resolution logic (lines 107-146) contains a nested `array_map` with individual database queries inside, creating potential N+1 issues.

**Suggested Fix:** Extract the MAC resolution logic into a dedicated method or service. The IP lookups inside `array_map` (line 119: `IpAddress::where('address', $ipData['ip'])->first()`) should be batch-loaded.

### 2.5 Long TestConnectionController::testSwitch() Method

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Http/Controllers/Admin/TestConnectionController.php:41-184` |

**Description:** This method is 143 lines with four separate catch blocks, each containing nearly identical `ConnectionTestLog::record()` calls and JSON response construction. The repeated pattern of log + record + json-response appears 4 times.

**Suggested Fix:** Extract a `handleError(string $message, ?int $statusCode, string $hostname): JsonResponse` method to eliminate the repeated catch-block logic.

### 2.6 Inline SVG Icons in Sidebar Component

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `resources/js/Components/Admin/Sidebar.vue:11-18` |

**Description:** Full SVG markup is stored as JavaScript string constants, each being 200+ characters. These are injected via `v-html`, which bypasses Vue's template compilation and introduces a theoretical XSS vector if the data source ever changes. The icons object spans 8 lines of dense SVG strings that are hard to read or maintain.

**Suggested Fix:** Extract icons into a separate icon component or use an icon library. At minimum, extract to a dedicated `icons.js` file.

### 2.7 Hardcoded URLs in Sidebar Navigation

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `resources/js/Components/Admin/Sidebar.vue:23-48` |

**Description:** Navigation URLs are hardcoded strings (`'/admin'`, `'/admin/users'`, etc.) rather than using Laravel's named routes via the `route()` helper. This breaks if routes are ever renamed or prefixed.

**Suggested Fix:** Use `route('admin.home')`, `route('admin.users.index')`, etc. Ziggy (already used elsewhere) provides the `route()` function in JavaScript.

---

## 3. Antipatterns

### 3.1 N+1 Query Risk in SwitchPortController::show()

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Critical** |
| **Files** | `app/Http/Controllers/Admin/SwitchPortController.php:116-138` |

**Description:** Inside a `$macs->map()` closure, each MAC address triggers:
1. `$this->macResolver->resolveMacToIps()` -- an external API call per MAC
2. For each resolved IP: `IpAddress::where('address', $ipData['ip'])->first()` -- a DB query per IP
3. For each IP: `$ipRecord->users()->with('user')->latest(...)->first()` -- another DB query per IP

With N MACs each having M IPs, this produces O(N*M) database queries plus O(N) external API calls inside a controller action.

**Suggested Fix:** Pre-load all IpAddress records for the resolved IPs in a single query before the map, then look up from the collection. Consider caching MAC-to-IP resolution results.

### 3.2 N+1 Risk in ScanNetworkDevices Job

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Jobs/ScanNetworkDevices.php:36-46, 68-104` |

**Description:** The job iterates through `$unlinkedIps` calling `$resolver->resolveIpToMac()` per IP (line 37), which is likely an external API call each time. Then in the second loop, `MacAddress::where('mac_address', $normalizedMac)->first()` is called per entry (line 72).

**Suggested Fix:** Batch the MAC address lookups by pre-loading all matching MAC records with `MacAddress::whereIn('mac_address', $macs)->get()->keyBy('mac_address')`.

### 3.3 Guzzle Client Construction in Service Constructors

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Services/Firewalls/OpnSense.php:112-119` |
| | `app/Providers/AppServiceProvider.php:312-319, 335-338` |

**Description:** The `OpnSense` constructor creates a `GuzzleHttp\Client` internally, making it impossible to mock the HTTP client in unit tests without extending the class. The same pattern exists in `AppServiceProvider` where `new Client()` is called for DHCP and DNS filtering services.

**Suggested Fix:** Accept a `Client` instance via constructor injection. The `OpnSense` class constructor already has 7 parameters; replacing the endpoint/key/secret parameters with a pre-configured `Client` would both reduce parameter count and improve testability.

### 3.4 Static Methods on OpnSenseApiService

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Services/Firewalls/OpnSenseApiService.php` (entire file) |

**Description:** All methods (`getShaperRules`, `getZones`, `makeClient`) are static. This makes the class impossible to mock in tests without wrapper patterns, and prevents dependency injection.

**Suggested Fix:** Convert to instance methods and register in the container.

### 3.5 Loose Comparison in IpAddressController::port()

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Http/Controllers/Admin/IpAddressController.php:120` |

**Description:** `$request->input('shutdown') == 1` uses loose comparison (`==` instead of `===`). While functional, this is inconsistent with the rest of the codebase which uses `(bool) $request->input(...)` or `$request->boolean()`.

**Suggested Fix:** Use `$request->boolean('shutdown')` for consistency and type safety.

---

## 4. Naming Inconsistencies

### 4.1 Mixed Naming for Port Status Queries

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `app/Http/Controllers/Admin/SwitchPortController.php:201` (`shutdown`) |
| | `app/Http/Controllers/Admin/SwitchPortController.php:215` (`enable`) |
| | `app/Http/Controllers/Admin/SwitchPortController.php:229` (`bounce`) |
| | `app/Models/IpAddress.php:158` (`shutPort`) |
| | `app/Models/IpAddress.php:169` (`unshutPort`) |

**Description:** Port control terminology is inconsistent: `shutdown`/`enable`/`bounce` in `SwitchPortController`, but `shutPort`/`unshutPort` in `IpAddress`. The Cisco-derived term `shut`/`no shut` mixes with generic `enable`/`disable`.

**Suggested Fix:** Standardize on one terminology set throughout: `shutdownPort`/`enablePort` or `disablePort`/`enablePort`.

### 4.2 Inconsistent Controller Naming for Settings

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `app/Http/Controllers/Admin/ThemeSettingsController.php` |
| | `app/Http/Controllers/Admin/GeneralSettingsController.php` |
| | `app/Http/Controllers/Admin/DnsDetectionSettingsController.php` |
| | `app/Http/Controllers/Admin/Ipv6DetectionSettingsController.php` |

**Description:** Four settings controllers follow different naming patterns. `Ipv6DetectionSettingsController` does not use `saveSetting()` (uses `IntegrationConfig::setValue` instead), while the other three do. This suggests a missing abstraction -- some settings live in the `settings` table, others in `integration_configs`, but the controller naming does not make this distinction clear.

### 4.3 Inconsistent `$application` vs `$app` Parameter Names

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `app/Providers/AppServiceProvider.php:196, 207, 217, 230` (`$application`) |
| | `app/Providers/AppServiceProvider.php:76, 90, 124, 347, 351` (`$app`) |

**Description:** The `Application` parameter in singleton closures alternates between `$application` and `$app` within the same file.

**Suggested Fix:** Standardize on `$app` (shorter, more conventional in Laravel).

---

## 5. Architecture Issues

### 5.1 Theme Data Loaded in Two Separate Middleware Layers

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Http/Middleware/InjectTheme.php:17-27` |
| | `app/Http/Middleware/HandleInertiaRequests.php:43-48` |

**Description:** Theme settings (mode, accent_hue, accent_chroma, accent_lightness) are loaded from the database twice per request -- once in `InjectTheme` (for Blade views via `View::share`) and once in `HandleInertiaRequests` (for Inertia props). Additionally, `InjectTheme` reads `theme.site_title` while `HandleInertiaRequests` reads the `site_title` setting, creating inconsistency. The `AppServiceProvider::boot()` also loads `site_title` at line 190.

**Suggested Fix:** Consolidate theme loading into a single service or cache layer. Read settings once per request (via a middleware or deferred singleton) and share from there.

### 5.2 Business Logic in Model (IpAddress)

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Models/IpAddress.php:129-192` |

**Description:** The `IpAddress` model contains methods that perform external API calls (`getPortInfo`), dispatch jobs (`shutPort`, `unshutPort`), call external services (`updateUsage`, `getStats`), and resolve container dependencies. Models should primarily represent data and relationships, not orchestrate business operations.

**Suggested Fix:** Move `getPortInfo()`, `shutPort()`, `unshutPort()`, `updateUsage()`, and `getStats()` to `IpAddressActionService` or a new `IpAddressOperationService`. Keep the model focused on data access and relationships.

### 5.3 Mixed Singleton Registration Between register() and boot()

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Providers/AppServiceProvider.php` |

**Description:** Some singletons are registered in `register()` (lines 62-177) and others in `boot()` (lines 196-356). Laravel convention dictates that `register()` is for binding services and `boot()` is for bootstrapping logic. The `NtopNgService`, `BorealisService`, `NetworkInventoryInterface`, `DhcpInterface`, `DnsFilteringInterface`, `NetworkSwitchInterface`, and `MacAddressResolverInterface` are all registered in `boot()`, but they are service bindings that belong in `register()`.

**Suggested Fix:** Move all `$this->app->singleton()` calls to `register()`.

### 5.4 Search Endpoint Lacks Rate Limiting and Input Sanitization

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Http/Controllers/Admin/SearchController.php:21-26` |

**Description:** The `search()` method uses `LIKE` queries with user input directly interpolated into the pattern (`sprintf('%%%s%%', $query)`). While the admin area likely has authentication, there is no input sanitization for SQL `LIKE` special characters (`%`, `_`), no rate limiting, and no maximum query length validation.

**Suggested Fix:** Escape LIKE metacharacters, add validation for max query length, and consider throttle middleware.

---

## 6. Security Concerns

### 6.1 Custom CSS Field Allows Injection Vectors

| Attribute | Detail |
|-----------|--------|
| **Severity** | **High** |
| **Files** | `app/Http/Controllers/Admin/ThemeSettingsController.php:43-47` |

**Description:** The custom CSS validation only checks for `<script` tags (case-insensitive). CSS injection via `expression()`, `url()` with `javascript:` protocol, `@import`, or `behavior:` (IE) is not blocked. While CSS injection is lower risk than XSS, it can enable data exfiltration via attribute selectors and external URLs.

**Suggested Fix:** Consider a CSS sanitizer library, or at minimum block `expression(`, `javascript:`, `@import url`, and `behavior:` patterns. Document the risk if intentionally leaving this permissive.

### 6.2 v-html Usage for SVG Icons

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `resources/js/Components/Admin/Sidebar.vue:128, 159` |

**Description:** The sidebar uses `v-html` to render SVG icons. While the data currently comes from a static local object, `v-html` bypasses Vue's XSS protection. If the icon data source ever becomes dynamic (e.g., from a config file or API), this becomes a direct XSS vector.

**Suggested Fix:** Use inline SVG components or a dedicated icon component system instead of `v-html`.

### 6.3 Missing Request Validation in IpAddressController Actions

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Http/Controllers/Admin/IpAddressController.php:118-129, 131-139, 141-149` |

**Description:** The `port()`, `limit()`, and `internet()` methods accept raw `$request->input()` values with minimal or no validation. The `port()` method uses loose comparison, and `limit()` and `internet()` cast directly to `bool` without validation rules.

**Suggested Fix:** Add explicit validation rules: `$request->validate(['shutdown' => 'required|boolean'])` etc.

---

## 7. Frontend Issues

### 7.1 Overlapping Composables: useAccentColor and useAccentHue

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `resources/js/composables/useAccentColor.js` |
| | `resources/js/composables/useAccentHue.js` |

**Description:** `useAccentHue.js` re-exports `useAccentColor` as `useAccentHue` and adds an `applyAccentHue` wrapper function. This creates a confusing dual API. The `useAccentHue` composable exists as a compatibility shim but adds cognitive overhead for developers choosing between the two.

**Suggested Fix:** Deprecate `useAccentHue.js` and migrate all consumers to `useAccentColor.js`. If `applyAccentHue` (hue-only API) is needed, add it to `useAccentColor.js`.

### 7.2 Duplicated Default Values for Theme Settings

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `resources/js/composables/useAccentColor.js:15-17` (DEFAULT_HUE=55, DEFAULT_CHROMA=0.19, DEFAULT_LIGHTNESS=72) |
| | `resources/js/composables/useTheme.js:12-14` (hardcoded 55, 0.19, 72) |
| | `resources/js/Pages/Admin/Settings/Theme.vue:17-19` (hardcoded 55, 0.19, 72) |
| | `app/Http/Middleware/HandleInertiaRequests.php:45-47` (hardcoded 55, 0.19, 72) |
| | `app/Http/Middleware/InjectTheme.php:20-22` (hardcoded 55, 0.19, 72) |
| | `app/Http/Controllers/Admin/ThemeSettingsController.php:23-25` (hardcoded 55, 0.19, 72) |

**Description:** The default theme values (hue=55, chroma=0.19, lightness=72) are hardcoded in at least 6 locations across PHP and JavaScript. If the defaults ever change, all locations must be updated.

**Suggested Fix:** On the PHP side, define defaults in `config/aperture.php` as `'theme.defaults'`. On the JS side, import from the `useAccentColor.js` constants. The Inertia shared props can pass these defaults from config.

### 7.3 Non-Debounced Resize Listener in Sidebar

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `resources/js/Components/Admin/Sidebar.vue:70-71` |

**Description:** The `resize` event listener calls `checkBreakpoint()` on every resize event without debouncing. This fires 10-50+ times per second during a resize, though the handler is lightweight.

**Suggested Fix:** Debounce the listener or use `matchMedia` with a listener for the breakpoint change, which is more performant.

### 7.4 Direct DOM Access in Composables

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `resources/js/composables/useAccentColor.js:22` (`document.documentElement`) |
| | `resources/js/composables/useTheme.js:18-19` (`document.documentElement`) |
| | `resources/js/Pages/Admin/Switches/Ports/Show.vue:64` (`getComputedStyle(document.documentElement)`) |

**Description:** Multiple composables and components directly access `document.documentElement` to read/write CSS variables. This couples the code to the browser DOM and makes SSR impossible if ever needed.

**Suggested Fix:** This is an acceptable trade-off for a network management SPA. Document the assumption that SSR is not planned. For `getComputedStyle` in Ports/Show.vue, consider reading the value once on mount rather than inside a computed.

### 7.5 Missing aria-label on Accent Color Preset Buttons

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `resources/js/Pages/Admin/Settings/Theme.vue:100-114` |

**Description:** The accent color preset buttons have `title` attributes but lack `aria-label`. Screen readers will announce the button as unlabeled since the visual content is purely a colored circle.

**Suggested Fix:** Add `:aria-label="'Select ' + preset.name + ' accent color'"`.

---

## 8. Testing Concerns

### 8.1 Silent Failure in updateUsage()

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Models/IpAddress.php:181-187` |

**Description:** `updateUsage()` catches `ClientException` and does nothing (`// Do Nothing`). This silently swallows errors, making failures invisible in monitoring and hard to debug. It also only catches `ClientException`, not the parent `GuzzleException`, so server errors (5xx) would still throw.

**Suggested Fix:** At minimum, log the error. Consider catching `Throwable` if all errors should be non-fatal, and add a `Log::warning()` call.

### 8.2 Dynamic Property Access in updateUsage()

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Medium** |
| **Files** | `app/Services/IpAddressActionService.php:127-132` |

**Description:** The `updateUsage()` method accesses stdClass properties using variable property names: `$attr = 'bytes.rcvd'; $ip->received = $stats->rsp->$attr;`. The dotted property name `bytes.rcvd` is used as a PHP property name on stdClass. This is fragile and confusing -- it works because the JSON response literally has keys with dots in them, but it looks like a bug (property chaining).

**Suggested Fix:** Use explicit array access or a helper: `$data = (array) $stats->rsp; $ip->received = $data['bytes.rcvd'];`.

---

## 9. Minor Issues

### 9.1 Unused `$config` Variable

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `app/Http/Controllers/Admin/IpAddressController.php:86` |

**Description:** `$config = null;` is assigned but never updated. It is passed to the Inertia response as `'config' => $config` but always remains `null`.

**Suggested Fix:** Remove the variable and the prop, or implement the intended functionality.

### 9.2 eslint-disable Comment for Unused Variable

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | `resources/js/Pages/Admin/Ips/Show.vue:49` |

**Description:** `// eslint-disable-next-line no-unused-vars` suppresses a warning for the `togglePort(ip)` function parameter. The `ip` parameter is unused because `props.shutdown` is used instead.

**Suggested Fix:** Remove the `ip` parameter: `function togglePort() {`.

### 9.3 Inconsistent use of `declare(strict_types=1)`

| Attribute | Detail |
|-----------|--------|
| **Severity** | **Low** |
| **Files** | Multiple files in `app/` |

**Description:** Some PHP files use `declare(strict_types=1);` (e.g., `IpAddressController.php`, `SwitchManagementController.php`, `OpnSense.php`) while others do not (e.g., `AppServiceProvider.php`, `IpAddress.php`, `User.php`, `CaptivePortalController.php`). This inconsistency means some code benefits from strict type checking and some does not.

**Suggested Fix:** Add `declare(strict_types=1);` to all PHP files in `app/`. This can be done via Rector.

---

## Summary Table

| Severity | Count | Key Areas |
|----------|-------|-----------|
| Critical | 4 | Triplicated saveSetting, AppServiceProvider god method, service locator in model, N+1 in SwitchPortController |
| High | 8 | Duplicate SwitchConfig fallback, duplicate reconciliation logic, theme double-loading, business logic in model, CSS injection, __get() override, SwitchPortController size |
| Medium | 12 | OpnSenseApiService duplication, enrichRange duplication, static methods, loose comparison, search input, missing validation, overlapping composables, duplicated defaults, updateUsage silent failure, mixed boot/register, dynamic property access, TestConnectionController length |
| Low | 9 | Naming inconsistencies, resize debounce, direct DOM access, aria-label, unused variable, eslint-disable, strict_types inconsistency, controller naming, $app/$application |

---

## Recommended Priority

1. **Immediate:** Extract `saveSetting()` to `Setting::set()` (5 min, eliminates 3 copy-paste instances)
2. **Immediate:** Fix N+1 in `SwitchPortController::show()` MAC resolution (batch IP lookups)
3. **Short-term:** Decompose `AppServiceProvider` into focused providers
4. **Short-term:** Move business logic out of `IpAddress` model to service layer
5. **Short-term:** Consolidate theme settings loading path
6. **Medium-term:** Extract generic reconciliation method in `OpnSense`
7. **Medium-term:** Centralize theme default values
8. **Medium-term:** Harden custom CSS validation
