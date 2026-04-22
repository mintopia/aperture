# IP Policy Management Design

## Goal

Unify internet access, rate limiting, and DNS filtering into a consistent, observer-driven IP policy system where the local database is the source of truth and changes to user or IP properties automatically propagate to external backends (OPNsense firewall, PiHole).

## Architecture

Three policy concerns — internet access, rate limiting, DNS filtering — follow the same pattern: a boolean field on both `users` and `ip_addresses`, Eloquent observers that detect changes and dispatch jobs, and backend services that apply the desired state to external systems. A reconciliation command per concern ensures the external system can be re-synced from local state at any time.

## Database Changes

### `users` table

| Column | Change | Type | Default |
|--------|--------|------|---------|
| `blocked` | Rename to `internet_blocked` | boolean | `false` |
| `internet_enabled` | Add | boolean | `false` |
| `rate_limit_enabled` | Add | boolean | `false` |
| `dns_filtering_enabled` | Existing — change default | boolean | `false` (was `true`) |

### `ip_addresses` table

| Column | Change | Type | Default |
|--------|--------|------|---------|
| `allowed` | Rename to `internet_enabled` | boolean | `false` |
| `limited` | Rename to `rate_limit_enabled` | boolean | `false` |
| `dns_filtering_enabled` | Add | boolean | `false` |

### Settings

- `dns_filtering.default_enabled` (boolean) — admin-configurable default for whether new users have DNS filtering opted in or out.
- `portal.blocked_message` (string) — configurable message shown to users with `internet_blocked = true`.

## Interface & Service Changes

### `DnsBlockingInterface` → `DnsFilteringInterface`

Renamed interface with corrected semantics:

- `enableForIp(string $ipAddress): void` — add IP to the filtered group in PiHole
- `disableForIp(string $ipAddress): void` — remove IP from the filtered group
- `isEnabledForIp(string $ipAddress): bool` — check if IP is in the filtered group
- `reconcile(bool $dryRun = false): ReconcileResult` — bulk sync: fetch PiHole state, fetch DB state, diff, apply changeset

### `PiHoleService` rewrite

Implements `DnsFilteringInterface`. Semantic flip from current implementation:

- Constructor takes `filteredGroupId` (was `noblockGroupId`)
- `enableForIp`: find or create client, ensure `filteredGroupId` is in its groups array
- `disableForIp`: find client, remove `filteredGroupId` from its groups array
- `isEnabledForIp`: check if client exists and has `filteredGroupId` in groups
- Config key: `noblock_group_id` → `filtered_group_id`
- Integration UI label: "Filtered Group"

### `FirewallBackendInterface` additions

Existing methods unchanged. New bulk methods:

- `reconcileInternet(bool $dryRun = false): ReconcileResult` — fetch firewall state, fetch DB state, diff, apply
- `reconcileRateLimits(bool $dryRun = false): ReconcileResult` — same pattern for rate limits

### `ReconcileResult` value object

```php
class ReconcileResult
{
    public function __construct(
        public readonly array $added,
        public readonly array $removed,
        public readonly array $unchanged,
        public readonly array $errors,
    ) {}
}
```

### `IpAddressActionService` renames

| Old method | New method |
|-----------|-----------|
| `allow()` | `enableInternet()` |
| `deny()` | `disableInternet()` |
| `limit()` | `enableRateLimit()` |
| `unlimit()` | `disableRateLimit()` |

### `IpAddress` model method removal

The current `IpAddress` model has public methods (`allow()`, `deny()`, `limit()`, `unlimit()`) that accept a `bool $queue` parameter and either call `IpAddressActionService` directly or dispatch an `IpAddressAction` job. With observers handling job dispatch automatically when fields change, these methods are removed. Callers should set the boolean field directly (e.g. `$ip->internet_enabled = true; $ip->save();`) and let the observer dispatch the appropriate job.

### New `IpPolicyService`

- `applyUserPolicy(User $user, IpAddress $ip): void` — sets IP fields to match user's desired state. If `internet_blocked` is true, `internet_enabled` on the IP is forced to `false`. Only sets fields that differ from current values. Only calls `$ip->save()` if the model is dirty.
- `applyDefaults(IpAddress $ip): void` — sets all three fields to `false`. Used when disassociating an IP from a user.

## Observers & Jobs

### `UserObserver::updated(User $user)`

- Checks if any of `internet_enabled`, `rate_limit_enabled`, `dns_filtering_enabled`, `internet_blocked` were changed (`wasChanged()`)
- If so, loads the user's associated IPs via `$user->ips()->with('ip')` and dispatches `SyncUserPolicyJob` for each IP

### `IpAddressObserver::updated(IpAddress $ip)`

Each check is independent — multiple jobs can be dispatched if multiple fields changed in one save:

