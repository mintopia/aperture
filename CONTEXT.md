# Aperture

Network operations platform for LAN events: it pulls network state (DHCP, IP/MAC, bandwidth, switch ports) from external systems and presents it to admins and attendees.

## Language

### Integrations

**Integration**:
An external system Aperture talks to (e.g. OPNsense, LibreNMS, Kea), configured once per installation.
_Avoid_: Plugin, connector, module

**Capability**:
A kind of data or control an Integration can supply (e.g. DHCP, IP-MAC). Each Capability is assigned to at most one Integration at a time.
_Avoid_: Feature, provider type

**Endpoint**:
A single network address of an Integration's API, with its own credentials. Most Integrations have one; Kea has one per address family.
_Avoid_: Server, host, URL

**Address Family**:
IPv4 or IPv6.
_Avoid_: Stack, IP version, protocol

### DHCP

**DHCP Lease**:
A current binding of an IP address to a client, as issued by the DHCP server. Only leases in the default state and not yet expired are DHCP Leases; declined, expired or reclaimed bindings are not.
_Avoid_: Allocation, assignment

**DHCP Range**:
A dynamic address pool within a subnet from which the DHCP server hands out leases. Prefix-delegation pools are not DHCP Ranges.
_Avoid_: Pool, scope, subnet

**Pool Status**:
Utilisation of a DHCP Range: how many of its addresses are covered by DHCP Leases versus its total size.
_Avoid_: Pool usage, pool stats

### IP-MAC

**IP-MAC Lookup**:
Resolving an IP address to the MAC address of the device using it, via the DHCP Lease first, then the IP-MAC Capability, then DHCP snooping observations as the last fallback (ADR-015).
_Avoid_: ARP lookup, MAC resolution

**IP-MAC Table**:
The full set of known IP-to-MAC pairs supplied by the Integration holding the IP-MAC Capability. May come from ARP (router, NMS) or from Kea's live DHCP Leases, independent of which Integration holds DHCP.
_Avoid_: ARP table (unless the source really is ARP)
