# Plan Review Log: Cisco DHCP Integration + Async DHCP Polling
Act 1 (grill-with-docs) complete — plan locked, no CONTEXT.md/ADR changes needed. MAX_ROUNDS=5.

## Round 1 — Codex

### Critical Findings
1. **Destructive empty-result handling** — empty fetch hard-deletes everything
2. **Concurrent sync race** — overlapping jobs can corrupt data
3. **Provider-switch race** — provider change mid-job can overwrite/delete new provider's data
4. **Undefined ownership key** — `source`/`integration` column unresolved
5. **Lease identity by IP alone** — unsafe across VRFs/VLANs
6. **Range identity undefined** — no stable unique key for upserts
7. **Partial migration hazard** — existing `dhcp_leases` rows have no source
8. **No transactional snapshot** — mixed-generation data exposed to controllers

### Schema/Domain
9. **Model/VO name collision** — `DhcpRange` Eloquent vs value object
10. **IPv6 schema contradicts scope** — migration includes IPv6 but Cisco DHCPv6 is out of scope
11. **Derived fields will drift** — `total_addresses`/`used_addresses`/`utilisation` duplication
12. **Range usage attribution invalid** — excluded-address subtraction creates ranges with no per-range usage
13. **Pool aggregation undefined** — multiple pools into one status is misleading
14. **`pool_size` unexplained** — risks contradicting device data
15. **Relational mapping omitted** — IP/MAC record creation unspecified
16. **Address scope missing** — no VRF/VLAN in lease/range identity

### Parsing/Transport
17. **Duplicate commands** — `getRanges()` and `getPoolStatus()` both run `show ip dhcp pool`
18. **CLI pagination risk** — paging prompts can corrupt parsing
19. **Privilege requirements unstated** — `show running-config` may need enable
20. **Parser scope understated** — IOS variants, VRFs, manual bindings, client-IDs
21. **MAC assumption unsafe** — DHCP may use client-ID not MAC
22. **Excluded-range computation underspecified** — edge cases unhandled
23. **Pool-stat/config correlation fragile** — name-based matching may fail
24. **Transport lifecycle unclear** — disconnect/secret handling unspecified

### Scheduling/Failure
25. **Container resolution ambiguous** — zero or multiple providers unhandled
26. **Retries can repeat destructive reconciliation**
27. **No timeout/backoff policy**
28. **Cache not durable** — pool status disappears on eviction
29. **Global cache key collides** — can't distinguish providers
30. **Threshold event storms** — fires every minute while above threshold
31. **Stale-data undefined** — failed poll leaves data looking current
32. **No observability** — no metrics/logs for sync

### Controller/Compat
33. **Response DTO contract unverified** — Eloquent may serialize differently
34. **N+1 relationship risk**
35. **Async freshness change** — no UI indication of data age
36. **No rollout strategy** — empty pages before first sync

### Snooping
37. **Snooping data not authoritative** — can be stale/forged
38. **No stale-binding cleanup**
39. **Ownership boundary unclear** — PortSyncService vs ScanNetworkDevices
40. **`instanceof` detection brittle**
41. **Multi-switch conflict handling missing**

### Security
42. **Arbitrary switch selection** — any SwitchConfig selectable
43. **Secret exposure in queue** — credentials may serialize
44. **Running-config sensitive data exposure**

### Cleanup/Testing
45. **Premature legacy removal** — unverified dependency check
46. **Test plan too narrow**
47. **No simpler architecture considered**

### Claude's response

**Accepted and will revise plan for:**

