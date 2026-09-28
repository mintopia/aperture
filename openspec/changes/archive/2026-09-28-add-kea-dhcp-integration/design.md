# Design: Kea DHCP Integration

## Problem Statement

LAN event operators running ISC Kea as their DHCP server cannot see DHCP Leases, DHCP Ranges or Pool Status in Aperture, and cannot resolve attendee IPs to MACs from Kea. Aperture only understands Kea when it sits behind OPNsense. Operators with a standalone Kea (often separate `kea-dhcp4` and `kea-dhcp6` daemons, each with its own API and credentials) have to rely on another Integration or go without.

## Solution

An admin adds a Kea Integration, points it at the IPv4 and/or IPv6 Kea API Endpoints with optional HTTP Basic credentials, and tests the connection. Once Kea is assigned the DHCP Capability, DHCP Leases, DHCP Ranges and Pool Status for both Address Families appear in Aperture on the normal sync cadence, and IP-MAC Lookups hit Kea live. Optionally, Kea can also be assigned the IP-MAC Capability, supplying an IP-MAC Table built from active leases when no router or NMS Integration is available.

## User Stories

1. As an admin, I want to add a Kea Integration, so that Aperture can read DHCP data from my standalone Kea server.
2. As an admin, I want Kea to be separate from the OPNsense Integration, so that I can use Kea without OPNsense.
3. As an admin, I want to configure an IPv4 Endpoint URL, so that Aperture can reach my `kea-dhcp4` API.
4. As an admin, I want to configure an IPv6 Endpoint URL, so that Aperture can reach my `kea-dhcp6` API.
5. As an admin, I want either Endpoint to be optional, so that I can run a v4-only or v6-only network.
6. As an admin, I want to be told when I have configured no Endpoint at all, so that I don't save an Integration that can never work.
7. As an admin, I want to set separate credentials per Endpoint, so that each daemon's own Basic auth configuration works.
8. As an admin, I want credentials to be optional, so that an unauthenticated Kea API on a trusted network works.
9. As an admin, I want a validation error when I set only a username or only a password, so that I catch half-configured auth before it fails at runtime.
10. As an admin, I want passwords stored encrypted, so that Kea credentials are not exposed in the database.
11. As an admin, I want a single "Verify SSL" toggle, consistent with other Integrations, so that self-signed Kea certificates work.
12. As an admin, I want Endpoint URLs to work whether they point at a Kea daemon or a Control Agent, so that I don't have to change my Kea deployment.
13. As an admin, I want to test the connection, so that I know Aperture can talk to Kea before relying on it.
14. As an admin, I want the connection test to report each Endpoint separately, so that I know which daemon is misconfigured.
15. As an admin, I want the connection test to tell me when authentication fails, so that I can fix credentials.
16. As an admin, I want the connection test to tell me when an Endpoint is unreachable, so that I can fix networking or the URL.
17. As an admin, I want the connection test to tell me when the `lease_cmds` hook is missing, so that I know to load it in Kea.
18. As an admin, I want the connection test to skip Endpoints I haven't configured, so that a v4-only setup passes.
19. As an admin, I want to assign the DHCP Capability to Kea, so that Kea becomes the source of DHCP data.
20. As an admin, I want to assign the IP-MAC Capability to Kea or to another Integration independently, so that I can pick the best IP-MAC source for my site.
21. As an operator, I want to see all active IPv4 DHCP Leases from Kea, so that I know which devices are on the network.
22. As an operator, I want to see all active IPv6 DHCP Leases from Kea, so that I have visibility of dual-stack clients.
23. As an operator, I want declined, reclaimed and expired leases hidden, so that the lease list reflects devices actually holding addresses.
24. As an operator, I want large lease tables fetched completely, so that no DHCP Leases are missing at a large event.
25. As an operator, I want each DHCP Lease to show IP, MAC, hostname and expiry, so that I can identify devices.
26. As an operator, I want IPv6 DHCP Leases to show a MAC where one can be derived, so that I can correlate v6 clients with their hardware.
27. As an operator, I want to see each Kea pool as a DHCP Range, so that I understand the address space being handed out.
28. As an operator, I want pools defined inside shared networks included, so that no DHCP Range is missing.
29. As an operator, I want DHCP Ranges labelled with the name I gave them in Kea, so that they are recognisable.
30. As an operator, I want unnamed DHCP Ranges labelled by subnet and bounds, so that they are still identifiable.
31. As an operator, I want Pool Status per DHCP Range, so that I can see when a pool is close to exhaustion.
32. As an operator, I want IPv6 Pool Status to display sensibly for huge pools, so that a /64 doesn't break the display.
33. As an operator, I want IPv4 data to keep updating when the IPv6 Endpoint is down (and vice versa), so that one failing daemon doesn't blind me to the other.
34. As an operator, I want a failed Address Family to keep its last-known data, so that a transient outage doesn't blank the dashboard.
35. As an operator, I want to see which Address Family's sync failed, so that I know where to look.
36. As an operator, I want IP-MAC Lookup to hit Kea live, so that newly-joined devices resolve immediately without waiting for the next sync.
37. As an operator, I want lookups for an unconfigured Address Family to return nothing quietly, so that v4-only sites see no errors for v6 addresses.
38. As an operator, I want lookups to fall back to the IP-MAC Capability when Kea is unreachable, so that MAC resolution degrades gracefully.
39. As an operator at a site with no router or NMS Integration, I want Kea to supply the IP-MAC Table, so that IP-to-MAC mapping still works.
40. As an operator, I want the IP-MAC Table to cover both Address Families, so that v6 clients are mapped too.
41. As an attendee, I want my device to be recognised by its MAC via Kea, so that features that depend on device identity work for me.

