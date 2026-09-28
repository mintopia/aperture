# bandwidth-monitoring Specification

## Purpose
Admins can view traffic history per user and per IP address and are warned when the bandwidth metric feed is unhealthy, so empty graphs are not mistaken for idle networks.

## Requirements

### Requirement: Selectable Ranges
The bandwidth endpoints for the dashboard, users and IP addresses SHALL accept only the ranges `1h`, `24h`, `4d` and `7d`, and the admin pages SHALL offer them labelled 1H, 24H, 4D and 7D.

#### Scenario: Supported range
- **WHEN** an admin requests range `7d`
- **THEN** the data is returned

#### Scenario: Unsupported range
- **WHEN** an admin requests range `72h`
- **THEN** the request is rejected with a validation error

### Requirement: IP Bandwidth Graph
The IP address detail page SHALL show download and upload series for the selected range, defaulting to 24h, with total downloaded and uploaded, loading and empty states.

#### Scenario: Change range
- **WHEN** an admin selects another range
- **THEN** the graph reloads for that range

### Requirement: Bits Per Second Values
Bandwidth series SHALL be reported in bits per second as fractional values, so low rates are not rounded to zero.

#### Scenario: Low rate
- **WHEN** the measured rate is under one byte per second
- **THEN** the series value is non-zero

### Requirement: Visible Errors
When bandwidth data cannot be fetched, the dashboard, user and IP pages SHALL display the error in an element announced to assistive technology.

#### Scenario: Fetch failure
- **WHEN** the bandwidth request fails
- **THEN** an error message is shown instead of an empty graph

### Requirement: Data Freshness Check
The Prometheus connection test SHALL, after confirming reachability, query the received-bytes metric (configurable, default `ntopng_host_bytes_rcvd`) and MUST fail when the query errors, no series exist, or the newest sample is older than 15 minutes, stating the sample age.

#### Scenario: Stale feed
- **WHEN** Prometheus is reachable but the newest sample is 20 minutes old
- **THEN** the test fails with a message that the metric is stale

#### Scenario: No data
- **WHEN** the metric has no series
- **THEN** the test fails

#### Scenario: Fresh feed
- **WHEN** the newest sample is under 15 minutes old
- **THEN** the test succeeds
