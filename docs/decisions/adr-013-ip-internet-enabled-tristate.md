# ADR 013: IP internet_enabled is tri-state (null = no explicit decision)
Status: Accepted
Date: 2026-06-18

## Context
The admin IP list shows a Status column with three intended states (per the
frontend `ipStatus` util and commit 53182d6): Allowed, Blocked, and "—". The
column read `row.allowed`, but `allowed` was renamed to `internet_enabled`
(migration 2026_04_22_151423) and the frontend was never updated, so the column
always rendered "—".

`internet_enabled` was a non-nullable boolean (default false) and is the live
enforcement flag: `IpAddressObserver` dispatches `SyncInternetAccessJob`
(firewall allow/block) on change, and `IpPolicyService` derives it from user
policy. With only true/false, a freshly-discovered IP that nobody has decided on
was stored as `false` and would read as "Blocked", which is wrong — it has no
explicit decision.

## Decision
`ip_addresses.internet_enabled` is nullable with default null, representing three
states:

- `true`  → explicitly allowed → "Allowed"
- `false` → explicitly blocked → "Blocked"
- `null`  → no explicit decision (e.g. auto-discovered) → "—"

Discovery paths (network scan, DHCP sync, user association before policy) create
IPs without setting the flag, so they default to null. Admin grant/block and
user-policy application set true/false explicitly.

For enforcement, **null is treated as blocked (deny-by-default)**: every consumer
coerces with `(bool)` (e.g. `IpAddressObserver`, `IpPolicyService`,
`UserShowDataService`), so an undecided IP gets no firewall allow rule. The admin
status filter exposes `allowed`/`blocked`/`unassigned` (the last mapping to
`whereNull`).

Existing rows are left untouched by the migration: pre-existing `false` IPs
continue to display as "Blocked".

## Consequences
- The Status column is meaningful again, and undecided IPs are visibly distinct
  from explicitly blocked ones.
- Enforcement is unchanged in effect: null and false both result in no internet
  access; only the displayed/recorded intent differs.
- All code reading `internet_enabled` must treat null as "not enabled" — the
  property type is now `bool|null`.
- The binary Grant/Revoke control on the IP detail page treats null as "not
  granted" (offers Grant), which is acceptable.

## Supersedes
N/A
