# Capability Rationalisation Design

**Date:** 2026-04-25
**Status:** Approved

## Overview

Rationalise the capability system so that every capability has a dedicated interface, a null provider, and follows the Liskov substitution principle. Remove ntopng. Gate all capability bindings through `CapabilityAssignment::isActiveProvider()`.

## Principles

1. Every capability has a null provider.
2. Liskov substitution principle applies — same interface, any provider can be substituted.
3. Service container bindings in `IntegrationServiceProvider` gate on `CapabilityAssignment` and bind the appropriate implementation.

## Capabilities

### `captive-portal`

Allows/denies IP addresses access to the internet.

**Interface:** `CaptivePortalInterface`

```php
addIp(string $ip, string $description): void
removeIp(string $ip): void
addAllowedHostnames(array $hostnames): void
reconcile(bool $dryRun = false): ReconcileResult
```

**Implementations:** `OpnSenseCaptivePortal`, `NullCaptivePortal`

### `rate-limiting`

Allows an IP address to be rate-limited (add/remove IP to alias).

**Interface:** `RateLimitingInterface`

```php
limitIp(string $ip): void
unlimitIp(string $ip): void
reconcile(bool $dryRun = false): ReconcileResult
```

**Implementations:** `OpnSenseRateLimiter`, `NullRateLimiter`

### `dhcp`

Provides list of DHCP ranges and leases. Enables IP-to-MAC and IP-to-hostname resolution.

**Interface:** `DhcpInterface` (unchanged)

```php
getPoolStatus(): DhcpPoolStatus
getLeases(): Collection<int, DhcpLease>
getLease(string $ipAddress): ?DhcpLease
getRanges(): Collection<int, DhcpRange>
```

**Implementations:** `OpnSenseDhcpService` (Kea, ISC, dnsmasq variants), `NullDhcpService`

### `dns-filtering`

Provides opt-in DNS filtering/ad blocking for an IP address.

**Interface:** `DnsFilteringInterface` (unchanged)

```php
isEnabledForIp(string $ipAddress): bool
enableForIp(string $ipAddress): void
disableForIp(string $ipAddress): void
reconcile(bool $dryRun = false): ReconcileResult
```

**Implementations:** `PiHoleService`, `NullDnsFiltering`

### `ip-bandwidth`

Provides bandwidth statistics for an IP address. Up and down counters in bytes, time-series rates, and totals over a range. Generally for internet traffic.

**Interface:** `IpBandwidthInterface`

```php
getIpBandwidth(string|array $ipAddress, string $range = '24h'): IpBandwidthResult
getTotalBandwidth(string $range = '24h'): IpBandwidthResult
getTopTalkers(int $limit = 10, string $range = '1m'): Collection<int, TopTalker>
```

**Implementations:** `PrometheusIpBandwidth`, `NullIpBandwidth`

**Value object:** `IpBandwidthResult` (renamed from `UserBandwidth`) — contains `timestamps`, `download`, `upload` (time-series rate arrays), `received`, `sent` (total bytes over range).

### `port-bandwidth`

Provides up/down byte counters for a switch port.

**Interface:** `PortBandwidthInterface`

```php
getPortBandwidth(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
isAvailable(): bool
```

**Implementations:** `PrometheusPortBandwidth`, `NullPortBandwidth`

**Value object:** `PortTimeSeries` — typed object with `in` and `out` arrays of `{timestamp, value}` pairs.

### `port-errors`

Provides error counters for a switch port.

**Interface:** `PortErrorsInterface`

```php
getPortErrors(string $device, string $ifName, float $start, float $end, ?int $step = null): PortTimeSeries
isAvailable(): bool
```

**Implementations:** `PrometheusPortErrors`, `NullPortErrors`

### `ip-mac`

Provides IP address to MAC address resolution. Additional source of data to DHCP leases. Returns both IPv4 and IPv6 entries.

**Interface:** `IpMacResolverInterface`

```php
getArpTable(): Collection<int, ArpEntry>
```

**Implementations:** `LibreNmsIpMacResolver` (merges ARP + IPv6 neighbors), `NullIpMacResolver`

### `port-mac`

Provides MAC address to switch port mappings (forwarding database / FDB).

**Interface:** `PortMacInterface`

```php
getForwardingDatabase(): Collection<int, ForwardingEntry>
```

**Implementations:** `LibreNmsPortMac`, `NullPortMac`

Note: Direct switch queries (via `SwitchServiceFactory` / `NetworkSwitchInterface`) are a separate, non-capability mechanism for port-MAC data.

## SSO / Authentication

Outside the capability system entirely. `AuthProviderInterface` + `BorealisDeviceFlowService` stays bound in `AppServiceProvider`. Borealis is one OAuth2 device-flow provider; any provider supporting device flow (e.g. Authentik) could be substituted. Configuration remains in admin but as a separate "Authentication" section.

## Service Provider & Binding

### `IntegrationServiceProvider`

Every capability binding follows the same pattern:

1. Check `CapabilityAssignment::isActiveProvider($integration, $capability)`
2. If assigned: build real implementation from `IntegrationConfig` DB config
3. If not: return the null provider

