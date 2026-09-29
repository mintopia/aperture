# Decision: IP-MAC source independence and precedence

Status: accepted
Date: 2026-09-28

## Context

The Kea IP-MAC Table read stored `dhcp_leases` tagged `kea`. Those rows exist only for the Integration holding the DHCP Capability (ADR-014), so assigning IP-MAC to Kea and DHCP elsewhere gave an always-empty table. DHCP snooping observations were collected from switches but never used for IP/MAC resolution.

## Decision

- `KeaIpMacResolver` reads leases live from Kea (the same `KeaDhcpService` snapshot the DHCP Capability uses) and no longer depends on stored leases or on Kea holding DHCP. `SyncDhcpData` stays the only writer of `dhcp_leases`.
- DHCP snooping observations are the lowest-precedence IP-MAC source. `DhcpSnoopingResolver::supplement()` adds an observation only for IPs the IP-MAC Table has no entry for.
- IP-MAC Lookup precedence: DHCP Lease, then the IP-MAC Capability table, then snooping observations. `ScanNetworkDevices` uses the same table plus snooping.

## Consequences

- Kea IP-MAC costs one extra Kea lease query per scan/lookup when DHCP is not Kea.
- Snooping never overrides an authoritative source, so a stale observation cannot rewrite a known pairing.

## Supersedes

None