- `internet_enabled` changed → dispatches `SyncInternetAccessJob($ip->address, $ip->internet_enabled)`
- `rate_limit_enabled` changed → dispatches `SyncRateLimitJob($ip->address, $ip->rate_limit_enabled)`
- `dns_filtering_enabled` changed → dispatches `SyncDnsFilteringJob($ip->address, $ip->dns_filtering_enabled)`

### `UserIpAddressObserver::deleted(UserIpAddress $userIp)`

- Loads the IP, calls `IpPolicyService::applyDefaults($ip)` to revert to default state (all disabled)
- The IP observer handles dispatching backend jobs

### Jobs

| Job | Purpose |
|-----|---------|
| `SyncUserPolicyJob` | Receives user ID + IP address ID. Calls `IpPolicyService::applyUserPolicy()`. Bridge from user change to IP change. |
| `SyncInternetAccessJob` | Calls `FirewallBackendInterface::updateIp()` or `removeIp()` based on enabled flag. |
| `SyncRateLimitJob` | Calls `FirewallBackendInterface::limitIp()` or `unlimitIp()` based on enabled flag. |
| `SyncDnsFilteringJob` | Calls `DnsFilteringInterface::enableForIp()` or `disableForIp()` based on enabled flag. Already exists — update to use renamed interface. |

### Loop prevention

- `IpPolicyService::applyUserPolicy()` only sets fields that differ from current values, only calls `$ip->save()` if dirty
- `IpAddressObserver` checks `$ip->wasChanged('field_name')` — only fires for fields that actually changed
- `SyncUserPolicyJob` only modifies the IP, never the user — no user observer loop

## IP Association & Disassociation

### Association (`User::addIp()`)

After associating the IP (creating/updating `UserIpAddress`), calls `IpPolicyService::applyUserPolicy($this, $ip)`. This sets the IP's fields to match the user's desired state. If the IP already matches, nothing happens — no save, no jobs.

### Disassociation

When a `UserIpAddress` is deleted, `UserIpAddressObserver::deleted()` loads the IP and calls `IpPolicyService::applyDefaults($ip)` which sets all three fields to `false`. The IP observer dispatches backend jobs for any fields that changed.

### `DashboardController::index()` simplification

Currently does `$ip->allow(true)` and manually dispatches `SyncDnsFilteringJob`. With this design, it just calls `$user->addIp($clientIp)` — `addIp` applies user policy, observer handles the rest. If `internet_blocked` is true, `blockContext` includes `internetBlocked: true` and `blockedMessage` from settings.

## Reconciliation Commands

Three separate commands, each thin wrappers around the bulk interface methods:

| Command | Calls |
|---------|-------|
| `aperture:reconcile-internet` | `$firewall->reconcileInternet($dryRun)` |
| `aperture:reconcile-rate-limits` | `$firewall->reconcileRateLimits($dryRun)` |
| `aperture:reconcile-dns-filtering` | `$dnsFiltering->reconcile($dryRun)` |

Each implementation owns the full flow: fetch backend state → fetch DB desired state → diff → apply changeset (or report if `--dry-run`). Results reported via `ReconcileResult`.

## Portal Blocked User Experience

When `internet_blocked` is true:

- `DashboardController` renders the portal with `blockContext.internetBlocked = true` and `blockContext.blockedMessage` from the `portal.blocked_message` setting
- The dashboard Vue component shows a prominent banner/notice displaying the configured message
- The user can still see the dashboard but their state reflects no internet access
- `IpPolicyService::applyUserPolicy()` forces `internet_enabled = false` on the IP when user is blocked, regardless of the user's `internet_enabled` setting

## Codebase Renames

### Database columns

- `users.blocked` → `users.internet_blocked`
- `ip_addresses.allowed` → `ip_addresses.internet_enabled`
- `ip_addresses.limited` → `ip_addresses.rate_limit_enabled`

### Interfaces

- `DnsBlockingInterface` → `DnsFilteringInterface`

### Model properties & methods

- `IpAddress::$allowed` → `$internet_enabled`
- `IpAddress::$limited` → `$rate_limit_enabled`
- `IpAddress::allow()` → `enableInternet()`
- `IpAddress::deny()` → `disableInternet()`
- `IpAddress::limit()` → `enableRateLimit()`
- `IpAddress::unlimit()` → `disableRateLimit()`
- `User::$blocked` → `$internet_blocked`

### Jobs

- `SyncDnsFilteringJob` — update to use `DnsFilteringInterface`
- `IpAddressAction` job — update to use new method names

### Config

- `pihole.noblock_group_id` → `pihole.filtered_group_id`
- Integration UI label: "Filtered Group"

### Frontend

- `blockContext.ipAllowed` → `blockContext.internetEnabled`
- `blockContext.internetBlocked` and `blockContext.blockedMessage` added
- `ConnectionStripBlock` status field references updated
- Admin views referencing `blocked` users updated

### Controllers

- `DashboardController` — simplified, delegates to `addIp` + observers
- `DnsFilterController` — update to use new interface/field names
- `PiHoleController` — consolidated into `DnsFilterController` or removed
