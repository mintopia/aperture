# network-device-tracking Specification

## Purpose
Aperture records every MAC address and managed IP address it discovers, persists the associations between IPs, MACs, users, switch ports and DHCP Leases, audits each creation and link, and lets admins browse the cross-referenced data.

## Requirements

### Requirement: Periodic Discovery Scan
The system SHALL run a network scan every five minutes, without overlapping runs, that collects DHCP Leases, the IP-MAC Table and the switch forwarding database from the Integrations holding those Capabilities, and persists the results.

#### Scenario: Scan collects all sources
- **WHEN** the scan runs
- **THEN** DHCP Leases, IP-MAC Table entries and forwarding entries are all read and persisted

### Requirement: MAC Address Persistence
The scan SHALL store every distinct MAC address seen from any source, normalised to uppercase colon-separated form, with the first source that saw it (`dhcp`, `arp` or `switch`) recorded on creation. An existing MAC MUST NOT be duplicated.

#### Scenario: New MAC from DHCP
- **WHEN** a MAC appears in a DHCP Lease and is not yet stored
- **THEN** a MAC address record is created with source `dhcp` and a `mac.created` audit entry is recorded by process `scan_network`

#### Scenario: Known MAC seen again
- **WHEN** a stored MAC appears in a later scan
- **THEN** no new MAC record or audit entry is created

### Requirement: IP Address Persistence Within Managed Ranges
The scan SHALL store each discovered IP address only if it falls within the managed network ranges, and SHALL refresh `last_seen_at` on already stored addresses.

#### Scenario: Managed IP discovered
- **WHEN** a scan sees an unstored IP inside a managed range
- **THEN** an IP address record is created and an `ip.created` audit entry is recorded

#### Scenario: Unmanaged IP discovered
- **WHEN** a scan sees an IP outside every managed range
- **THEN** no IP address record is created

### Requirement: IP-MAC Association
The scan SHALL maintain a many-to-many association between IPs and MACs, recording the discovery source (`dhcp`, `arp`, `ipv6_detection`) and the time last seen for each pair.

#### Scenario: New pairing
- **WHEN** a managed IP is seen with a MAC it was not previously paired with
- **THEN** the pair is created with its source and `last_seen_at`, and an `ip_mac.linked` audit entry is recorded

#### Scenario: Existing pairing
- **WHEN** an already paired IP and MAC are seen again
- **THEN** only the pair's `last_seen_at` is updated

#### Scenario: Multiple MACs per IP
- **WHEN** an IP is seen with two different MACs over time
- **THEN** both pairings are retained and the most recently seen MAC is the IP's current MAC

### Requirement: DHCP Lease Persistence
The scan SHALL persist each DHCP Lease that has a MAC and a managed IP, keyed by IP and MAC, storing the hostname, expiry and the DHCP Integration that supplied it.

#### Scenario: Lease refreshed
- **WHEN** a lease for an already stored IP and MAC is seen with a new hostname or expiry
- **THEN** the stored lease is updated rather than duplicated

#### Scenario: Lease without MAC
- **WHEN** a lease has no MAC
- **THEN** it is not persisted

### Requirement: Switch Port MAC Linking
The system SHALL link stored switch port MAC entries to their MAC address records, and MACs learned on trunk ports MUST NOT be tracked.

#### Scenario: Port MAC linked
- **WHEN** a forwarding entry's MAC matches a stored MAC address and its port MAC entry is unlinked
- **THEN** the entry is linked and a `port_mac.linked` audit entry is recorded

#### Scenario: Trunk port
- **WHEN** a port sync finds MAC entries on a port whose switchport mode is trunk
- **THEN** those entries are skipped

### Requirement: OUI Auto-Allow Policy
Admins SHALL be able to configure OUI prefixes on the Network settings page. After each scan, every IP paired with a MAC matching a prefix that does not have internet enabled SHALL have it enabled. Each prefix MUST be one to six colon-separated hex octets and is stored uppercase.

#### Scenario: Matching MAC
- **WHEN** a MAC starts with a configured prefix and its IP has internet disabled
- **THEN** internet is enabled on the IP and an `oui.auto_allowed` audit entry is recorded by process `oui_policy`

