# ADR 011: Cascade MAC Owner to Newly Linked IPs

Status: Accepted (amended 2026-09-28)
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

Whenever an IP↔MAC link is created or refreshed (`IpMacObserved` event — named for observation, not creation, because it deliberately also fires on refresh;
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
- An IP claimed by a different user is re-assigned only under the amendment below.
- The cascade applies user policy (and firewall enablement when the IP already
  has internet enabled) from background jobs, not just portal requests.

## Supersedes

N/A

## Amendment 2026-09-28: ownership follows the current lease

The original rule ("an IP is never silently re-assigned") assumed addresses are
not reused during an event. They are: when user A leaves and A's IP is re-leased
to user B's MAC, B's traffic inherited A's internet access and policy.

- When a DHCP-sourced `IpMacObserved` shows the IP held by a MAC owned by a
  different user (or by no user), and none of the previous owner's MACs still
  has a lease on that IP, the IP's associations are removed. The new holder's
  owner is then associated and their policy and firewall state applied; if the
  MAC is unowned the IP is reset to the deny-by-default policy. The change is
  audited as `ip.user_reassigned`.
- MACs derived from a DHCPv6 DUID are stored with source `dhcp_duid`. Cloned
  Windows images share a DUID, so several machines can map to one derived MAC.
  Such MACs never trigger the ownership cascade or reassignment, and are not
  assigned an owner by the login cascade.