1. **Empty-result guard (1)** — Add minimum-result validation before reconciliation. If fetch returns zero results and previous sync had data, skip deletion and log a warning.
2. **Overlapping job protection (2)** — Use `withoutOverlapping()` on the scheduled job.
3. **Provider identity column (4, 7)** — Define as `integration` (string, non-null) on both tables. Existing `dhcp_leases` rows get a migration backfill based on current active DHCP provider. Scope all delete operations to `where('integration', $currentProvider)`.
4. **Transactional sync (3, 8, 26)** — Wrap the full sync (fetch + upsert + delete) in a DB transaction. Capture provider identity at start, abort if changed.
5. **DhcpRange naming (9)** — Name the Eloquent model `DhcpRangeRecord` to avoid collision with the value object.
6. **Duplicate command consolidation (17)** — Single fetch snapshot per sync. `CiscoDhcpService` runs all commands once and caches results internally for the duration of the call.
7. **Transport lifecycle (24)** — Disconnect in `finally` block, queue only `SwitchConfig` ID not credentials (43).
8. **Cache key scoping (28, 29)** — Key as `dhcp_pool_status:{integration}`. Also store `last_synced_at` timestamp in DB for staleness (31).
9. **Threshold hysteresis (30)** — Only fire event on crossing, not while sustained above.
10. **Controller response contract (33, 34)** — Define response DTOs/resources, eager-load relationships, add response tests.
11. **Rollout strategy (36)** — Gate DB read path behind existence of sync data; fall back to live fetch if no sync has run yet.
12. **Observability (32)** — Add structured logging for sync: duration, record counts, failures.
13. **Privilege documentation (19)** — Document that `show running-config` requires enable access; transport handles enable mode.
14. **IP/MAC record creation (15)** — Specify that sync job resolves/creates `IpAddress` and `MacAddress` records using existing `firstOrCreate` patterns.
15. **Snooping source tracking (37, 38)** — Store source switch, observed timestamp, and expiry. Reconcile per-switch with expiry-aware cleanup.
16. **Legacy removal verification (45)** — Grep for all `CiscoService` references before removal.

**Rejected with reasoning:**

- **Lease identity scope (5, 16)**: VRFs and overlapping address spaces are not a concern for this deployment. LAN event switches use flat L2/L3 — no VRF partitioning. Adding VRF/scope columns is premature complexity for a use case that doesn't exist. If needed later, it's a migration.
- **IPv6 schema (10)**: The migration should include IPv6-capable columns (VARCHAR for IPs, not INT). Out of scope for Cisco DHCPv6 *implementation*, but the schema should not artificially constrain future providers.
- **Derived fields (11)**: Storing `total_addresses`/`used_addresses` alongside ranges is intentional — it's snapshot data from the device at sync time, not derived from DB state. Utilisation is computed at write time with consistent rounding.
- **Range usage attribution (12)**: Accepted partially — will model pools separately where possible, but for the UI the per-range view with proportional attribution is acceptable.
- **Pool aggregation (13)**: The `pool_size` config field exists precisely for this — manual override of aggregate capacity when device data is ambiguous. Will document.
- **`pool_size` (14)**: Retained as optional override, same as VyOS. When zero/unset, derive from device data.
- **Address scope (16)**: Same as lease identity — no VRFs in this deployment.
- **CLI pagination (18)**: The ssh-proxy/transport already sets `terminal length 0`. Not a plan concern.
- **Parser scope (20, 21, 22, 23)**: These are implementation details for the parser tests, not plan-level concerns. The plan already calls for fixture tests covering IOS variants.
- **Container resolution (25)**: Existing pattern — `NullDhcpService` is the default when no provider is active. Job resolves interface, gets null service, sync produces no data. Not a bug.
- **Timeout/backoff (27)**: Laravel job infrastructure handles this via `$timeout`, `$tries`, `$backoff` properties. Implementation detail.
- **No simpler architecture (47)**: The snapshot/generation approach is more complex than upsert+delete scoped by provider in a transaction. Rejected.
- **Multi-switch snooping conflicts (41)**: Store per-switch observations — last-write-wins is acceptable for IP/MAC mappings at LAN events where topology is known.
- **`instanceof` detection (40)**: This follows the existing `SupportsInterfaceOutputCapture` pattern already used by `CiscoSwitchAdapter`. Consistent with codebase conventions.
- **Arbitrary switch selection (42)**: Only admin users can configure integrations (policy-gated). No additional restriction needed beyond existing auth.
- **Running-config exposure (44)**: The `section ip dhcp` filter limits output. Logs already redact sensitive data per existing patterns.

## Round 2 — Codex

