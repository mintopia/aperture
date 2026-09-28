# managed-network-ranges Specification

## Purpose
Admins define which IPv4 and IPv6 ranges Aperture manages. Addresses outside those ranges, such as remote admin or VPN sessions, are never linked to users, given firewall rules or tracked.

## Requirements

### Requirement: Range Membership
The system SHALL treat an IP address as managed if and only if it falls within at least one configured CIDR range of its Address Family. Invalid IP strings MUST NOT be managed.

#### Scenario: Inside a range
- **WHEN** the IPv4 ranges contain `10.0.0.0/8` and the IP is `10.1.2.3`
- **THEN** the IP is managed

#### Scenario: Outside every range
- **WHEN** the IP is `192.168.1.1` and no range contains it
- **THEN** the IP is not managed

#### Scenario: Address Family separation
- **WHEN** only IPv4 ranges are configured and an IPv6 address is tested
- **THEN** it is checked against the IPv6 ranges only

### Requirement: Range Defaults
When no ranges are stored, IPv4 SHALL default to `0.0.0.0/0` and IPv6 to `::/0` so every address is managed. An explicitly stored empty list MUST manage no addresses of that Address Family.

#### Scenario: Fresh install
- **WHEN** no range settings exist
- **THEN** all IPv4 and IPv6 addresses are managed

#### Scenario: Emptied list
- **WHEN** an admin saves an empty IPv4 range list
- **THEN** no IPv4 address is managed

### Requirement: Network Settings Page
Admins SHALL edit IPv4 ranges, IPv6 ranges and the default DNS filtering state for new connections at `/admin/settings/network`, entering one CIDR per line.

#### Scenario: Save ranges
- **WHEN** an admin saves valid ranges
- **THEN** they are stored and a success message is shown

### Requirement: CIDR Validation
Each submitted line SHALL be a valid IP with a numeric prefix, 0 to 32 for IPv4 or 0 to 128 for IPv6, and the IP MUST belong to the field's Address Family.

#### Scenario: Bad prefix
- **WHEN** an admin submits `10.0.0.0/33` as an IPv4 range
- **THEN** the save is rejected with `Invalid IPv4 CIDR notation: 10.0.0.0/33`

#### Scenario: Wrong family
- **WHEN** an IPv6 address is entered in the IPv4 field
- **THEN** the save is rejected

### Requirement: Unmanaged IPs Are Not Associated With Users
Associating a user with an IP SHALL do nothing and return no IP when the IP is unmanaged: no IP record, user link, policy or firewall action is created.

#### Scenario: Login from unmanaged IP
- **WHEN** a user authenticates from an unmanaged IP
- **THEN** login completes but no IP is linked and no firewall rule is applied

#### Scenario: Portal status from unmanaged IP
- **WHEN** the portal status endpoint is called from an unmanaged IP
- **THEN** it reports internet as not enabled

### Requirement: Unmanaged IPs Are Not Created on Lookup
Route lookup of an IP address by address SHALL create a missing record only when the address is managed.

#### Scenario: Unmanaged lookup
- **WHEN** an unknown unmanaged address is looked up
- **THEN** no record is created and nothing is returned

### Requirement: Scan Respects Managed Ranges
The network scan SHALL skip unmanaged addresses when persisting IPs, IP-MAC pairings and DHCP Leases.

#### Scenario: Unmanaged lease
- **WHEN** a DHCP Lease is for an unmanaged IP
- **THEN** it is not persisted