#### Scenario: No prefixes configured
- **WHEN** no prefixes are configured
- **THEN** the policy step does nothing

#### Scenario: Invalid prefix
- **WHEN** an admin submits a prefix that is not colon-separated hex octets
- **THEN** the settings are rejected with a validation error naming the prefix

### Requirement: User Login MAC Ownership Cascade
When a user is associated with a managed IP, the system SHALL assign that user to every unowned MAC paired with the IP, and then associate the user with the other IPs of MACs they own, except IPs already associated with a different user. The cascade MUST go one hop only (IP to MAC to sibling IPs), and each step is audited (`mac.user_assigned`, `ip.user_cascaded`).

#### Scenario: Unowned MAC claimed
- **WHEN** a user logs in on an IP whose MAC has no owner
- **THEN** the MAC becomes owned by the user

#### Scenario: Sibling IP cascaded
- **WHEN** the user owns a MAC that also has an IPv6 address paired
- **THEN** the user is associated with that IPv6 address

#### Scenario: MAC owned by another user
- **WHEN** the MAC already belongs to a different user
- **THEN** ownership is not changed and no sibling IPs are cascaded

#### Scenario: Sibling IP owned by another user
- **WHEN** a sibling IP is associated with a different user
- **THEN** that IP is skipped

### Requirement: Cascade on New Link
When an IP-MAC link is created or refreshed and the MAC has an owner, the system SHALL associate the owner with the IP unless the IP belongs to a different user or the owner is already associated.

#### Scenario: Late link
- **WHEN** a scan links an owned MAC to a new IP
- **THEN** the MAC's owner is associated with the IP and an `ip.user_cascaded` audit entry is recorded

### Requirement: Audit Log
Every audit entry SHALL record an action, an optional subject, related entity and actor, the originating process, optional metadata and a severity. Admins SHALL be able to browse entries in a paginated log filterable by action, process, subject type and date range, and sortable by time, action, subject type or process.

#### Scenario: Filter by action
- **WHEN** an admin filters the audit log by action `mac.created`
- **THEN** only entries with that action are listed

#### Scenario: Entity links
- **WHEN** an entry's subject is a user, IP address, MAC address or switch
- **THEN** the log links to that entity's admin page

### Requirement: MAC Address Admin Pages
Admins SHALL have a MAC address list, filterable by MAC, hostname, user nickname, IP and source, and a detail page for each MAC showing its owner, source, associated IPs with source and last seen, DHCP Leases, switch ports with VLAN, and recent audit entries.

#### Scenario: List filtering
- **WHEN** an admin filters the list by hostname
- **THEN** only MACs with a DHCP Lease matching that hostname are listed

#### Scenario: Detail page
- **WHEN** an admin opens a MAC's page
- **THEN** its associated IPs, DHCP Leases, switch ports and audit entries are shown

### Requirement: Cross-Referenced Admin Views
The IP address detail page SHALL show the IP's MAC addresses, DHCP Leases and audit entries, the user detail page SHALL show audit entries, and the switch port page SHALL show connected devices from stored MAC, IP, user and lease relationships.

#### Scenario: IP page
- **WHEN** an admin opens an IP address page
- **THEN** linked MACs, DHCP Leases and audit entries are shown

### Requirement: Stale IP-MAC Mapping Purge
Admins SHALL be able to delete IP-MAC pairings not seen for more than a chosen number of days (1 to 3650) from the Network settings page. The action MUST require the admin's password and SHALL record a `network.ip_mac_mappings.cleared` audit entry with the day count and number deleted.

#### Scenario: Purge succeeds
- **WHEN** an admin submits a valid day count and correct password
- **THEN** pairings whose `last_seen_at` is strictly older than that many days are deleted and the count is reported

#### Scenario: Wrong password
- **WHEN** the password is incorrect
- **THEN** nothing is deleted and an error is shown

#### Scenario: Invalid days
- **WHEN** the day count is missing, below 1, above 3650 or not an integer
- **THEN** the request is rejected with a validation error