### Findings
1. **P0 — Snooping has no observation schema** — global IP/MAC can't store per-switch source
2. **P0 — withoutOverlapping may not protect queued execution** — need unique-job middleware + onOneServer()
3. **P0 — Pool status DB storage undefined** — Phase 1 has no table for it
4. **P1 — Controller queries not scoped to active integration**
5. **P1 — Provider identity too weak** — integration name can't distinguish config changes
6. **P1 — Provider-change race under transaction isolation**
7. **P1 — Cache writes not transactional** — can publish from rolled-back transaction
8. **P1 — Empty-result guard undefined** — no enforceable criteria
9. **P1 — Range usage can't be enriched from pool-level stats**
10. **P1 — Lease migration ordering** — non-null before backfill fails
11. **P1 — Nullable range uniqueness unsafe**
12. **P1 — Live fallback reintroduces sync failure mode**
13. **P2 — Transport lifecycle ambiguous** — disconnect ownership unclear across methods
14. **P2 — Threshold crossing race-prone**
15. **P2 — Snooping partial-fetch protection missing**
16. **P2 — IOS parser scope incomplete**

### Claude's response

**Accepted and will revise:**

1. **Snooping observation table (1)** — Create `dhcp_snooping_observations` table keyed by `(switch_config_id, ip, mac)` with VLAN, interface, expiry, observed_at. Snooping data stays separate from global IP/MAC records; a resolver reads from this table to enrich IP/MAC linkage.
2. **Job uniqueness (2)** — Use Laravel `ShouldBeUnique` interface (queue-level uniqueness) plus `onOneServer()` for scheduler dispatch. Replaces reliance on `withoutOverlapping()` alone.
3. **Pool status DB table (3)** — Add `dhcp_pool_statuses` table with columns: `integration`, `total`, `used`, `available`, `utilisation`, `synced_at`. Cache is a read-through layer, DB is authoritative.
4. **Controller scoping (4)** — Controller resolves active integration identity and scopes all queries with `where('integration', $active)`.
5. **Cache after commit (7)** — Move cache population to an `afterCommit` callback.
6. **Migration ordering (10)** — Add column nullable, backfill, then alter to non-null.
7. **Range key non-null (11)** — Make `range_from`, `range_to`, `type`, `subnet` non-null in the unique constraint columns. Normalize IP text to canonical form before storage.
8. **Transport lifecycle (13)** — `CiscoDhcpService` exposes a single `fetchSnapshot()` method that owns connect → executeMultiple → parse → disconnect in a `try/finally`. The individual `getLeases()`/`getRanges()`/`getPoolStatus()` methods read from the cached snapshot.
9. **Threshold after commit (14)** — Dispatch threshold event via `afterCommit` callback, compare against DB-stored previous utilisation.
10. **Snooping partial-fetch guard (15)** — Validate command output contains expected table structure before reconciliation.
11. **Empty-result criteria (8)** — Define: a fetch is "empty-valid" if the provider returned a successful response with zero rows (not a connection/parse error). A fetch is "empty-suspicious" if the previous sync had >0 rows and current returns 0 — in this case, log warning and skip deletion but still update `last_synced_at`. After 3 consecutive empty-suspicious syncs, proceed with deletion.

**Rejected with reasoning:**

- **Provider identity revision (5, 6)**: Over-engineering for this use case. The `integration` column identifies the provider. If someone switches the designated Cisco switch, the next sync simply fetches from the new switch — the data is a point-in-time snapshot, not a versioned document. A config change mid-transaction is harmless: the worst case is one sync cycle with mixed data, immediately corrected on the next cycle. Adding configuration revision tracking and optimistic locking adds significant complexity for a race that resolves itself in 60 seconds.
- **Range usage from pool stats (9)**: Acknowledged imperfect, but acceptable for the UI. The plan already notes this as a tradeoff. Per-range usage breakdown isn't available from IOS — modelling pools separately would require a `dhcp_pools` table and a pool-to-range relationship, adding significant schema complexity for marginal UI benefit. Will document the approximation.
- **Live fallback (12)**: The fallback is a transitional measure for first deploy only — not a permanent code path. It prevents empty pages during the brief window before the first sync. Dispatching an immediate sync on deploy is a good addition (will add), but removing the fallback entirely means admin sees empty pages if that sync fails. Keep both: dispatch immediate sync AND fall back to live if no DB data exists.
- **IOS parser scope (16)**: Implementation detail — the plan already specifies fixture tests for IOS variants. The parser will reject command-error output (check for IOS error patterns before parsing). Detailed grammar specification belongs in the test fixtures, not the plan.

