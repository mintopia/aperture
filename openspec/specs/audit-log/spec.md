# audit-log Specification

## Purpose
The Audit Log is the single activity store for Aperture. It records who did what to which entity, including autonomous network telemetry, and feeds both the admin Audit Log page and the live Recent Activity widget on the admin dashboard.

## Requirements

### Requirement: Audit Entry Recording
The system SHALL record each audit entry with an action, an optional polymorphic subject, related entity and actor, a process label (defaulting to `system`), optional metadata, and a severity of `info`, `warning` or `critical` (defaulting to `info`). Every entry SHALL be written through a single recording method.

#### Scenario: Actor-driven entry
- **WHEN** an admin performs an audited action such as changing a setting
- **THEN** an entry is stored with the action, the process, the client IP in metadata and severity `info`

#### Scenario: Entry without actor
- **WHEN** an entry is recorded with no subject, related entity or actor
- **THEN** it is stored with those fields empty

### Requirement: Live Broadcast of New Entries
The system SHALL broadcast each newly recorded audit entry on the private `admin.events` channel with its id, action, human-readable description, severity and ISO 8601 creation time. Only admins SHALL be authorised to listen on that channel.

#### Scenario: Entry recorded
- **WHEN** any audit entry is recorded
- **THEN** a broadcast carrying the pre-rendered description and severity is dispatched to `admin.events`

### Requirement: Network Telemetry Recorded as Audit Entries
The system SHALL record exactly three autonomous network events as actor-less audit entries with process `network`: a switch becoming unreachable (`switch.unreachable`, severity `critical`, subject the Switch), a bandwidth anomaly (`bandwidth.anomaly`, severity `warning`) and a DHCP Range reaching its utilisation threshold (`dhcp.threshold_reached`, severity `warning`). All other broadcast events SHALL be ignored, and a failure while recording SHALL be reported without interrupting the original event.

#### Scenario: Switch unreachable
- **WHEN** a switch is declared unreachable
- **THEN** a `critical` `switch.unreachable` entry is recorded with the switch as subject and the event payload as metadata

#### Scenario: Anomaly or threshold
- **WHEN** a bandwidth anomaly or DHCP pool threshold event fires
- **THEN** a `warning` entry is recorded with the event payload as metadata and no subject

#### Scenario: Unlisted event
- **WHEN** any other broadcastable event is dispatched, including the audit broadcast itself
- **THEN** no additional audit entry is recorded

### Requirement: Human-Readable Descriptions
The system SHALL render every audit entry as a one-line description from its action, subject, actor, related entity and metadata, using the same wording on the dashboard widget and the Audit Log page.

#### Scenario: Known action
- **WHEN** a `user.login` entry is described
- **THEN** the description reads "<subject> logged in"

#### Scenario: Telemetry action
- **WHEN** a `dhcp.threshold_reached` entry with pool and usage metadata is described
- **THEN** the description names the pool and its utilisation percentage

### Requirement: Audit Log Page
The system SHALL provide an admin-only Audit Log page listing entries paginated (20 per page by default), newest first. It SHALL allow filtering by action, process, subject type and an inclusive date range, and sorting by creation time, action, subject type or process in either direction. Filter options SHALL be the distinct values present in the log.

#### Scenario: Filter by process
- **WHEN** an admin selects a process filter
- **THEN** only entries with that process are listed and the filter persists across pages

#### Scenario: Date range is inclusive
- **WHEN** an admin sets a "to" date
- **THEN** entries created any time on that day are included

#### Scenario: Invalid sort
- **WHEN** the requested sort column is not sortable
- **THEN** the list falls back to creation time descending

#### Scenario: Non-admin
- **WHEN** a non-admin requests the page
- **THEN** access is denied

### Requirement: Entity Deep-Links
Each listed entry SHALL link its subject, related entity and actor to the matching admin page when the entity is a User, IP address, MAC address or Switch and still exists.

#### Scenario: Deleted entity
- **WHEN** an entry refers to an entity that no longer exists
- **THEN** no link is offered for it

### Requirement: Dashboard Recent Activity
The admin dashboard SHALL show the ten most recent audit entries in a Recent Activity widget with description and a severity indicator that is also exposed to assistive technology. New entries SHALL appear live via the `admin.events` broadcast, and a "View All" link SHALL lead to the Audit Log page.

#### Scenario: Live update
- **WHEN** a new audit entry is broadcast while an admin views the dashboard
- **THEN** it is added to the top of the widget without reload

#### Scenario: Severity shown
- **WHEN** an entry has severity `critical`
- **THEN** its row displays the critical severity indicator
