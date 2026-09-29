# ipv6-tracking Specification

## Purpose
Browsers on the portal prove their IPv6 address using a signed token so Aperture can register it, link it to the device's MAC and user, and apply the same access as the device's IPv4 address.

## Requirements

### Requirement: IPv6 Detection Settings
Admins SHALL configure the detection endpoint, JWKS URL, and optional JWT audience and issuer at `/admin/settings/ipv6-detection`.

#### Scenario: Save settings
- **WHEN** an admin saves the settings
- **THEN** they are stored and a success message is shown

#### Scenario: Not configured
- **WHEN** no detection endpoint is set
- **THEN** the portal and dashboard perform no IPv6 detection

### Requirement: Token Submission
`POST /ipv6` SHALL accept a token from the signed-in user. It MUST return 503 when no JWKS URL is configured and 422 when the token is invalid.

#### Scenario: Not configured
- **WHEN** a token is posted and no JWKS URL is configured
- **THEN** the response is 503

#### Scenario: Invalid token
- **WHEN** the token fails verification
- **THEN** the response is 422 and no IP is registered

### Requirement: Token Verification
The system SHALL verify the RS256 token against keys fetched from the JWKS URL (cached for one hour), enforce the configured audience and issuer when set, and take the IPv6 address from the `sub` claim, which MUST be a valid IPv6 address.

#### Scenario: Valid token
- **WHEN** the signature verifies and `sub` is an IPv6 address
- **THEN** that address is extracted

#### Scenario: Audience mismatch
- **WHEN** an audience is configured and the token's `aud` differs
- **THEN** verification fails

#### Scenario: Non-IPv6 subject
- **WHEN** `sub` is not an IPv6 address
- **THEN** verification fails

### Requirement: Registration and MAC Linkage
A verified IPv6 address SHALL be associated with the user if managed. It SHALL then be paired with the current MAC of the request's client IPv4 address using source `ipv6_detection`, refreshing `last_seen_at` if already paired.

#### Scenario: MAC known for IPv4
- **WHEN** the client IPv4 has a known MAC
- **THEN** the IPv6 is paired with that MAC and an `ip_mac.linked` audit entry is recorded by process `ipv6_detection`

#### Scenario: MAC unknown
- **WHEN** the client IPv4 has no MAC
- **THEN** the IPv6 is registered without a pairing

#### Scenario: Response
- **WHEN** registration completes
- **THEN** the response contains the IPv6 address and whether internet is enabled for it

### Requirement: Portal Detection Retries
The captive portal page SHALL attempt IPv6 detection up to three times, one second apart, once internet is enabled, and then redirect to the dashboard regardless of outcome.

#### Scenario: Detection fails
- **WHEN** all three attempts fail
- **THEN** the user is still redirected to the dashboard

### Requirement: Dashboard Live Update
While the dashboard is open the browser SHALL repeat detection every two minutes and, on success, update the displayed IPv6 address and internet status without a page refresh. The dashboard SHALL initially show the most recently seen IPv6 address paired with the device's current MAC.

#### Scenario: Detected while open
- **WHEN** detection succeeds on the dashboard
- **THEN** the connection strip shows the new IPv6 and status

#### Scenario: Detection disabled
- **WHEN** no endpoint is configured
- **THEN** no detection requests are made

### Requirement: Lowercase Canonical Form
IPv6 addresses SHALL be stored and compared in lowercase, IPv4 unchanged, and there MUST be at most one IP address record per address. Addresses sent to and read from OPNsense captive portal and rate limiter, and address filters and lookups in admin and API endpoints, SHALL use that canonical form.

#### Scenario: Uppercase input
- **WHEN** an IPv6 address is supplied in uppercase
- **THEN** it matches and is stored as its lowercase form

#### Scenario: Duplicate prevention
- **WHEN** two records would have the same address
- **THEN** the unique index on address rejects the second
