# ip-policy Specification

## Purpose
Keeps internet access, rate limiting and DNS filtering consistent across users, IP addresses and their external backends. The local database is the source of truth: changing a policy flag on a user or IP address propagates automatically to the firewall and DNS filter, and reconciliation can re-sync a backend from local state.

## Requirements

### Requirement: Policy Flags
Each user SHALL carry `internet_enabled`, `rate_limit_enabled`, `dns_filtering_enabled` and `internet_blocked` flags, and each IP address SHALL carry `internet_enabled`, `rate_limit_enabled` and `dns_filtering_enabled` flags. The flags SHALL default to false.

#### Scenario: New IP address
- **WHEN** an IP address is first recorded
- **THEN** all three of its policy flags are false

### Requirement: User Policy Applied To IP
Applying a user's policy to an IP address SHALL set the IP's three flags to the user's values, except that `internet_enabled` MUST be false whenever the user has `internet_blocked`. The IP SHALL be saved only if a flag actually changed.

#### Scenario: Blocked user
- **WHEN** a user with `internet_enabled` and `internet_blocked` both true has policy applied to an IP
- **THEN** the IP's `internet_enabled` is false

#### Scenario: Already matching
- **WHEN** the IP already matches the user's policy
- **THEN** the IP is not saved and no backend job is dispatched

### Requirement: Association Applies Policy
Associating an IP address with a user SHALL apply that user's policy to the IP. Only IP addresses inside managed network ranges MAY be associated.

#### Scenario: Portal visit
- **WHEN** a user visits the portal from a managed IP address
- **THEN** the IP is associated with the user and takes the user's policy flags

#### Scenario: Unmanaged address
- **WHEN** the client IP is outside every managed range
- **THEN** no IP address is created or associated

### Requirement: Disassociation Resets Policy
Removing the association between a user and an IP address SHALL reset all three flags on that IP to false.

#### Scenario: User released
- **WHEN** a user-IP association is deleted
- **THEN** the IP's internet, rate limit and DNS filtering flags become false and the backends are updated

### Requirement: User Change Propagates To IPs
When any of a user's four policy flags changes, the system SHALL queue a policy sync for every IP address associated with that user. Changes to other user attributes MUST NOT queue any sync.

#### Scenario: Admin enables rate limiting for a user
- **WHEN** a user's `rate_limit_enabled` flips to true
- **THEN** each associated IP gets a queued policy sync that applies the new flag

#### Scenario: Unrelated edit
- **WHEN** a user's nickname changes
- **THEN** no policy sync is queued

### Requirement: IP Change Propagates To Backends
When an IP address's flag changes, the system SHALL queue exactly the backend action for that flag: a firewall internet change, a firewall rate-limit change, or a DNS filtering change. Flags that did not change MUST NOT trigger jobs. A null `internet_enabled` MUST be treated as no access.

#### Scenario: Two flags change in one save
- **WHEN** `internet_enabled` and `dns_filtering_enabled` change together
- **THEN** one firewall internet job and one DNS filtering job are queued and no rate-limit job

#### Scenario: No change
- **WHEN** an IP is saved with no policy flag changes
- **THEN** no backend job is queued

### Requirement: Reconciliation
The `aperture:reconcile {target}` command SHALL accept `internet`, `rate-limits` or `dns-filtering`, reconcile that backend against local state and report the added, removed, unchanged and error counts. `--dry-run` MUST report without changing the backend. An unknown target SHALL fail with the list of valid targets.

#### Scenario: Dry run
- **WHEN** an admin runs `aperture:reconcile internet --dry-run`
- **THEN** the differences are reported, the backend is unchanged and the output states that no changes were applied

#### Scenario: Unknown target
- **WHEN** an admin runs `aperture:reconcile foo`
- **THEN** the command fails and lists the valid targets

### Requirement: Blocked User Experience
When a user is `internet_blocked`, the portal dashboard SHALL receive an internet-blocked indicator and the message from the `portal.blocked_message` setting.

#### Scenario: Blocked user opens the dashboard
- **WHEN** a blocked user opens the portal dashboard
- **THEN** the dashboard receives the blocked indicator and the configured message
