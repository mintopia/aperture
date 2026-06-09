# Plan: Cisco DHCP Integration + Async DHCP Polling
_Locked via grill-with-docs — by Claude + Jess. Terms per codebase conventions. Reviewed by Codex (5 rounds, deadlock — see PLAN-REVIEW-LOG.md)._

## Goal
Add a Cisco IOS DHCP server integration (IPv4 and IPv6) that follows the existing capability/bootstrapper pattern, allowing a designated switch to provide DHCP lease, range, and pool status data. Simultaneously refactor all DHCP integrations (OPNsense, VyOS, Cisco) from live-fetch-on-page-load to async scheduled polling with database-backed storage. Additionally, collect DHCP snooping bindings during normal switch polling as an IP/MAC mapping source, and remove the legacy `CiscoService`.

## Approach

### Phase 1 — Database & Models
1. Create `dhcp_range_records` table: `integration` (string, nullable), `interface`, `type` (string, non-null), `subnet` (string, non-null), `range_from` (string, non-null), `range_to` (string, non-null), `prefix`, `gateway`, `description`, `total_addresses` (decimal(39,0), nullable), `used_addresses` (decimal(39,0), nullable), `utilisation` (decimal(5,4), nullable). Unique constraint on `(integration, type, subnet, range_from, range_to)` with partial index excluding null integration. IPs in canonical text form.
2. Create `DhcpRangeRecord` Eloquent model.
3. Add `integration` column (string, nullable) to `dhcp_leases`. Backfill existing rows with actual active DHCP provider's integration enum value. Keep nullable — queries scope by active integration.
4. Create `dhcp_pool_statuses` table: `integration` (string, non-null), `address_family` (string, non-null — `'ipv4'`/`'ipv6'`), `total` (decimal(39,0)), `used` (decimal(39,0)), `available` (decimal(39,0)), `utilisation` (decimal(5,4)), `synced_at` (datetime). Unique on `(integration, address_family)`. Create `DhcpPoolStatusRecord` model. Cache is read-through; DB authoritative.
5. Create `dhcp_sync_states` table: `integration` (string, non-null), `address_family` (string, non-null), `dataset` (string, non-null — `'leases'`/`'ranges'`/`'pool_status'`), `empty_count` (int, default 0), `last_attempt_at` (datetime, nullable), `last_success_at` (datetime, nullable). Unique on `(integration, address_family, dataset)`. Create `DhcpSyncState` model.
6. Create `dhcp_snooping_observations` table: `switch_config_id` (FK), `vlan` (int, non-null, default 0), `ip` (string), `mac` (string), `interface` (string, nullable), `expires_at` (datetime, nullable), `observed_at` (datetime). Unique on `(switch_config_id, vlan, ip, mac)`. Create `DhcpSnoopingObservation` model.

### Phase 2 — Cisco Integration Scaffolding
7. Add `Cisco = 'cisco'` to `Integration` enum.
8. Cisco integration config in `config/integrations.php`: `switch_id` (select from `SwitchConfig`), `pool_size` (decimal/string, optional), `ipv6_enabled` (bool, default true).
9. Create `CiscoBootstrapper` implementing `IntegrationBootstrapper` — binds `DhcpInterface` when `Capability::Dhcp` active. Constructs `CiscoDhcpService` from selected `SwitchConfig` (ID only; credentials at execution time).
10. Register in `IntegrationServiceProvider::registerCapabilityBindings()`.

### Phase 3 — Cisco DHCP Service
11. Add DHCP parsing methods to `IosOutputParser`:
    - `parseDhcpBindingTable(string $output): array` — `show ip dhcp binding`
    - `parseDhcpv6BindingTable(string $output): array` — `show ipv6 dhcp binding` (IA_NA only; prefix delegation excluded). Returns DUID/IAID as client identifiers; MAC extracted from DUID-LL/DUID-LLT when parseable, otherwise null.
    - `parseDhcpPoolStats(string $output): array` — `show ip dhcp pool`
    - `parseDhcpv6PoolStats(string $output): array` — `show ipv6 dhcp pool`
    - `parseDhcpPoolConfig(string $output): array` — `show running-config | section ip dhcp`
    - `parseDhcpv6PoolConfig(string $output): array` — `show running-config | section ipv6 dhcp pool`
    - `computeEffectiveRanges(array $poolConfig): array` — IPv4: subtracts excluded addresses from networks (handles overlaps, reversed ranges, exclusions outside pools, network/broadcast). IPv6: prefix-based allocation.
    - All parsers reject IOS error output before parsing.
    - All decimal arithmetic uses `bcadd`/`bcsub`/`bcdiv`/`bccomp`.
12. Create `CiscoDhcpService` implementing `DhcpInterface`:
    - Constructor: `SwitchCommandTransportInterface` + `IosOutputParser` + `poolSize` + `bool $ipv6Enabled`.
    - **Bound as transient**. `resetSnapshot()` clears cache.
    - **Single fetch snapshot**: `fetchSnapshot()` → connect → `executeMultiple()` → parse → disconnect in `finally`. IPv4 always runs. IPv6 only if `$ipv6Enabled`; IPv6 failures log warning, do not block IPv4. Tracks per-family fetch success internally.
    - `getLeases()`: returns combined IPv4 + IPv6 (where successful) `DhcpLease` VOs. DHCPv6 leases have `mac` nullable when DUID doesn't contain a MAC.
    - `getRanges()`: effective ranges from both families.
    - `getPoolStatus()`: aggregate across successful families.
    - `getLease(string $ip)`: filters from `getLeases()`.
    - `getFetchStatus(): array` — returns per-family success/failure for sync job to use.
    - Enable-level privileges for running-config; transport handles.

