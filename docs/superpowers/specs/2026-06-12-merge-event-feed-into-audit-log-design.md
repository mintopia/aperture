# Spec: Collapse SystemEvent into AuditLog

Date: 2026-06-12
Status: Approved (pending implementation plan)

## Problem

Aperture has two overlapping activity surfaces:

- **Event Feed** — backed by the `SystemEvent` model. Populated automatically by a
  single wildcard listener (`RecordBroadcastEvent`) that flattens every broadcast
  event into a denormalised row (`type`, `level`, `message`, `data`). Surfaced as a
  live dashboard widget and a dedicated `Admin/Events/Index` page. Pruned on a
  retention schedule.
- **Audit Log** — backed by the `AuditLog` model. Populated deliberately via explicit
  `AuditLog::record(...)` calls. Rich relational shape (polymorphic actor / subject /
  related, `process`, `metadata`), a human-readable description generator, entity
  deep-links, and filtering. An accountability/compliance trail.

The two feel redundant (≈4 admin actions emit *both* a broadcast event → SystemEvent
*and* an explicit audit record) and the dashboard widget feels thin (it stores `level`
but the component discards it, so every row looks identical).

## Decision

`AuditLog` becomes the **single activity store**. `SystemEvent` is deleted entirely.
The canonical history of network changes is the switches' own syslog, so Aperture does
not need a second canonical store — the dashboard widget is a convenience surface for
admins. Autonomous network telemetry is recorded as actor-less audit entries; the
dashboard reads purely from `AuditLog`.

This supersedes the earlier "blended widget over two stores" direction considered during
brainstorming, which was rejected because it left two stores and did not simplify.

## Scope

### A. Delete the SystemEvent subsystem

Remove:

- `app/Models/SystemEvent.php`
- `database/factories/SystemEventFactory.php`
- `app/Http/Controllers/Admin/EventController.php`
- `resources/js/Pages/Admin/Events/Index.vue`
- `app/Console/Commands/PruneSystemEventsCommand.php`
- The `admin.events.index` route
- All associated tests (`SystemEventTest`, `EventControllerTest`,
  `PruneSystemEventsCommandTest`, and any factory references)

Add a migration that **drops the `system_events` table**. The existing rows are not
migrated — syslog is the system-of-record for network history.

### B. `AuditLog::record()` becomes the single choke point

After creating the row, `AuditLog::record(...)` dispatches a new **`AuditLogRecorded`**
event (`ShouldBroadcast`, on the existing `admin.events` private channel). Payload is
lean and pre-rendered for the dashboard:

- `id`
- `action`
- `description` (via `AuditLogDescriptionGenerator`)
- `severity`
- actor label + url (nullable)
- `created_at` (ISO 8601)

Every audit write — explicit controller calls and telemetry-sourced ones — now flows
live to the dashboard through this one path. No separate broadcast wiring per call site.

### C. Rewrite `RecordBroadcastEvent`

> **Scope correction (post-investigation).** An audit of actual production dispatch
> sites showed that most broadcast events the old `LEVEL_MAP` referenced are never
> dispatched, and the actor-driven actions are *already* audited at their controller
> source. Only **three** telemetry events both fire in production **and** lack existing
> audit coverage. The listener rewrite is scoped to exactly those three.

Replace `SystemEvent::create(...)` with translation of an **explicit allow-list** of the
three live, unaudited telemetry events into `AuditLog::record(...)`:

| Event | Dispatch site | action | subject | severity | process |
|---|---|---|---|---|---|
| `SwitchUnreachable` | `Services/NetworkSwitch/CircuitBreaker` | `switch.unreachable` | `SwitchConfig` (typed prop `$switchConfig`) | critical | network |
| `BandwidthAnomalyDetected` | `Console/Commands/DetectBandwidthAnomaliesCommand` | `bandwidth.anomaly` | null (event carries IP as a string, not a model) | warning | network |
| `DhcpPoolThresholdReached` | `Jobs/SyncDhcpData` | `dhcp.threshold_reached` | null (pool is a string) | warning | network |

`SwitchUnreachable`'s subject is read from the event's typed promoted property
(`$event->switchConfig`). The other two carry only scalars, so `subject` is null and all
details live in `metadata` (the `broadcastWith()` payload).

The allow-list is what keeps the rewrite safe — every event NOT on the list is ignored:

- **Already audited at source (do not re-record):** `InternetAccessChanged`
  (`ip.internet_toggled` / `user.internet_toggled`), `RateLimitChanged`
  (`ip.rate_limit_toggled`), `DnsFilterChanged` (`ip.dns_filter_toggled` /
  `user.dns_filter_toggled`), `UserBlocked` (`user.block_toggled`). These already appear
  in the Audit Log with a real `actor`; recording them again here would duplicate them
  actor-less.
