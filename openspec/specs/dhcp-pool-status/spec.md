# dhcp-pool-status Specification

## Purpose
The admin dashboard shows the Pool Status of each DHCP Range from synchronised data without contacting the DHCP server, and reports very large IPv6 ranges accurately.

## Requirements

### Requirement: Dashboard Reads Synced Data
The admin dashboard SHALL build its DHCP Range list from stored range records of the DHCP Capability's active Integration and MUST NOT query the DHCP server during the request.

#### Scenario: Load dashboard
- **WHEN** an admin opens the dashboard
- **THEN** pools come from the stored records and no live DHCP call is made

#### Scenario: Other Integration's records
- **WHEN** stored ranges belong to an Integration that is not active for DHCP
- **THEN** they are not listed

### Requirement: Pool Row Contents
Each pool row SHALL provide a name (description, else interface), network (subnet, else prefix), used count, total and utilisation, defaulting missing values to 0.

#### Scenario: Missing values
- **WHEN** a record has no utilisation
- **THEN** utilisation is 0

### Requirement: Exact Totals
Range totals SHALL be exact decimal strings so an IPv6 /64 reports 2^64 addresses. The Cisco DHCP service SHALL compute IPv6 totals as 2^(128 - prefix length) for any prefix length and yield none for a malformed prefix.

#### Scenario: /64 pool
- **WHEN** a Cisco IPv6 pool has a /64 prefix
- **THEN** its total is 18446744073709551616

#### Scenario: Malformed prefix
- **WHEN** the prefix has no valid length or is not IPv6
- **THEN** no total is computed

### Requirement: Compact Total Display
Admin pages SHALL show totals below one million with digit grouping and larger totals in scientific notation to two significant digits.

#### Scenario: Large total
- **WHEN** the total is 2^64
- **THEN** it displays as `1.8e19`

#### Scenario: Small total
- **WHEN** the total is 155
- **THEN** it displays as `155`
