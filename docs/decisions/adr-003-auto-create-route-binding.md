# ADR 003: Auto-Creating IP/MAC Records via Route Model Binding

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-012 identified that `IpAddress` and `MacAddress` models auto-create records in `resolveRouteBinding()` when a matching record doesn't exist. `IpAddress` limits creation to addresses within managed network ranges. `MacAddress` creates records unconditionally for any valid MAC format. This could theoretically be used to pollute the database by navigating to admin URLs with fabricated addresses.

## Decision
Accept the current auto-creation behaviour. This is intentional design for the captive portal workflow: when an admin navigates to view details for an IP or MAC address that the system hasn't seen yet, automatically creating the record provides a better UX than returning a 404. The `IpAddress` managed range check prevents creation of records outside the event network. `MacAddress` auto-creation supports the device discovery workflow where admins may look up MACs observed on switches before the portal has seen them.

## Consequences
- An admin could create many records by scripting requests, but this requires authenticated admin access and the records are lightweight.
- The managed range check on `IpAddress` bounds the set of creatable addresses to the event network.
- If non-admin routes ever use these model bindings, the auto-creation behaviour should be revisited.

## Supersedes
N/A
