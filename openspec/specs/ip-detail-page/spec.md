# ip-detail-page Specification

## Purpose
Gives admins a single page for one IP address: its access, rate limit and DNS filter state and controls, the device and switch port behind it, who uses it, and its bandwidth and history.

## Requirements

### Requirement: Access Controls
The page header SHALL offer three actions whose labels describe what clicking does: Grant/Revoke Access, Enable/Disable Rate Limit and Enable/Disable DNS Filter. Grant/Revoke Access MUST ask for confirmation, naming the number of associated users when there are any. Rate limit and DNS filter toggles SHALL apply immediately without confirmation. Each action SHALL be audit-logged.

#### Scenario: Revoke access
- **WHEN** an admin clicks Revoke Access on an IP with internet enabled and confirms
- **THEN** the IP's `internet_enabled` becomes false and the page shows the updated state

#### Scenario: Toggle rate limit
- **WHEN** an admin clicks Enable Rate Limit
- **THEN** `rate_limit_enabled` is set with no confirmation dialog

#### Scenario: Toggle DNS filter
- **WHEN** an admin posts `filter` to `admin/ips/{ip}/dns-filter`
- **THEN** `dns_filtering_enabled` is updated and the change is audit-logged

#### Scenario: Missing value
- **WHEN** the toggle request omits its boolean
- **THEN** it is rejected with a validation error

### Requirement: Metadata Strip
The page SHALL show the IP's current MAC address, the switch, the port and the comment, using a dash for missing values. The switch and port SHALL link to the switch page and port page when the IP is found on a port.

#### Scenario: IP on a known port
- **WHEN** the IP's MAC is seen on a switch port
- **THEN** switch and port are links to their admin pages

#### Scenario: IP not on a port
- **WHEN** no port is known for the IP
- **THEN** switch and port show a dash

### Requirement: Associated Users
The page SHALL list users associated with the IP with nickname and last-seen time, each linking to the user's admin page, with an empty message when there are none.

#### Scenario: No users
- **WHEN** no user is associated
- **THEN** "No associated users" is shown

### Requirement: Internet Bandwidth
The page SHALL chart the IP's download and upload with a selectable range of 1h, 24h, 4d or 7d (default 24h), show Down and Up totals for the range, refresh periodically, and show a non-blocking error message if loading fails.

#### Scenario: Change range
- **WHEN** an admin selects 7d
- **THEN** the chart and totals reload for seven days

#### Scenario: Load failure
- **WHEN** the bandwidth request fails
- **THEN** "Failed to load bandwidth data" is shown and the rest of the page remains usable

### Requirement: Port Metrics
When the IP is on a known switch port, the page SHALL show the port's 24-hour bandwidth and error charts. These sections MUST be hidden when no port is known. When metrics are not available, the page SHALL say Prometheus is not configured rather than failing.

#### Scenario: Metrics backend absent
- **WHEN** no metrics provider is assigned
- **THEN** the port sections state that Prometheus is not configured

### Requirement: Two-Column Layout
Users SHALL sit beside internet and port metrics in two equal columns from the large breakpoint upward, stacked in a single column below it.

#### Scenario: Narrow viewport
- **WHEN** the viewport is narrower than the large breakpoint
- **THEN** the sections stack in one column

### Requirement: History Sections
Below the columns the page SHALL show MAC address history (address, source, last seen, user; each linking to the MAC page), DHCP leases (MAC, hostname, expiry, last updated) and the IP's audit log (action, process, timestamp), each with an empty message.

#### Scenario: No leases
- **WHEN** the IP has no DHCP Lease
- **THEN** "No DHCP leases" is shown
