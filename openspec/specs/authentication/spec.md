# authentication Specification

## Purpose
Attendees sign in through the captive portal using the OAuth2 device flow (QR code scanned on a phone); admins and users with a password sign in by email and password or a passkey. First-run setup creates the initial admin. Sensitive credential changes require re-verification.

## Requirements

### Requirement: First-Run Setup
While no user exists, the system SHALL redirect all non-setup requests to `/setup` (JSON requests receive 503), where the first admin is created with an email and a password of at least 12 characters, confirmed. The new user SHALL receive the admin and user roles and be signed in. Once any user exists, `/setup` SHALL redirect to the login page, and concurrent setup submissions SHALL NOT create more than one initial user.

#### Scenario: Fresh install
- **WHEN** a visitor opens any page with no users in the database
- **THEN** they are redirected to `/setup`

#### Scenario: Setup completed
- **WHEN** the first admin submits a valid email and password
- **THEN** the admin is created, signed in and taken to the admin dashboard

#### Scenario: Setup after completion
- **WHEN** a user exists and someone requests `/setup`
- **THEN** they are redirected to the login page

### Requirement: Captive Portal Device Flow Login
The captive portal at `/captive` SHALL start a device flow with the Borealis provider, remember the flow against the requesting client IP for its lifetime, and show a QR code and user code. If the provider cannot be reached it SHALL respond 503 with a service-unavailable page.

#### Scenario: Login page shown
- **WHEN** an unauthenticated visitor opens `/captive`
- **THEN** a QR code, user code and verification address are shown

#### Scenario: Provider down
- **WHEN** the device flow cannot be initiated
- **THEN** the page is served with status 503 indicating the service is unavailable

### Requirement: Device Flow Polling
The captive page SHALL poll `/captive/poll/{deviceCode}`. The poll SHALL return 410 `expired` for an unknown or expired code, 403 when the polling IP differs from the IP that started the flow, `pending` while the provider has not approved, and `complete` with a redirect home once approved. On approval the system SHALL find or create the User, associate the client IP with them, enable their internet access unless they are blocked, sign them in, and record a `user.captive_login` audit entry.

#### Scenario: Approved on phone
- **WHEN** the user approves the flow and the page polls
- **THEN** the user is signed in and the response is `complete` with a redirect to home

#### Scenario: Blocked user
- **WHEN** a blocked user completes the flow
- **THEN** they are signed in but their internet access is not enabled

#### Scenario: Different device polls
- **WHEN** a poll comes from an IP other than the one that started the flow
- **THEN** the response is 403 and no login occurs

#### Scenario: Expired code
- **WHEN** the device code is unknown or has expired
- **THEN** the response is 410 with status `expired`

### Requirement: User Matching from Device Flow
The system SHALL match a device-flow identity to an existing User by external id first, then by email when email linking is enabled, and otherwise create a new User. Nickname, avatar, external id and tokens SHALL be updated on each login, and access and refresh tokens SHALL be stored encrypted and hidden from serialisation.

#### Scenario: Returning user
- **WHEN** a user with a known external id signs in again
- **THEN** their existing account is reused and its token fields refreshed

#### Scenario: Email linking
- **WHEN** an unknown external id arrives with an email matching an existing User and linking is enabled
- **THEN** that User is linked rather than a duplicate created

### Requirement: Password Login
The system SHALL provide `/login` for guests with email and password. Successful login SHALL regenerate the session and record `user.login`; failure SHALL record `user.login_failed` and show a generic credentials error. Login attempts SHALL be throttled to 5 per minute per email and IP. Admins SHALL be returned to an intended `/admin` URL when one exists, otherwise to the admin dashboard; other users to their intended URL or home.

#### Scenario: Wrong password
- **WHEN** a guest submits invalid credentials
- **THEN** a validation error is shown and a failed-login entry is recorded

#### Scenario: Throttled
- **WHEN** more than 5 attempts are made in a minute for the same email and IP
- **THEN** further attempts are rejected by the rate limiter

#### Scenario: Admin login
- **WHEN** an admin logs in with no intended URL
- **THEN** they land on the admin dashboard

### Requirement: Passkey Login
The system SHALL let a guest sign in with a registered passkey, with the same throttle as password login.

#### Scenario: Passkey sign-in
- **WHEN** a guest completes a valid passkey assertion
- **THEN** they are authenticated

### Requirement: Logout
The system SHALL log out on `POST /logout`, record `user.logout`, regenerate the session and redirect to the login page.

#### Scenario: Logout
- **WHEN** a signed-in user logs out
- **THEN** the session ends and they are redirected to login

### Requirement: Account Security Management
A signed-in user SHALL manage credentials from account settings. A user without a password SHALL be able to create one (minimum 8 characters, confirmed). Changing or removing a password, and registering or deleting passkeys, SHALL require a security method (password or passkey) and a verified session, established by re-entering the password. Each change SHALL be audited.

#### Scenario: Unverified change
- **WHEN** a user tries to change their password or manage passkeys without verifying
- **THEN** they are redirected to account settings (or receive 403 for JSON) with a verification message

#### Scenario: Create first password
- **WHEN** a user with no password sets one
- **THEN** it is stored hashed, `user.password_created` is recorded and the session becomes verified

#### Scenario: Existing password
- **WHEN** a user who already has a password calls the create endpoint
- **THEN** the request is forbidden

#### Scenario: Clear password
- **WHEN** a verified user removes their password
- **THEN** it is cleared, verification is dropped and `user.password_cleared` is recorded
