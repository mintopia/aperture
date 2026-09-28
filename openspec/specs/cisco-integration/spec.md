# cisco-integration Specification

## Purpose
Lets a Cisco IOS switch act as the DHCP server of record: its bindings, pools and exclusions become DHCP Leases, DHCP Ranges and Pool Status for both Address Families, and DHCP snooping on managed Cisco switches feeds observed IP-to-MAC mappings.

## Requirements

### Requirement: Cisco Integration Registration
The system SHALL provide a Cisco Integration that can be assigned the DHCP Capability. It SHALL use an already-configured switch as its transport, selected by `switch_id`, and reach it through the SSH proxy.

#### Scenario: Cisco offers DHCP
- **WHEN** an admin views the available Integrations
- **THEN** Cisco is listed and offers the DHCP Capability only

#### Scenario: No switch selected
- **WHEN** the Cisco Integration holds the DHCP Capability but no switch is configured, or the switch no longer exists
- **THEN** the system falls back to the previously bound DHCP provider instead of failing

### Requirement: Configuration
The Cisco Integration SHALL accept a `switch_id` that references an existing switch, an optional pool size override (integer, 0 or more) and a `ipv6_enabled` toggle that defaults to on.

#### Scenario: Unknown switch
- **WHEN** an admin saves a `switch_id` that does not exist
- **THEN** the configuration is rejected with a validation error

#### Scenario: IPv6 disabled
- **WHEN** `ipv6_enabled` is off
- **THEN** no IPv6 commands are sent to the switch and no IPv6 data is returned

### Requirement: Lease and Range Snapshot
The Cisco Integration SHALL collect DHCP data in a single SSH session by running the IPv4 binding, pool and running-config commands (plus the IPv6 equivalents when enabled), and SHALL close the session afterwards. The result SHALL be reused until explicitly reset.

#### Scenario: One session per snapshot
- **WHEN** leases, Ranges and Pool Status are all requested
- **THEN** the switch is queried once and the session is disconnected

#### Scenario: Reset
- **WHEN** the snapshot is reset and data is requested again
- **THEN** the switch is queried again

### Requirement: IPv6 Failure Isolation
A failure fetching IPv6 DHCP data, including error output from the switch, SHALL NOT prevent IPv4 data from being returned. The fetch status SHALL report per Address Family whether the fetch succeeded.

#### Scenario: IPv6 not supported by IOS
- **WHEN** the switch answers the IPv6 binding command with an error
- **THEN** a warning is logged, IPv4 data is returned and the IPv6 fetch status is false

#### Scenario: Both succeed
- **WHEN** both fetches succeed
- **THEN** both fetch statuses are true

### Requirement: DHCP Leases
The Cisco Integration SHALL turn IPv4 and IPv6 bindings into DHCP Leases with IP, expiry and MAC. The MAC MAY be absent for DHCPv6 bindings. Hostname SHALL be empty.

#### Scenario: IPv6 binding without MAC
- **WHEN** a DHCPv6 binding has no MAC
- **THEN** the DHCP Lease is returned with a null MAC

### Requirement: DHCP Ranges
IPv4 DHCP Ranges SHALL be the usable host range of each DHCP pool (excluding the network and broadcast addresses) minus any overlapping `ip dhcp excluded-address` entries, so a pool split by exclusions yields several Ranges. Each Range SHALL report total addresses, used addresses (bindings inside it) and utilisation. IPv6 pools SHALL be reported as prefix-only Ranges with usage counted by prefix match.

#### Scenario: Exclusion in the middle of a pool
- **WHEN** a /24 pool has one excluded block inside it
- **THEN** two Ranges are returned that omit the excluded block

#### Scenario: Compressed and expanded IPv6 notation
- **WHEN** a binding is written in a different notation from the pool prefix
- **THEN** it is still counted within the prefix

#### Scenario: Unparsable IPv6 prefix
- **WHEN** an IPv6 pool has no valid prefix
- **THEN** its usage and utilisation are reported as unknown, not zero

### Requirement: Pool Status
IPv4 Pool Status SHALL sum leased addresses across pools. Total SHALL be the pool size override when it is greater than 0, otherwise the sum of pool totals. IPv6 Pool Status SHALL be reported as empty.

#### Scenario: Override set
- **WHEN** the pool size override is 500
- **THEN** total is 500 regardless of the switch's pool totals

#### Scenario: No override
- **WHEN** the override is 0
- **THEN** total is derived from the switch's pool statistics

### Requirement: Scheduled DHCP Sync
DHCP data from the assigned provider SHALL be polled every minute by a queued job and persisted to the database, and admin DHCP views SHALL read the persisted data. An administrator MUST be able to run the same sync once on demand with `dhcp:sync`. A sync that returns an empty dataset for a family with existing data SHALL NOT delete that data until three consecutive empty results have occurred.

#### Scenario: No provider assigned
- **WHEN** no DHCP Capability is assigned
- **THEN** the sync does nothing

#### Scenario: Transient empty result
- **WHEN** one sync returns no leases after earlier syncs returned leases
- **THEN** the stored leases are kept

#### Scenario: Failed fetch
- **WHEN** a family's fetch failed
- **THEN** stored data for that family is untouched and the attempt is recorded

#### Scenario: Threshold crossing
- **WHEN** pool utilisation for an Address Family rises from below 80% to 80% or more
- **THEN** a pool-threshold event is dispatched once for that family

### Requirement: DHCP Snooping Observations
When a managed Cisco switch is synced, the system SHALL read `show ip dhcp snooping binding` and persist each binding as an observation keyed by switch, VLAN, IP and MAC. Observations for that switch no longer present SHALL be deleted. A snooping failure MUST NOT abort the port sync.

#### Scenario: Binding with a lease time
- **WHEN** a binding has a lease of 3600 seconds
- **THEN** its observation expires an hour after the sync

#### Scenario: Binding disappears
- **WHEN** a binding is missing from the next sync of the same switch
- **THEN** its observation is removed

#### Scenario: Snooping command fails
- **WHEN** the snooping command errors
- **THEN** a warning is logged and ports and MACs still sync

### Requirement: Connection Test
The connection test SHALL fail with a specific message if no switch is selected or the switch cannot be found. Otherwise it SHALL run `show ip dhcp pool` through the SSH proxy and pass only if the output is non-empty and not an invalid-input error.

#### Scenario: Pool command rejected
- **WHEN** the switch answers `% Invalid input`
- **THEN** the test fails and reports that the DHCP pool query failed

#### Scenario: Success
- **WHEN** the switch returns pool output
- **THEN** the test passes and shows the output