Currently only `user-bandwidth` and `host-stats` are gated. After rationalisation, all 9 capabilities are gated.

### Shared Clients

Implementations sharing an underlying client use composition:

- **`OpnSenseClient`** — extracted shared Guzzle client + config (endpoint, credentials, verify_ssl). Injected into `OpnSenseCaptivePortal`, `OpnSenseRateLimiter`, `OpnSenseDhcpService`. Registered as a singleton.
- **`PrometheusService`** — internal raw PromQL client. Injected into `PrometheusIpBandwidth`, `PrometheusPortBandwidth`, `PrometheusPortErrors`. Registered as a singleton. No longer exposed via a public interface.
- **`LibreNmsService`** — internal HTTP client. Injected into `LibreNmsIpMacResolver`, `LibreNmsPortMac`. Stays available directly for non-capability features (device list, port detail).

## Consumer Changes

### `IpAddressActionService`

- `FirewallBackendInterface` → `CaptivePortalInterface` + `RateLimitingInterface`
- `HostStatsProviderInterface` → removed (`updateUsage()` method deleted)
- `NetworkInventoryInterface` → removed

### Controllers

| Controller | Current | New |
|---|---|---|
| `HomeController` | `DhcpInterface`, `TrafficMonitorInterface` | `DhcpInterface`, `IpBandwidthInterface` |
| `UserController` | `TrafficMonitorInterface` | `IpBandwidthInterface` (also into `show()` for per-device bandwidth) |
| `IpAddressController` | `MetricsProviderInterface`, `TrafficMonitorInterface` | `IpBandwidthInterface`, `PortBandwidthInterface`, `PortErrorsInterface` |
| `SwitchPortController` | `MetricsProviderInterface` | `PortBandwidthInterface`, `PortErrorsInterface` |
| `StatsController` | `TrafficMonitorInterface` | `IpBandwidthInterface` |

### Commands

| Command | Current | New |
|---|---|---|
| `ReconcileInternetCommand` | `FirewallBackendInterface` | `CaptivePortalInterface` |
| `ReconcileRateLimitsCommand` | `FirewallBackendInterface` | `RateLimitingInterface` |
| `SyncUserBandwidthCommand` | `TrafficMonitorInterface` | `IpBandwidthInterface` |
| `SyncBandwidthCommand` | — | **Deleted** |

### Jobs

| Job | Current | New |
|---|---|---|
| `ScanNetworkDevices` | `DhcpInterface`, `NetworkInventoryInterface` | `DhcpInterface`, `IpMacResolverInterface`, `PortMacInterface` |

### `MacAddressResolver`

`NetworkInventoryInterface` → `IpMacResolverInterface`. Same logic — tries DHCP first, then ARP.

### Network Devices (User Show Page)

`buildNetworkDevices()` currently reads `$ip->received`/`$ip->sent` (always 0 because host-stats was null). After rationalisation, `UserController::show()` injects `IpBandwidthInterface` and calls `getIpBandwidth($ip->address, '7d')` per device IP for real bandwidth data.

## Database Changes

### `capability_assignments` Migration

Remove stale: `authentication`, `sso`, `user-info`, `aggregate-stats`, `device-metrics`, `firewall`, `host-stats`.

Rename: `user-bandwidth` → `ip-bandwidth`.

Add new: `ip-mac` (librenms), `port-mac` (librenms).

Final state:

| capability | integration |
|---|---|
| `captive-portal` | `opnsense` |
| `rate-limiting` | `opnsense` |
| `dhcp` | `opnsense` |
| `dns-filtering` | `pihole` |
| `ip-bandwidth` | `prometheus` |
| `port-bandwidth` | `prometheus` |
| `port-errors` | `prometheus` |
| `ip-mac` | `librenms` |
| `port-mac` | `librenms` |

### `ip_addresses` Table

Drop `received` and `sent` columns — nothing writes to them after `SyncBandwidthCommand` is removed.

### Scheduler

Remove `aperture:sync-bandwidth` from schedule. Keep `aperture:sync-user-bandwidth` and `aperture:expire-sessions`.

## Audit Logging

Audit logging is done at the call site (controllers, commands, jobs) — not in capability implementations. The caller has the context (which user, which admin, which process).

### Existing Coverage (unchanged)

- `mac.created`, `ip.created`, `ip_mac.linked`, `oui.auto_allowed` — in `ScanNetworkDevices`
- `mac.user_assigned`, `ip.user_cascaded` — in `User` model

### New Audit Points

