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
enforcement flag: `IpAddressObserver` dispatches `SyncFirewallJob` (internet action)
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

In code, `IpAddress::internet_enabled` is cast to the `App\Enums\InternetState`
enum (`Allowed`, `Blocked`, `Undecided`) by `InternetStateCast`; the column and
the JSON serialisation (`true`/`false`/`null`) are unchanged.

For enforcement there is one rule, `IpAddress::isInternetAllowed()` (backed by
`InternetState::isAllowed()`): **only `Allowed` is allowed; `Undecided` is
treated as blocked (deny-by-default)**. Firewall, policy and portal consumers
call it instead of coercing with `(bool)`, so an undecided IP gets no firewall
allow rule. The admin
status filter exposes `allowed`/`blocked`/`unassigned` (the last mapping to
`whereNull`).

Existing rows are left untouched by the migration: pre-existing `false` IPs
continue to display as "Blocked".

## Consequences
- The Status column is meaningful again, and undecided IPs are visibly distinct
  from explicitly blocked ones.
- Enforcement is unchanged in effect: null and false both result in no internet
  access; only the displayed/recorded intent differs.
- Code deciding access must call `isInternetAllowed()`; the property type is now
  the `InternetState` enum, not `bool|null`.
- The binary Grant/Revoke control on the IP detail page treats null as "not
  granted" (offers Grant), which is acceptable.

## Supersedes
N/A
