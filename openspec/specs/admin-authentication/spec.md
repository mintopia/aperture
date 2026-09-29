# admin-authentication Specification

## Purpose
Lets administrators sign in to the admin area with an email and password or a passkey, independently of the Borealis attendee sign-in.

## Requirements

### Requirement: Email And Password Login
The login page SHALL accept an email and password. Valid credentials SHALL start a new session. Invalid credentials MUST show a validation error without revealing which part was wrong. Both outcomes SHALL be audit-logged.

#### Scenario: Valid credentials
- **WHEN** a user signs in with a correct email and password
- **THEN** the session is regenerated and a login is audit-logged

#### Scenario: Wrong password
- **WHEN** the password is wrong
- **THEN** the email field shows "The provided credentials do not match our records." and a failed login is audit-logged

### Requirement: Login Throttling
Login attempts SHALL be limited to five per minute per email and IP address combination.

#### Scenario: Sixth attempt
- **WHEN** six attempts are made within a minute for one email from one IP
- **THEN** the sixth is throttled

### Requirement: Post-Login Redirect
Administrators SHALL be sent to the admin home, or back to an admin page they were originally trying to reach. A stored destination outside the admin area MUST be ignored. Other users SHALL be redirected to their intended page or the portal.

#### Scenario: Deep link
- **WHEN** an admin signs in after being sent to login from `/admin/ips`
- **THEN** they land on `/admin/ips`

#### Scenario: External intended URL
- **WHEN** the stored intended URL is not under `/admin`
- **THEN** the admin lands on the admin home

### Requirement: Passkey Login
Guests SHALL be able to sign in with a registered passkey from the login page, subject to the same throttling. Signed-in users SHALL be able to register and delete their own passkeys. Deletions SHALL be audit-logged.

#### Scenario: Passkey rejected
- **WHEN** passkey assertion fails
- **THEN** the response is 422 with "Authentication failed." and the page shows the error

#### Scenario: Delete a passkey
- **WHEN** a signed-in user deletes one of their passkeys
- **THEN** it is removed and the deletion audit-logged

### Requirement: Password Management
Admins SHALL be able to set a user's password or clear it. Passwords MUST NOT be exposed in responses; only whether one is set. A user with a cleared password SHALL be unable to sign in with email and password.

#### Scenario: Set password
- **WHEN** an admin saves a new password for a user
- **THEN** the user can sign in with it

#### Scenario: Clear password
- **WHEN** an admin clears a user's password
- **THEN** the user shows as having no password and email login fails