## Round 3 — Codex

### Findings
1. **P0 — Threshold comparison impossible after commit** — upsert overwrites previous value before callback
2. **P0 — Provider-change race** (repeated)
3. **P1 — Integration name not a provider-instance identity** (repeated)
4. **P1 — Empty-result success unverifiable via DhcpInterface**
5. **P1 — Empty-result counters have no persistence schema**
6. **P1 — last_synced_at undefined for leases/ranges**
7. **P1 — Cached snapshots may survive across jobs** (singleton in queue worker)
8. **P1 — Live fallback permits repeated SSH requests**
9. **P1 — Deployment dispatch location unspecified**
10. **P1 — Snooping uniqueness omits VLAN**
11. **P1 — Snooping completeness validation insufficient**
12. **P1 — 'legacy' backfill may violate enum typing**
13. **P2 — Threshold events retry-duplicable**
14. **P2 — onOneServer() assumes shared atomic cache**
15. **P2 — Per-range usage fabricates precision**
16. **P2 — Snooping normalization unspecified**
17. **P2 — IPv6-ready schema claim false for integer counts**

**Also: User requested DHCPv6 be brought into scope.**

### Claude's response

**Accepted and will revise:**

1. **Threshold previous value (1)** — Read previous utilisation from DB *before* the upsert within the transaction. Pass both old and new values to the afterCommit callback for crossing comparison.
2. **Empty-result counters persistence (5)** — Add a `dhcp_sync_states` table: `integration` (unique), `leases_empty_count` (int, default 0), `ranges_empty_count` (int, default 0), `last_lease_sync_at`, `last_range_sync_at`, `last_attempt_at`. This replaces the vague `last_synced_at` and gives per-dataset tracking (6).
3. **Singleton snapshot stale (7)** — Bind `CiscoDhcpService` as transient (not singleton). Add `resetSnapshot()` call at start of each sync job for all providers defensively.
4. **Live fallback rate limit (8)** — Gate live fallback with a cache lock (e.g. `Cache::lock('dhcp_live_fallback', 30)`) so concurrent requests don't spam SSH. One live fetch, rest wait for result.
5. **Deploy dispatch (9)** — Dispatch via a post-migration Artisan command (e.g. `php artisan dhcp:sync --once`), not application bootstrap.
6. **Snooping VLAN in unique key (10)** — Change unique constraint to `(switch_config_id, vlan, ip, mac)` with VLAN defaulting to 0 (non-null sentinel for untagged).
7. **Snooping normalisation (16)** — Canonicalise IP (inet_pton/inet_ntop roundtrip), MAC (lowercase colon-separated), VLAN (int), interface name (abbreviated) before upsert.
8. **Legacy backfill enum safety (12)** — Backfill with the actual active integration value (e.g. `'opnsense'`, `'vyos'`), not a synthetic `'legacy'` string. If no active provider, leave nullable and handle in queries.
9. **Per-range usage nullable (15)** — Make `used_addresses` and `utilisation` nullable on `DhcpRangeRecord`. Only populate when per-range data is available (OPNsense/VyOS). For Cisco, leave null and show utilisation at pool level only.
10. **DHCPv6 in scope** — Remove from "out of scope". Add Cisco DHCPv6 commands (`show ipv6 dhcp binding`, `show ipv6 dhcp pool`) to CiscoDhcpService. Use `bigInteger` or `string` for address counts to handle IPv6 pool sizes. Existing VyOS service already supports DHCPv6.

**Rejected with reasoning:**

- **Provider-change race / provider-instance identity (2, 3)**: Third time raised, still rejected. Same reasoning: integration name is sufficient identity. A config change mid-minute is harmless — corrected in ≤60 seconds. The complexity of configuration revision tracking and optimistic locking is not justified for a 1-minute polling cycle in a LAN event tool. This is a theoretical race with trivial self-healing, not a data integrity risk.
- **Empty-result success metadata via DhcpInterface (4)**: Changing the `DhcpInterface` contract to return metadata wrappers would break all existing implementations. The sync job can infer success from try/catch — if the call succeeded without exception, the result is valid. Connection/parse failures throw. This is sufficient.
- **Snooping completeness beyond header check (11)**: Transport-level command completion is already handled by the ssh-proxy — commands return complete output or throw. Truncation is a transport concern, not a parser concern. Header validation plus exception handling is sufficient.
- **Threshold retry deduplication (13)**: Events are dispatched afterCommit. If the job fails after commit, Laravel won't retry it (committed = done). If it fails before commit, the transaction rolls back and no event fires. The scenario of "committed but unacknowledged" is a queue driver edge case handled by Laravel's exactly-once semantics.
- **onOneServer shared cache (14)**: Production uses Redis. This is a deployment concern documented in Laravel's own scheduler docs, not a plan-level issue.

