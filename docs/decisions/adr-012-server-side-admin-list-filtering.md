# ADR 012: Server-side filtering for paginated admin list pages
Status: Accepted
Date: 2026-06-18

## Context
Admin list pages (IP Addresses, MAC Addresses, Users, Audit Log) expose search
boxes and dropdown filters via the shared `FilterBar` component. An audit found
the implementation was inconsistent:

- Some pages (IPs address, Switches) filtered server-side via query params.
- Others (Users search + status, Audit Log free-text search) filtered
  **client-side over only the current paginated page**, so a match on page 3 was
  invisible from page 1.
- Two dropdowns (IPs Status, MAC Source) were sent to the server but silently
  ignored, and one search box (MAC) only matched a single column server-side
  despite advertising "MAC, hostname, or user".

Client-side filtering is only correct when the full dataset is delivered to the
browser (e.g. DHCP Leases, which is not paginated).

## Decision
Any admin list page that uses server-side pagination MUST filter server-side.
Each `FilterBar` search box / dropdown maps to a query parameter handled in the
controller (or its data service), using `SearchHelper::toLikePattern` for
contains-style text matching and exact `where` for enumerated dropdowns. The
frontend issues `router.get(..., { preserveState: true })` and renders the
server-returned page (`*.data`) directly — it does not re-filter rows locally.

Pages that deliver the entire dataset un-paginated may continue to filter
client-side.

## Consequences
- Filters operate over the whole dataset, not just the loaded page.
- Filter behaviour is consistent and testable via feature tests asserting
  `*.data` counts.
- A filter that returns zero rows must still render the `FilterBar` (gate the
  table section on "has rows OR has an active filter") so the user is not
  trapped.
- Per-page client-side summary strips (e.g. Users active/blocked counts) reflect
  the loaded page only; global counts would require explicit controller support.

## Supersedes
N/A
