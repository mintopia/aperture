# capability-assignment Specification

## Purpose
Defines how Capabilities are bound to Integrations. Each Capability is served by at most one Integration at a time, chosen by an admin, and every Capability has a null provider so the rest of Aperture behaves safely when none is assigned.

## Requirements

### Requirement: Capability Set
The system SHALL recognise the Capabilities captive-portal, rate-limiting, dhcp, dns-filtering, ip-bandwidth, port-bandwidth, port-errors, ip-mac and port-mac, each with its own interface so any Integration offering it is substitutable for another. Authentication SHALL sit outside Capability assignment.

#### Scenario: Consumer depends on the Capability
- **WHEN** a feature needs DHCP Leases
- **THEN** it depends on the DHCP interface and does not know which Integration supplies it

### Requirement: Single Provider Per Capability
A Capability SHALL be assigned to at most one Integration. Assigning a Capability to an Integration MUST replace any previous assignment for that Capability.

#### Scenario: Reassign DHCP
- **WHEN** an admin assigns DHCP to Kea while OPNsense holds it
- **THEN** Kea becomes the only DHCP provider and OPNsense no longer holds it

### Requirement: Assignment Only For Offered Capabilities
An admin SHALL only be able to assign a Capability that the Integration declares. A request to assign any other Capability MUST be rejected with HTTP 422 and no change.

#### Scenario: Unsupported Capability
- **WHEN** an admin tries to assign dns-filtering to LibreNMS
- **THEN** the request fails with 422 and nothing is stored

### Requirement: Admin Toggle
Admins SHALL be able to switch each Capability on or off for an Integration from the Integrations pages. Switching off SHALL remove only that Integration's assignment.

#### Scenario: Turn off
- **WHEN** an admin turns off a Capability for the Integration that holds it
- **THEN** the Capability has no provider

#### Scenario: Turn off a non-holder
- **WHEN** an admin turns off a Capability for an Integration that does not hold it
- **THEN** the current holder keeps the Capability

### Requirement: Null Providers
Every Capability SHALL have a null provider returning empty results and taking no external action. The system MUST use it whenever the Capability is unassigned, or the holder cannot be built.

#### Scenario: Nothing assigned
- **WHEN** no Integration holds captive-portal
- **THEN** granting access performs no firewall call and does not fail

### Requirement: Gated Bindings
An Integration's implementation of a Capability SHALL be used only when that Integration holds the Capability. Integrations that do not hold a Capability MUST NOT be contacted for it, even when configured.

#### Scenario: Configured but unassigned
- **WHEN** VyOS is fully configured but holds no Capability
- **THEN** no request is sent to VyOS for DHCP or IP-MAC data

#### Scenario: Assignment lookup failure
- **WHEN** the assignment table cannot be read
- **THEN** the Integration is treated as not holding the Capability

### Requirement: Shared Integration Clients
An Integration that supplies several Capabilities SHALL share one client, and therefore one configuration, across them. Different Capabilities of one Integration MAY be assigned or unassigned independently.

#### Scenario: Prometheus bandwidth
- **WHEN** Prometheus holds ip-bandwidth and port-bandwidth
- **THEN** both use the same endpoint configuration

### Requirement: Declared Capabilities
The system SHALL offer these Capabilities per Integration: OPNsense (captive-portal, rate-limiting, dhcp), PiHole (dns-filtering), Prometheus (ip-bandwidth, port-bandwidth, port-errors), LibreNMS (ip-mac, port-mac), VyOS (dhcp, ip-mac), Kea (dhcp, ip-mac), Cisco (dhcp). Borealis SHALL offer none.

#### Scenario: Integrations page
- **WHEN** an admin opens the Integrations page
- **THEN** each Integration lists exactly its declared Capabilities with their current active state

### Requirement: Retired Capabilities
Host statistics, network inventory and traffic-monitor concepts and the per-IP received/sent counters SHALL NOT exist. Bandwidth data comes only from the ip-bandwidth Capability.

#### Scenario: Legacy assignment rows
- **WHEN** the system is migrated from the old capability names
- **THEN** user-bandwidth becomes ip-bandwidth and the unknown legacy assignments are removed
