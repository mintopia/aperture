# kea-integration Specification

## Purpose
Standalone Kea DHCP Integration offering the DHCP and IP-MAC Capabilities over IPv4 and IPv6 Endpoints.

## Requirements
### Requirement: Kea Integration Registration
The system SHALL provide a standalone Kea Integration, independent of the OPNsense Integration, that can be assigned the DHCP Capability and the IP-MAC Capability.

#### Scenario: Kea offers both Capabilities
- **WHEN** an admin views the available Integrations
- **THEN** Kea is listed and offers the DHCP and IP-MAC Capabilities

#### Scenario: Capabilities assigned independently
- **WHEN** an admin assigns DHCP to Kea and IP-MAC to another Integration
- **THEN** DHCP data comes from Kea and the IP-MAC Table comes from the other Integration

### Requirement: Endpoint Configuration
The Kea Integration SHALL support an IPv4 Endpoint and an IPv6 Endpoint, each with its own URL and optional HTTP Basic username and password. Each Endpoint SHALL be optional, but at least one MUST be configured. A single `verify_ssl` setting SHALL apply to both Endpoints. Passwords SHALL be stored encrypted.

#### Scenario: IPv4 only
- **WHEN** only the IPv4 Endpoint URL is set
- **THEN** the configuration is valid and only IPv4 data is fetched

#### Scenario: IPv6 only
- **WHEN** only the IPv6 Endpoint URL is set
- **THEN** the configuration is valid and only IPv6 data is fetched

#### Scenario: No Endpoint
- **WHEN** neither Endpoint URL is set
- **THEN** the configuration is rejected with a validation error

#### Scenario: Partial credentials
- **WHEN** an Endpoint has a username without a password, or a password without a username
- **THEN** the configuration is rejected with a validation error for that Endpoint

#### Scenario: No credentials
- **WHEN** an Endpoint has neither username nor password
- **THEN** requests to that Endpoint are sent without an Authorization header

#### Scenario: Credentials set
- **WHEN** an Endpoint has a username and password
- **THEN** every request to that Endpoint uses HTTP Basic auth with those credentials

#### Scenario: TLS verification disabled
- **WHEN** `verify_ssl` is off
- **THEN** requests to both Endpoints skip certificate verification

### Requirement: Kea Command Transport
Every command sent to a Kea Endpoint SHALL include the `service` parameter (`dhcp4` for the IPv4 Endpoint, `dhcp6` for the IPv6 Endpoint), so that an Endpoint URL may point at either a Kea daemon or a Kea Control Agent.

#### Scenario: Service parameter on IPv4
- **WHEN** any command is sent to the IPv4 Endpoint
- **THEN** the request body contains `"service": ["dhcp4"]`

#### Scenario: Kea empty result
- **WHEN** Kea responds with result code 3 (empty)
- **THEN** it is treated as an empty result, not an error

### Requirement: Connection Test
The connection test SHALL send `list-commands` to each configured Endpoint and pass only if every configured Endpoint responds, accepts authentication, and lists `config-get` plus `lease4-get-page` (IPv4) or `lease6-get-page` (IPv6). Results SHALL be reported per Endpoint with the failure reason.

#### Scenario: All configured Endpoints healthy
- **WHEN** each configured Endpoint returns a command list containing the required commands
- **THEN** the test passes

#### Scenario: Authentication failure
- **WHEN** an Endpoint returns HTTP 401
- **THEN** the test fails, naming that Endpoint and an authentication failure

#### Scenario: Network failure
- **WHEN** an Endpoint cannot be reached
- **THEN** the test fails, naming that Endpoint and a connection failure

#### Scenario: Lease hook missing
- **WHEN** an Endpoint's command list lacks the lease paging command
- **THEN** the test fails, naming that Endpoint and the missing `lease_cmds` hook

#### Scenario: Unconfigured Endpoint skipped
- **WHEN** only the IPv4 Endpoint is configured
- **THEN** no request is made to an IPv6 Endpoint

### Requirement: DHCP Lease Sync
When Kea holds the DHCP Capability, the DHCP sync SHALL fetch leases from each configured Endpoint using `lease4-get-page` / `lease6-get-page` with a page limit of 1000, continuing until an empty page. Only leases in state 0 whose `cltt + valid-lft` is in the future SHALL be treated as DHCP Leases.

#### Scenario: Multiple pages
- **WHEN** Kea holds 2,500 active IPv4 leases
- **THEN** three pages are requested and all 2,500 DHCP Leases are stored

#### Scenario: Inactive leases excluded
- **WHEN** Kea returns leases in state 1 (declined), state 2 (expired-reclaimed), or state 0 with an expiry in the past
- **THEN** none of them are stored as DHCP Leases

#### Scenario: Lease fields mapped
- **WHEN** an active lease is synced
- **THEN** its IP address, MAC, hostname and expiry are stored on the DHCP Lease