| Action | Location | Audit Key |
|---|---|---|
| IP internet toggled | `IpAddressController::toggleInternet()` | `ip.internet_toggled` |
| IP internet toggled (bulk) | `UserController::toggleInternet()` | `ip.internet_toggled` |
| IP rate limit toggled | `IpAddressController::toggleRateLimit()` | `ip.rate_limit_toggled` |
| IP rate limit toggled (bulk) | `UserController::toggleRateLimit()` | `ip.rate_limit_toggled` |
| User blocked/unblocked | `UserController::toggleBlock()` | `user.block_toggled` |
| Session expired | `ExpireSessionsCommand` | `ip.session_expired` |
| Reconcile internet | `ReconcileInternetCommand` | `captive_portal.reconciled` |
| Reconcile rate limits | `ReconcileRateLimitsCommand` | `rate_limit.reconciled` |
| Reconcile DNS filtering | `ReconcileDnsFilteringCommand` | `dns_filtering.reconciled` |
| Switch port MAC linked | `ScanNetworkDevices::linkSwitchPortMacs()` | `port_mac.linked` |

## File Structure

```
app/Services/
├── Interfaces/
│   ├── CaptivePortalInterface.php
│   ├── RateLimitingInterface.php
│   ├── DhcpInterface.php
│   ├── DnsFilteringInterface.php
│   ├── IpBandwidthInterface.php
│   ├── PortBandwidthInterface.php
│   ├── PortErrorsInterface.php
│   ├── IpMacResolverInterface.php
│   ├── PortMacInterface.php
│   ├── AuthProviderInterface.php           (outside capabilities)
│   ├── MacAddressResolverInterface.php     (internal)
│   └── NetworkSwitchInterface.php          (internal)
│
├── Null/
│   ├── NullCaptivePortal.php
│   ├── NullRateLimiter.php
│   ├── NullDhcpService.php
│   ├── NullDnsFiltering.php
│   ├── NullIpBandwidth.php
│   ├── NullPortBandwidth.php
│   ├── NullPortErrors.php
│   ├── NullIpMacResolver.php
│   └── NullPortMac.php
│
├── OpnSense/
│   ├── OpnSenseClient.php
│   ├── OpnSenseCaptivePortal.php
│   ├── OpnSenseRateLimiter.php
│   └── OpnSenseDhcpService.php
│
├── Prometheus/
│   ├── PrometheusService.php               (internal PromQL client)
│   ├── PrometheusIpBandwidth.php
│   ├── PrometheusPortBandwidth.php
│   └── PrometheusPortErrors.php
│
├── LibreNms/
│   ├── LibreNmsService.php                 (internal — device list, port detail)
│   ├── LibreNmsIpMacResolver.php
│   └── LibreNmsPortMac.php
│
├── PiHole/
│   └── PiHoleService.php
│
���── Auth/
│   └── BorealisDeviceFlowService.php       (outside capabilities)
│
└── ValueObjects/
    ├── IpBandwidthResult.php               (renamed from UserBandwidth)
    ├── PortTimeSeries.php                  (new)
    ├── TopTalker.php
    ├── ReconcileResult.php
    ├── ArpEntry.php
    ├── ForwardingEntry.php
    ├── DhcpLease.php
    ├── DhcpRange.php
    └── DhcpPoolStatus.php
```

## Deletions

| File | Reason |
|---|---|
| `Interfaces/FirewallBackendInterface.php` | Split into CaptivePortal + RateLimiting |
| `Interfaces/TrafficMonitorInterface.php` | Replaced by IpBandwidthInterface |
| `Interfaces/HostStatsProviderInterface.php` | ntopng removed |
| `Interfaces/MetricsProviderInterface.php` | Split into PortBandwidth + PortErrors |
| `Interfaces/NetworkInventoryInterface.php` | Replaced by IpMacResolver + PortMac |
| `Firewalls/OpnSense.php` | Split into OpnSenseCaptivePortal + OpnSenseRateLimiter |
| `NtopNgService.php` | ntopng removed |
| `Null/NullHostStatsProvider.php` | ntopng removed |
| `Null/NullTrafficMonitor.php` | Replaced by NullIpBandwidth |
| `Null/NullMetricsProvider.php` | Replaced by NullPortBandwidth + NullPortErrors |
| `Null/NullNetworkInventoryService.php` | Replaced by NullIpMacResolver + NullPortMac |
| `Prometheus/PrometheusTrafficMonitor.php` | Replaced by PrometheusIpBandwidth |
| `CachedNetworkInventoryService.php` | NetworkInventoryInterface removed |
| `ValueObjects/AggregateStats.php` | Dead code removed |
| `ValueObjects/HostBytes.php` | ntopng removed |
| `Console/Commands/SyncBandwidthCommand.php` | host-stats path removed |

Plus all corresponding test files.

## Risks & Mitigations

1. **Reconcile commands are safety-critical** — splitting `FirewallBackendInterface` means reconcile logic splits too. Thorough testing with comprehensive mocks required.
2. **Capability migration ordering** — migration renaming capabilities must run before new code deploys, otherwise bindings resolve to null providers temporarily.
3. **Scheduler restart** — after removing `aperture:sync-bandwidth`, the scheduler process needs restarting.
4. **Value object rename** — `UserBandwidth` → `IpBandwidthResult` touches controllers, commands, and tests. Frontend unaffected (JSON keys unchanged).

## Out of Scope

- Top talkers widget on admin dashboard (new feature)
- Borealis → generic OAuth2 device flow provider rework
- Admin UI for managing capability assignments
- Caching layer for `IpMacResolverInterface`