### Phase 4 — Async DHCP Sync Job
13. Create `SyncDhcpData` job:
    - `ShouldBeUnique`. Every minute, `onOneServer()`.
    - Resolves active integration + `DhcpInterface`. Skip if `NullDhcpService`.
    - `resetSnapshot()`, fetch all data. Structured logging (duration, counts, errors).
    - **Per-family reconciliation**: Check `getFetchStatus()` (if available) or infer from results. Only reconcile (delete missing) for address families that fetched successfully. Failed family → skip its deletion, log warning.
    - **Empty-result guard** (per-dataset, per-family via `DhcpSyncState`):
      - `last_attempt_at` always updated.
      - Suspicious: skip deletion, increment `empty_count`.
      - After 3 consecutive: proceed, reset counter.
      - Success: update `last_success_at`, reset counter.
    - **Transactional sync**:
      - Read previous `DhcpPoolStatusRecord` utilisation per family **before** upsert.
      - Resolve/create `IpAddress`/`MacAddress` via `firstOrCreate`. `mac_address_id` nullable for DHCPv6 leases without parseable MAC. Canonical MAC normalisation.
      - Upsert `DhcpLease` by `(integration, ip_address_id)`, delete this integration's missing (scoped to successful families).
      - Upsert `DhcpRangeRecord` by unique key, delete missing (scoped). `used_addresses`/`utilisation` nullable (null for Cisco; populated for OPNsense/VyOS).
      - Upsert `DhcpPoolStatusRecord` by `(integration, address_family)`. BCMath for counts. Clamp: `available = max(0, total - used)`, `utilisation ∈ [0, 1]`. Warn if override < used. Delete pool status rows for families that succeeded but returned no pools.
      - Update `DhcpSyncState` per dataset per family.
    - **After commit**: cache `dhcp_pool_status:{integration}:{family}` (2-min TTL). Fire `DhcpPoolThresholdReached` (includes `address_family` in payload) only on crossing per family.
    - `$timeout`, `$tries`, `$backoff`.
14. Register in scheduler.
15. Artisan `php artisan dhcp:sync --once` for deploy.

### Phase 5 — Refactor Controller to Read from DB
16. `DhcpController::index()`: resolve active integration, query `DhcpRangeRecord` scoped, read pool status per family from cache/DB. Include `last_success_at` from `DhcpSyncState` for freshness.
17. `DhcpController::leases()`: query `DhcpLease` scoped, eager-load `ipAddress`/`macAddress`.
18. Remove `DhcpInterface` injection from controller.
19. Response DTOs/resources. Regression tests.
20. **Rollout safeguard**: No sync data → live fallback gated by `Cache::lock('dhcp_live_fallback', transport_timeout + 10)`. Lock unavailable → stale/empty with staleness indicator.

### Phase 6 — DHCP Snooping in Switch Polling
21. `SupportsDhcpSnooping` interface: `getDhcpSnoopingBindings(): Collection`.
22. `parseDhcpSnoopingTable()` in `IosOutputParser`. Validates output structure.
23. `CiscoSwitchAdapter` implements `SupportsDhcpSnooping`.
24. `PortSyncService`: `instanceof` check. Canonicalise IP/MAC/VLAN/interface. Upsert `DhcpSnoopingObservation` by `(switch_config_id, vlan, ip, mac)`. Delete this switch's missing (respecting expiry).
25. Create `DhcpSnoopingResolver` — queries `dhcp_snooping_observations` table, returns IP/MAC mappings. Wire into existing IP/MAC resolution pipeline for enrichment.

### Phase 7 — Cleanup
26. Grep for `CiscoService` references.
27. Remove `CiscoService` + tests.
28. Clean remaining references.

## Key decisions & tradeoffs
- **Single designated switch** — preserves single-binding architecture.
- **`SwitchCommandTransportInterface`** — consistent with existing adapter.
- **Excluded-address parsing** — complex but accurate. IPv6: prefix-based.
- **All integrations async** — 1-minute DB-backed polling.
- **Provider-scoped upsert + delete** — prevents cross-provider corruption.
- **Per-address-family tracking** — separate pool status, sync state, thresholds, reconciliation.
- **IPv6 independent** — failure doesn't block IPv4; configurable.
- **`decimal(39,0)` + BCMath** — handles IPv6 2^128 address space correctly.
- **DHCPv6 IA_NA only** — prefix delegation excluded (different domain).
- **DHCPv6 MAC nullable** — DUID/IAID as identifier; MAC from DUID-LL/LLT when available.
- **`DhcpInterface` unchanged** — per-family split is sync-job concern.
- **Snooping in separate table** — per-switch+VLAN, with resolver for IP/MAC enrichment.
- **Transient service binding** — no stale snapshots.
- **Empty-result guard** — per-dataset, per-family, 3-cycle confirmation.
- **Threshold crossing with pre-read + afterCommit** — per family, includes family in event payload.
- **Integration column nullable** — avoids migration issues, queries scope.
- **Pool override clamping** — available ≥ 0, utilisation ∈ [0, 1].

## Risks / open questions
- SSH for 5+ commands — `executeMultiple()` batching.
- IOS version differences — fixture parser tests.
- Deploy: Artisan sync + live fallback until first success.

## Out of scope
- DHCP relay configuration
- DHCPv6 prefix delegation
- Real-time event streaming (SNMP/syslog)
- VRF-scoped address partitioning
- Per-switch DHCP views
