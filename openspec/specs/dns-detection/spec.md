# dns-detection Specification

## Purpose
Warns portal users when their device is not using the event DNS servers, which would bypass the LAN cache. The check runs in the user's browser because only the browser's own DNS resolution shows which resolver it uses.

## Requirements

### Requirement: DNS Detection Settings
Admins SHALL configure a check URL and warning message at `/admin/settings/dns-detection`. When the check URL is empty the feature MUST be disabled.

#### Scenario: Save
- **WHEN** an admin saves a valid URL and message
- **THEN** both are stored and a success message is shown

#### Scenario: Clear
- **WHEN** an admin saves an empty URL
- **THEN** DNS detection is disabled

### Requirement: Check URL Validation
The check URL SHALL contain the `{uuid}` placeholder, be a valid URL and be at most 500 characters. The warning message SHALL be at most 500 characters.

#### Scenario: Missing placeholder
- **WHEN** the URL has no `{uuid}`
- **THEN** the save is rejected with "The URL must contain the {uuid} placeholder."

#### Scenario: Message too long
- **WHEN** the message exceeds 500 characters
- **THEN** the save is rejected

### Requirement: Dashboard Payload
The portal dashboard SHALL receive the check URL and warning message only when a check URL is configured, using a default warning message when none is set.

#### Scenario: Configured
- **WHEN** a check URL is set and no message is set
- **THEN** the default message "Your device is not using the event DNS servers. Please update your DNS settings." is provided

#### Scenario: Not configured
- **WHEN** no check URL is set
- **THEN** no DNS detection data is provided and no warning block renders

### Requirement: Browser-Side Check
The dashboard SHALL replace `{uuid}` with a fresh random UUID, fetch the URL from the browser, and interpret the JSON `server` field: `event` passes, `online` fails.

#### Scenario: Event DNS
- **WHEN** the response is `{"server": "event"}`
- **THEN** no warning is shown and checking stops

#### Scenario: Public DNS
- **WHEN** the response is `{"server": "online"}`
- **THEN** the admin's warning message is shown

#### Scenario: Check error
- **WHEN** the request fails or the response is not valid JSON
- **THEN** it is treated as a pass and no warning is shown

### Requirement: Re-check
After a failed check the dashboard SHALL re-check every 60 seconds, provide a manual refresh control, and stop timers when the block is removed.

#### Scenario: Automatic retry
- **WHEN** a check has failed
- **THEN** it is repeated after 60 seconds

#### Scenario: Manual refresh
- **WHEN** the user activates the refresh control
- **THEN** the check runs immediately