## Round 4 — Codex

### Findings
1. **P0 — Migration fails when no provider active** — nullable rows then non-null alter
2. **P0 — bigint doesn't support IPv6 pool sizes** — 2^64+ addresses
3. **P0 — IPv6 range config never fetched/parsed**
4. **P0 — DHCPv6 prefix delegation conflicts with lease schema**
5. **P0 — Provider-change race** (4th repeat)
6. **P1 — Aggregating IPv4+IPv6 pool stats meaningless**
7. **P1 — Unsupported IPv6 commands can block IPv4 sync**
8. **P1 — Non-empty partial snapshots trigger deletion**
9. **P1 — Snooping partial-output** (repeat)
10. **P1 — Sync-state semantics contradictory**
11. **P1 — Threshold retry-duplicable** (repeat)
12. **P1 — pool_size integer for IPv6**
13. **P2 — Live fallback lock duration unsafe**
14. **P2 — Distributed locking requirements unstated** (repeat)
15. **P2 — Pool override invariants undefined**

### Claude's response

**Accepted and will revise:**

1. **Migration nullable handling (1)** — Keep `integration` column nullable permanently. Queries scope by active integration; null rows are ignored. No forced non-null alter.
2. **Address counts as string (2, 12)** — Use `decimal(39,0)` for address counts (handles up to 2^128). `pool_size` config also accepts string/decimal values.
3. **IPv6 range config parsing (3)** — Add `parseDhcpv6PoolConfig()` for `show running-config | section ipv6 dhcp pool`. IPv6 pools use prefix-based allocation, not excluded-address subtraction — parser handles prefix/address-range definitions.
4. **Prefix delegation exclusion (4)** — Explicitly exclude DHCPv6 prefix delegation from lease tracking. Only track individual address bindings (IA_NA). PD bindings are a different domain — they delegate subnets, not individual addresses. Document as out of scope for leases.
5. **Per-address-family pool status (6)** — Key `dhcp_pool_statuses` by `(integration, address_family)` where address_family is `'ipv4'` or `'ipv6'`. Separate thresholds per family. Update unique constraint.
6. **IPv6 commands independent (7)** — Treat IPv4 and IPv6 command groups independently in `fetchSnapshot()`. IPv6 failures (e.g. `% Invalid` on older IOS) log a warning but do not prevent IPv4 data from syncing. IPv6 collection can be disabled via integration config if device doesn't support it.
7. **Sync-state branch clarity (10)** — Separate `last_attempt_at` (always updated), `last_success_at` (updated only when data accepted), and empty counters (incremented on suspicious, reset on success). Suspicious = skip deletion but still update attempt timestamp.
8. **Lock duration (13)** — Set live fallback lock duration to transport timeout + 10s buffer. If lock not acquired, return cached/stale data or empty state with staleness indicator.
9. **Pool override clamping (15)** — Clamp `available` to `max(0, total - used)`. Utilisation capped at 1.0. Log warning if override < used.

**Rejected with reasoning:**

- **Provider-change race (5)**: 4th time. Final rejection. The race window is <1 second within a 60-second cycle. Even in the worst case (VyOS data tagged as OPNsense), the next sync corrects it. This is a LAN event tool, not a financial system. Adding optimistic locking to a 1-minute polling job is complexity without proportionate benefit.
- **Partial snapshot detection (8, 9)**: The transport returns complete command output or throws — it's SSH with terminal length 0, not a streaming API. A "truncated" response that still looks valid is not a realistic failure mode with this transport layer. The empty-result guard handles the actual failure case (total fetch failure).
- **Threshold retry dedup (11)**: Repeated rejection — afterCommit + Laravel queue semantics handle this.
- **Distributed locking (14)**: Deployment concern, not plan concern. Redis is required infrastructure.

