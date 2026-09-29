# integration-configuration Specification

## Purpose
Lets admins configure every Integration from the admin UI rather than environment files. Each Integration declares its fields and validation once, and the admin pages, storage, secret handling and connection testing all follow that declaration.

## Requirements

### Requirement: Declared Fields
Each Integration SHALL declare its configurable fields with a type (text, url, password, toggle, select or select-remote), label, placeholder, help text and validation rule. The admin form SHALL render each field according to its type.

#### Scenario: Secret field
- **WHEN** an Integration declares a field of type password
- **THEN** it renders as a masked input

#### Scenario: Toggle field
- **WHEN** an Integration declares a field of type toggle
- **THEN** it renders as an on/off switch

### Requirement: Integration Index
The admin SHALL list every declared Integration with its enabled state, latest connection test health and Capabilities. An Integration counts as enabled if its `enabled` value is set, otherwise if an endpoint or switch is configured.

#### Scenario: Endpoint present
- **WHEN** an Integration has an endpoint saved and no `enabled` value
- **THEN** it is shown as enabled

#### Scenario: Never tested
- **WHEN** no connection test has been run for an Integration
- **THEN** its health is unknown

### Requirement: Saving Configuration
Saving SHALL validate submitted values using the Integration's declared rules, persist only declared fields, and record an audit entry. Integer-validated values SHALL be stored as integers. Unknown Integrations MUST return 404.

#### Scenario: Undeclared field
- **WHEN** a request contains a key the Integration does not declare
- **THEN** that key is ignored

#### Scenario: Validation failure
- **WHEN** a submitted value breaks its rule
- **THEN** nothing is saved and the error is shown on that field

#### Scenario: Unknown Integration
- **WHEN** an admin opens or saves `/admin/settings/integrations/nope`
- **THEN** the response is 404

### Requirement: Secret Storage
Fields of type password SHALL be stored encrypted and decrypted when read. They MUST never be stored as plain text.

#### Scenario: Stored value
- **WHEN** an API key is saved
- **THEN** the database holds an encrypted value and the service receives the decrypted key

### Requirement: Database Is The Source
Services SHALL read Integration configuration from the database when they are built, so a change made in the admin UI takes effect without redeploying.

#### Scenario: Endpoint changed in the admin UI
- **WHEN** an admin changes an Integration's endpoint
- **THEN** the next request to that Integration uses the new endpoint

### Requirement: Connection Testing
Each Integration SHALL provide a connection test. A test SHALL use the unsaved form values overlaid on stored values, so a change can be tested before saving. Only declared fields MUST be accepted. Each result SHALL be logged with method, URL, status and message, and the admin SHALL be able to see the recent history (latest 20).

#### Scenario: Test unsaved values
- **WHEN** an admin edits the endpoint and clicks test without saving
- **THEN** the test uses the edited endpoint and other stored values

#### Scenario: Integration without a tester
- **WHEN** a test is requested for an unknown service
- **THEN** the response is 404 with "Unknown service"

#### Scenario: History
- **WHEN** an admin opens the Integration page
- **THEN** the most recent test results are listed with their outcome

### Requirement: Remote Field Options
Fields that offer choices from the remote system (OPNsense shaper rules and zones, PiHole groups, Seatpicker events) SHALL load their options from the remote system using the current form values, and switch fields SHALL list the configured switches with a "None" option.

#### Scenario: Load PiHole groups
- **WHEN** an admin loads groups before saving credentials
- **THEN** the groups are fetched with the unsaved credentials

### Requirement: Borealis As An Integration
Borealis SHALL be configured like every other Integration (endpoint, client ID, secret, scope) with a connection test. It SHALL offer no Capabilities.

#### Scenario: Borealis listed
- **WHEN** an admin opens the Integrations page
- **THEN** Borealis appears with no Capabilities and can be configured and tested
