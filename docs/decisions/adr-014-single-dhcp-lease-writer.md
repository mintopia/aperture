# ADR 014: Single DHCP Lease Writer with Canonical MAC and IP Identity

Status: Accepted
Date: 2026-09-28

## Context

Both the network scan (`PersistDhcpLeasesStep`) and the DHCP sync
(`SyncDhcpData`) wrote `dhcp_leases`, with different rules: the scan skipped
unknown MACs while the sync created them, blank hostnames were stored as null
by one and as an empty string by the other, and only the sync deleted stale
rows. With no shared lock, concurrent `updateOrCreate` calls could hit the
`(ip_address_id, mac_address_id)` unique index and roll back the sync.

Identity inputs were also loose: blank MACs (for example DHCPv6 leases without
a hardware address) collapsed into one shared empty-string MAC row, MAC
normalisation accepted anything and mangled unpadded octets, and IPv6 addresses
were only lowercased, so a non-canonical form in a URL created a duplicate IP.

## Decision

- `SyncDhcpData` is the only writer of `dhcp_leases`. The network scan no
  longer persists leases; it keeps linking IPs and MACs from the same feeds.
  The upsert is keyed on the unique `(ip_address_id, mac_address_id)` columns
  and retries as an update if a concurrent insert wins the race.
- `MacAddress::normalize()` returns `AA:BB:CC:DD:EE:FF` or null. Null means
  blank input or anything that is not exactly 12 hex digits after stripping
  separators; unpadded colon/dash octets are zero-padded first.
- `IpAddress::normalize()` canonicalises both families through
  `inet_pton`/`inet_ntop`, so route binding and lookups agree.
- Blank MAC and blank hostname are null everywhere (`DhcpLease` value object
  and storage).

## Consequences

- Lease rows appear on the sync cadence (every minute), not the scan's.
- Leases without a usable MAC are stored with a null `mac_address_id` and no
  longer create a bogus shared MAC row.
- A migration nulls existing empty hostnames and detaches leases from any
  empty-string MAC row. Pre-existing non-canonical IPv6 `ip_addresses` rows are
  not rewritten.

## Supersedes

None