### Requirement: IPv6 MAC Derivation
For IPv6 leases, the MAC SHALL be taken from `hw-address` when present; otherwise it SHALL be extracted from the DUID when the DUID is DUID-LLT (type 1) or DUID-LL (type 3) with hardware type 1 (Ethernet); otherwise the MAC SHALL be null.

#### Scenario: hw-address present
- **WHEN** an IPv6 lease includes `hw-address`
- **THEN** that value is used as the MAC

#### Scenario: DUID-LLT
- **WHEN** an IPv6 lease has no `hw-address` and a DUID of type 1 with hardware type 1
- **THEN** the MAC is the trailing six bytes of the DUID

#### Scenario: DUID-LL
- **WHEN** an IPv6 lease has no `hw-address` and a DUID of type 3 with hardware type 1
- **THEN** the MAC is the trailing six bytes of the DUID

#### Scenario: Unusable DUID
- **WHEN** an IPv6 lease has no `hw-address` and a DUID of type 2 or 4, or a non-Ethernet hardware type
- **THEN** the MAC is null

### Requirement: DHCP Range Sync
DHCP Ranges SHALL be derived from the pools in `config-get` output, including subnets at the top level and inside `shared-networks`. Pools in both `start - end` and CIDR notation SHALL be supported. Prefix-delegation pools SHALL be ignored. Each DHCP Range SHALL be labelled with the pool's `user-context.name`, else the subnet's `user-context.name`, else `<subnet CIDR> (<start>–<end>)`.

#### Scenario: Shared networks
- **WHEN** a subnet with a pool is defined inside a shared network
- **THEN** its pool is synced as a DHCP Range

#### Scenario: CIDR pool
- **WHEN** a pool is written as `10.0.1.0/25`
- **THEN** the DHCP Range spans 10.0.1.0 to 10.0.1.127

#### Scenario: Prefix delegation ignored
- **WHEN** an IPv6 subnet defines `pd-pools`
- **THEN** no DHCP Range is created for them

#### Scenario: Label fallback
- **WHEN** neither the pool nor the subnet has a `user-context.name`
- **THEN** the DHCP Range is labelled with the subnet CIDR and pool bounds

### Requirement: Pool Status
Pool Status for each DHCP Range SHALL be computed from the Range's size and the number of active DHCP Leases within it. IPv6 Range totals SHALL be capped to avoid overflow.

#### Scenario: IPv4 utilisation
- **WHEN** a 100-address IPv4 Range contains 40 active DHCP Leases
- **THEN** its Pool Status is 40 used of 100

#### Scenario: Large IPv6 pool
- **WHEN** an IPv6 Range spans a /64
- **THEN** its total is capped and no overflow occurs

### Requirement: Independent Address Family Sync
Each Address Family SHALL sync independently. A failure fetching one family SHALL NOT affect the other; the failed family SHALL retain its last-known data and report its own failure in its sync state.

#### Scenario: IPv6 fails, IPv4 succeeds
- **WHEN** the IPv4 Endpoint responds normally and the IPv6 Endpoint times out
- **THEN** IPv4 DHCP data is updated, IPv6 DHCP data is unchanged, and only the IPv6 sync state records a failure

#### Scenario: IPv4 fails, IPv6 succeeds
- **WHEN** the IPv4 Endpoint returns HTTP 401 and the IPv6 Endpoint responds normally
- **THEN** IPv6 DHCP data is updated, IPv4 DHCP data is unchanged, and only the IPv4 sync state records a failure

### Requirement: Live IP-MAC Lookup
Looking up a DHCP Lease by IP SHALL query the Endpoint matching the address's family live, using `lease4-get` or `lease6-get` (type `IA_NA`) by `ip-address`, and apply the same active-lease filter and MAC derivation as the sync.

#### Scenario: Active lease found
- **WHEN** an IPv4 address has an active lease in Kea
- **THEN** the lookup returns that lease and its MAC

#### Scenario: Inactive or missing lease
- **WHEN** Kea has no lease, or only a declined or expired lease, for the address
- **THEN** the lookup returns no lease

#### Scenario: Family not configured
- **WHEN** an IPv6 address is looked up and no IPv6 Endpoint is configured
- **THEN** the lookup returns no lease without making a request or raising an error

#### Scenario: Transport error
- **WHEN** the Endpoint is unreachable during a lookup
- **THEN** the lookup returns no lease and MAC resolution falls back to the IP-MAC Capability

### Requirement: IP-MAC Table from Leases
When Kea holds the IP-MAC Capability, the IP-MAC Table SHALL contain one entry per active DHCP Lease, across both configured Address Families, that has a resolvable MAC.

#### Scenario: Dual-stack table
- **WHEN** both Endpoints are configured and hold active leases
- **THEN** the IP-MAC Table contains IPv4 and IPv6 entries

#### Scenario: Lease without MAC
- **WHEN** an IPv6 lease has no resolvable MAC
- **THEN** it is not included in the IP-MAC Table