- **`AuditLogRecorded` is excluded** — prevents the wildcard listener from recursing on
  its own broadcast.
- **Orphan events (`DeviceDiscovered`, `SwitchSyncCompleted`, `UserConnected`,
  `RateLimitChanged`, `DnsFilterChanged`, `UserBlocked`, `PortStateChanged`) are not
  dispatched in production** and are left untouched. `DeviceDiscovered` is deliberately
  *not* mapped: discovery is already audited as `mac.created` / `ip.created` by the
  network scan, so mapping it would duplicate those. `UserConnected` is left to
  `user.captive_login` (deliberate logins); a generic device-online audit is out of
  scope (syslog owns that volume).

Note: `PortStateChanged` does not implement `ShouldBroadcast`, so it never produced
SystemEvents. Its dead dashboard handler is removed as part of section F.

### D. Extend `AuditLogDescriptionGenerator`

Add description cases for the new telemetry actions, porting the wording from the old
`RecordBroadcastEvent::formatMessage`. Keeps all human-readable rendering centralised in
the generator (single source of truth for both the dashboard and the Audit Log page).

### E. `severity` column on `audit_logs`

Migration adds a nullable `severity` enum-style column (`info | warning | critical`,
default `info`). `AuditLog::record(...)` gains an optional `severity` parameter
defaulting to `info`. Telemetry-sourced records set it per the table in section C;
existing explicit call sites keep `info` except where a higher level is clearly
warranted (e.g. `UserBlocked` → `critical`, matching the old `LEVEL_MAP`). This drives
the dashboard severity dot and is the fix for the "feels thin" complaint. Chosen over
deriving severity client-side from the action string, which would be brittle.

### F. Dashboard

- Rename `resources/js/Components/Admin/EventFeed.vue` → `RecentActivity.vue`
  (retitled "Recent Activity").
- `HomeController` seeds the `recentEvents` prop from recent **`AuditLog`** rows mapped
  to the unified shape (id, action, description, severity, actor, created_at, urls),
  ordered by `created_at` desc, capped (retain the existing display cap behaviour).
- The widget subscribes to the single `AuditLogRecorded` broadcast for live prepend.
  Existing stat-refresh triggers on the raw domain events are retained for updating the
  stat cards; they no longer add feed rows (the `AuditLogRecorded` handler owns rows).
- Renders description + actor + a severity dot/colour with entity deep-links.
- "View All →" points at the Audit Log page (`admin.audit-log.index`).

## Architecture notes

- **Single live channel:** all activity reaches the dashboard via `AuditLogRecorded` on
  `admin.events`. No double live entries because the row-producing path is exactly one
  per logical action.
- **No recursion:** the wildcard listener's allow-list excludes `AuditLogRecorded`.
- **No double-record:** the 4 actor-driven types are excluded from the listener because
  their controllers already audit them with proper actor attribution.
- **Retention:** the audit log is no longer pruned by the deleted command. If a future
  retention policy is wanted for telemetry-sourced rows, it is out of scope here.

## Testing

Backend (PHPUnit, parallel, SQLite, array cache/session, XDEBUG_MODE=coverage):

- `RecordBroadcastEvent` records an `AuditLog` for each allow-listed telemetry event
  with correct action / subject / related / severity / process / metadata.
- The 4 actor-driven types do **not** produce a listener-sourced audit record.
- `AuditLogRecorded` is **not** re-recorded (no recursion).
- `AuditLog::record(...)` dispatches `AuditLogRecorded` with the expected payload.
- `AuditLogDescriptionGenerator` produces correct descriptions for the new actions.
- `HomeController` seeds `recentEvents` from audit logs in the correct order/shape.
- Migration drops `system_events`; migration adds `severity` with the correct default.

Frontend (vitest):

- `RecentActivity.vue` renders both actor and actor-less rows, severity dot per level,
  deep-links, empty state, and live prepend on `AuditLogRecorded`.

UI (Playwright): dashboard Recent Activity widget journey; Audit Log page unaffected.

Net: deletion of the SystemEvent test suite. All project quality gates apply (100%
coverage, Pint, PHPStan level 8, Rector Laravel ruleset, eslint/prettier, audit skill
for UI changes).

## Out of scope

- Any retention/pruning policy for the audit log.
- Changes to the Audit Log page itself beyond it being the "View All" destination.
- Migrating existing `system_events` rows.
