# switchport-detail-page Specification

## Purpose
Gives admins an operational view of one switch port for triage: connected devices first, live telemetry alongside, with minimal, confirmed controls.

## Requirements

### Requirement: Header Actions
The header SHALL show the port name and only two actions, Refresh and Shut/Unshut. Port status MUST appear in the metadata strip and not in the header.

#### Scenario: Refresh
- **WHEN** an admin clicks Refresh
- **THEN** the port is synced from the switch, the button shows a loading state while in flight, and the page updates in place

### Requirement: Confirmed Shut/Unshut
The toggle SHALL be labelled Shut when the port is administratively up and Unshut when it is down. Every state change MUST require confirmation, and the confirmation SHALL summarise the connected devices. The button SHALL be disabled while the request is in flight.

#### Scenario: Shut an up port
- **WHEN** an admin clicks Shut
- **THEN** a "Shut Down Port?" dialog warns that connected devices lose connectivity and lists them

#### Scenario: Confirm
- **WHEN** the admin confirms
- **THEN** the shutdown request is sent, the page shows the port as down without navigating away, and the toggle reads Unshut

### Requirement: Section Layout
On extra-large viewports the page SHALL use two columns with the left about 1.4 times the right: Connected Devices then Interface Output on the left; Bandwidth, Interface Errors and Running Config on the right. Below that breakpoint the sections stack in the same order.

#### Scenario: Desktop
- **WHEN** the viewport is extra large
- **THEN** devices and interface output are on the left, telemetry and running config on the right

### Requirement: Connected Devices
The page SHALL list devices learned on the port with a count in the heading, deduplicated by MAC, with IPv4 and IPv6 addresses linking to their IP pages.

#### Scenario: No devices
- **WHEN** no MAC is on the port
- **THEN** an empty message is shown

### Requirement: Telemetry And Output States
Bandwidth and error charts SHALL cover the last 24 hours and show a non-blocking fallback when no metrics backend is available. Interface Output and Running Config SHALL show "No interface output available" and "No running config available" when absent.

#### Scenario: Metrics unavailable
- **WHEN** no metrics provider is assigned
- **THEN** the errors section shows a fallback message and the rest of the page still renders

### Requirement: Navigation And Freshness
The page SHALL link to the previous and next port on the same switch when they exist and show how long ago it was last updated. It SHALL refresh port, devices and metrics without a full reload when a port-state or switch-sync event arrives for this port or switch.

#### Scenario: Port state changes elsewhere
- **WHEN** a state-changed event for this port is broadcast
- **THEN** the page reloads its port, device and metric data and resets the last-updated time