## Implementation Decisions

- **New Integration**: a `kea` case in the Integration enumeration, with its own bootstrapper registered alongside the others, offering the `dhcp` and `ip-mac` Capabilities. Not an extension of the OPNsense adapter's Kea mode.
- **Config schema**: fields `endpoint_v4`, `username_v4`, `password_v4`, `endpoint_v6`, `username_v6`, `password_v6`, `verify_ssl`. Password fields are password-typed so they are encrypted by the existing mechanism. Validation: at least one endpoint; username/password required together per Endpoint.
- **Kea client module**: a single deep module owning all Kea communication for one Endpoint — builds the command envelope (`command`, `service`, `arguments`), applies Basic auth and TLS verification, interprets Kea's result codes (0 success, 3 empty, others error), and exposes typed operations: list commands, get config, page leases, get lease by address. Uses Laravel's HTTP client so all Kea traffic shares one test seam.
- **Lease parsing**: shared across sync, live lookup and IP-MAC Table: active filter (state 0, `cltt + valid-lft > now`) and IPv6 MAC derivation (`hw-address` → DUID-LLT/LL Ethernet → null).
- **DHCP service**: implements the existing DHCP Capability interface (leases, lease by IP, ranges, pool status) by composing one Kea client per configured Address Family.
- **Range parsing**: from `config-get` `Dhcp4.subnet4` / `Dhcp6.subnet6` plus `shared-networks[].subnet4|subnet6`; pools in range or CIDR notation; `pd-pools` ignored; label fallback pool name → subnet name → CIDR + bounds.
- **Pool Status**: computed from Range size and active DHCP Leases in range, reusing the approach of the existing OPNsense DHCP service; IPv6 totals capped.
- **Per-family failure**: the service reports per-family fetch status so the existing sync job can update one Address Family and preserve the other, recording failure in that family's sync state.
- **IP-MAC resolver**: implements the existing IP-MAC Capability interface from active leases with resolvable MACs, both families.
- **Connection tester**: implements the existing testable-integration contract; runs `list-commands` per configured Endpoint; checks for `config-get` and the family's lease paging command; per-Endpoint result messages.
- **No schema changes**: existing DHCP storage already keys by Address Family.
- **No ADR**: the multi-Endpoint config is deliberately not recorded as an ADR.

## Testing Decisions

- **Good tests** exercise external behaviour only: given Kea HTTP responses and an Integration configuration, assert on what Aperture stores and returns. No assertions on internal method calls or intermediate structures.
- **Seam 1 — Kea HTTP API**: `Http::fake` with realistic Kea JSON fixtures. Feature tests configure the Integration and Capability assignment, run the real DHCP sync job / MAC resolver / connection tester, then assert on stored DHCP Leases, DHCP Ranges, Pool Status, per-family sync state, the IP-MAC Table and test results. Request assertions only where behaviour is externally visible (auth header, `service` parameter, no request to an unconfigured Endpoint).
- **Seam 2 — Admin UI**: Playwright covers configuring the Kea Integration (v4-only, v6-only, dual), validation errors, and running the connection test. All form elements under test get test IDs.
- **Prior art**: the Cisco DHCP feature test (real sync job, mocked transport, database assertions) is the template for Seam 1; existing connection-tester tests using `Http::fake` for the tester cases.
- **Coverage**: 100% of new code; Pint, PHPStan level 8 and Rector clean.

## Out of Scope

- Host reservations not currently leased.
- Prefix-delegation pools (`pd-pools`) — not modelled.
- `stat_cmds` / Kea statistics — Pool Status is computed instead.
- `subnet_cmds` — `config-get` is used instead.
- Kea High Availability / failover between peers — one server per Address Family.
- Changes to the OPNsense adapter's Kea mode.

## Further Notes

- Tickets: epic mintopia/aperture#3; slices #4–#12 in dependency order (see `tasks.md`).
- Glossary terms (Endpoint, Address Family, DHCP Lease, DHCP Range, Pool Status, IP-MAC Lookup, IP-MAC Table) are defined in `CONTEXT.md`.
- Relevant ADR: adr-005 (accepted SSRF risk for connection testers) applies to the Kea connection tester.
