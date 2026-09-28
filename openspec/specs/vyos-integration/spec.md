# vyos-integration Specification

## Purpose
Lets a VyOS router act as the source of DHCP Leases, DHCP Ranges, Pool Status and the IP-MAC Table, over the VyOS HTTP API. Both Address Families are covered by a single Endpoint.

## Requirements

### Requirement: VyOS Integration Registration
The system SHALL provide a VyOS Integration that can be assigned the DHCP Capability and the IP-MAC Capability, independently of each other.

#### Scenario: VyOS offers both Capabilities
- **WHEN** an admin views the available Integrations
- **THEN** VyOS is listed and offers the DHCP and IP-MAC Capabilities

#### Scenario: Only one Capability assigned
- **WHEN** an admin assigns DHCP to VyOS and IP-MAC to another Integration
- **THEN** DHCP data comes from VyOS and the IP-MAC Table comes from the other Integration

#### Scenario: Capability not assigned
- **WHEN** VyOS is not the assigned provider for a Capability
- **THEN** VyOS is never queried for that Capability

### Requirement: Endpoint Configuration
The VyOS Integration SHALL have a single Endpoint with a base URL, an API key, a `verify_ssl` toggle and an optional DHCP pool size. The API key SHALL be stored encrypted. The pool size, when set, MUST be an integer between 0 and 1,000,000.

#### Scenario: Invalid pool size
- **WHEN** an admin saves a pool size that is negative or above 1,000,000
- **THEN** the configuration is rejected with a validation error

#### Scenario: Invalid URL
- **WHEN** an admin saves an Endpoint that is not a URL
- **THEN** the configuration is rejected with a validation error

### Requirement: API Transport
Every request to VyOS SHALL be a form-encoded POST carrying the API key and a JSON operation. Configuration reads SHALL go to `/retrieve` and operational commands to `/show`. A non-2xx response, or a body reporting `success: false`, MUST raise an error containing the reason. TLS certificate verification SHALL follow `verify_ssl`.

#### Scenario: Configuration read
- **WHEN** the system reads VyOS configuration
- **THEN** it POSTs a `showConfig` operation to `/retrieve`

#### Scenario: API-level failure
- **WHEN** VyOS answers HTTP 200 with `success: false`
- **THEN** the request fails with the error message VyOS returned

#### Scenario: TLS verification disabled
- **WHEN** `verify_ssl` is off
- **THEN** requests skip certificate verification

### Requirement: DHCP Leases
The VyOS Integration SHALL supply DHCP Leases for both Address Families by parsing the tabular output of `show dhcp server leases` and `show dhcpv6 server leases`. Each lease SHALL carry IP, MAC (empty when VyOS does not report one), hostname and expiry. Looking up a lease by IP MUST compare normalised addresses.

#### Scenario: IPv4 and IPv6 combined
- **WHEN** both address families have leases
- **THEN** the lease list contains the IPv4 leases followed by the IPv6 leases

#### Scenario: One family fails
- **WHEN** fetching IPv6 leases fails
- **THEN** a warning is logged and the IPv4 leases are still returned

#### Scenario: Lookup with differently written IPv6
- **WHEN** a lease is looked up by an IPv6 address written in different case or compression from VyOS's
- **THEN** the matching lease is returned

### Requirement: DHCP Ranges
The VyOS Integration SHALL derive DHCP Ranges from the `shared-network-name` subnets in the `service dhcp-server` and `service dhcpv6-server` configuration. Each Range SHALL carry its shared-network name, subnet, start and stop addresses, and for IPv4 the subnet's default router as gateway. Each Range SHALL be enriched with total addresses, used addresses (DHCP Leases inside the Range) and utilisation.

#### Scenario: IPv4 Range utilisation
- **WHEN** a Range spans 100 addresses and 25 DHCP Leases fall inside it
- **THEN** the Range reports 100 total, 25 used and utilisation 0.25

#### Scenario: Subnet without a range
- **WHEN** a configured subnet has no range definition
- **THEN** it produces no DHCP Range

#### Scenario: No ranges configured
- **WHEN** VyOS has no DHCP configuration
- **THEN** no Ranges are returned and no error is raised

### Requirement: Pool Status
Pool Status for IPv4 SHALL be computed as the number of DHCP Leases against the configured pool size; utilisation is 0 when the pool size is 0 or unset. Pool Status for IPv6 SHALL be reported as empty.

#### Scenario: Pool size configured
- **WHEN** the pool size is 200 and 50 DHCP Leases exist
- **THEN** Pool Status reports 200 total, 50 used, 150 available and utilisation 0.25

#### Scenario: More leases than pool size
- **WHEN** the lease count exceeds the pool size
- **THEN** available is 0

### Requirement: IP-MAC Table
The VyOS Integration SHALL supply the IP-MAC Table from `show ip neighbors` and `show ipv6 neighbors`, keeping only rows with a valid MAC and lowercasing it. Duplicate IP and MAC pairs MUST be removed.

#### Scenario: Duplicate neighbour
- **WHEN** the same IP and MAC appear in both outputs
- **THEN** the table contains that pair once

#### Scenario: Neighbour without MAC
- **WHEN** a neighbour row has no link-layer address
- **THEN** it is omitted

### Requirement: Connection Test
The connection test SHALL run `show version` against the Endpoint using the supplied credentials and pass only if the request succeeds and VyOS does not report `success: false`.

#### Scenario: Bad API key
- **WHEN** VyOS rejects the API key
- **THEN** the test fails and reports the VyOS error

#### Scenario: Reachable and authenticated
- **WHEN** VyOS returns a successful response
- **THEN** the test passes with "Connected and authenticated successfully"