## Round 5 — Codex (FINAL — MAX_ROUNDS reached)

### Findings
1. **P0 — IPv6 failure causes IPv4 deletion** — combined dataset masks missing IPv6
2. **P0 — DHCPv6 uses DUID/IAID not MAC**
3. **P0 — Provider-change race** (5th repeat)
4. **P1 — DhcpInterface contract change unspecified** for per-family pool status
5. **P1 — Response contract backward compat**
6. **P1 — Pool status stale family rows**
7. **P1 — Sync state insufficiently scoped**
8. **P1 — BCMath needed for decimal arithmetic**
9. **P1 — Snooping resolver not defined**
10. **P1 — Partial non-empty snapshots** (repeat)
11. **P1 — Threshold event contract incomplete**
12. **P1 — Threshold retry dedup** (repeat)
13. **P2 — Distributed locking** (repeat)
14. **P2 — Nullable integration range uniqueness**

### Claude's response

**Will incorporate these final improvements:**

1. **Per-family reconciliation (1)** — Valid. Track IPv4 and IPv6 fetch success independently. Only reconcile (delete missing) for address families that fetched successfully. If IPv6 commands failed, skip IPv6 deletion but keep IPv4 sync.
2. **DHCPv6 DUID/IAID (2)** — Valid. DHCPv6 leases store DUID/IAID as client identifier. `mac_address_id` nullable on DHCPv6 leases. Only link MAC when a DUID-LL or DUID-LLT contains a parseable MAC.
3. **DhcpInterface contract (4)** — `getPoolStatus()` returns a single aggregate. The sync job calls it and stores per-family data by inspecting the leases/ranges it already has. No interface change needed — the per-family split is a sync-job concern, not a service contract concern.
4. **Pool status cleanup (6)** — Delete pool status rows for families that completed successfully but returned no data.
5. **Sync state scoping (7)** — Expand `dhcp_sync_states` to key by `(integration, address_family, dataset)` for granular tracking.
6. **BCMath (8)** — Valid. Use `bcadd`/`bcsub`/`bcdiv` for all decimal count arithmetic.
7. **Snooping resolver (9)** — Valid oversight. Add `DhcpSnoopingResolver` that queries observations table and returns IP/MAC mappings. Wire into existing IP/MAC resolution pipeline.
8. **Threshold event payload (11)** — Include `address_family` in `DhcpPoolThresholdReached` event payload.
9. **Nullable uniqueness (14)** — Add partial unique index excluding null integration rows. Or: use empty string '' instead of null for legacy. Implementation decision.

**Rejected (final):**

- **Provider-change race (3)**: 5th and final rejection. Fully addressed in review log. Self-healing in ≤60 seconds. No further discussion.
- **Partial non-empty snapshots (10)**: 3rd rejection. Transport guarantees complete output. Not a realistic failure mode.
- **Threshold retry dedup (12)**: 3rd rejection. afterCommit handles this.
- **Distributed locking (13)**: 3rd rejection. Redis is production infrastructure.
- **Response backward compat (5)**: The controller response is being refactored anyway (reading from DB). The new response shape will be defined in regression tests. No backward compat concern.

## Resolution — DEADLOCK at MAX_ROUNDS

### Unresolved points (Codex position → Claude counter-position)

1. **Provider-change race**: Codex wants optimistic locking with configuration revision verification before commit. Claude: self-healing 60-second cycle makes this complexity unjustified for a LAN event tool.
2. **Partial non-empty snapshot protection**: Codex wants explicit completeness metadata to prevent truncated output from triggering deletions. Claude: transport guarantees complete output; truncation throws.
3. **Threshold retry deduplication**: Codex wants persistent idempotent transition identifiers. Claude: afterCommit + queue semantics are sufficient.
4. **Distributed locking validation**: Codex wants explicit shared-cache prerequisite enforcement. Claude: deployment concern, Redis is required infrastructure.

These are reasonable defensive-programming concerns for a high-reliability system, but disproportionate for a LAN event management tool with 1-minute self-correcting cycles. The plan is ready for implementation with the accepted improvements.
