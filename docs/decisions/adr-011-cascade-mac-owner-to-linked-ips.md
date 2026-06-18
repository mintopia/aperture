# ADR 011: Cascade MAC Owner to Newly Linked IPs

Status: Accepted
Date: 2026-06-10

## Context

User↔IP associations were only created at portal login
(`UserNetworkAssociationService::addIp` plus a one-hop cascade across the MACs
linked to the login IP at that moment) and via the portal IPv6 detection
endpoint. IPv6 SLAAC privacy addresses rotate and appear after login, so a new
IPv6 that is later linked to a user-owned MAC (by the network scan, by
`IpAddressActionService::enableInternet`, or by IPv6 detection) was never
associated with the owner. Production showed exactly this: a DHCPv6-discovered
IPv6 linked to an owned MAC with "No associated users".

## Decision

Whenever an IP↔MAC link is created or refreshed (`IpMacLinked` event,
dispatched from `LinkIpMacStep`, `IpAddressActionService::enableInternet`, and
`PortalController::ipv6`), a listener cascades the MAC's owner onto the IP:
it creates the user↔IP association and applies the owner's policy via
`UserNetworkAssociationService::addIp(owner, ip, cascade: false)`, recording an
`ip.user_cascaded` audit entry. The cascade is skipped when the MAC has no
owner, when a different user is already associated with the IP, or when the
association already exists. Dispatching on refresh (not only first link) lets
existing production rows heal on the next scan.

## Consequences

- Devices with rotating IPv6 privacy addresses stay attributed to their owner
  without requiring a fresh portal login per address.
- Ownership propagates one hop only (MAC → IP); recursion is prevented by
  `cascade: false`, mirroring the existing login-time cascade semantics.
- An IP claimed by a different user is never silently re-assigned.
- The cascade applies user policy (and firewall enablement when the IP already
  has internet enabled) from background jobs, not just portal requests.

## Supersedes

N/A
