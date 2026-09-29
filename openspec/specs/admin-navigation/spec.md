# admin-navigation Specification

## Purpose
The admin area is organised into a grouped sidebar, and the Content section lets admins manage general site settings and public content pages that visitors can read, including the terms and privacy pages linked from the portal.

## Requirements

### Requirement: Admin Access Control
All `/admin` routes SHALL be available only to users holding the admin role.

#### Scenario: Non-admin access
- **WHEN** a signed-in non-admin requests an admin URL
- **THEN** access is denied

### Requirement: Grouped Sidebar
The admin sidebar SHALL group navigation into Management (Dashboard, Users, IP Addresses, Switches, DHCP, MAC Addresses), Services (Integrations, IPv6 Detection, DNS Detection, Network, Captive Portal API), Content (Dashboard, Pages, Settings) and System (Audit Log). The current section SHALL be highlighted; the admin Dashboard and Content Dashboard entries SHALL be active only on their exact URL.

#### Scenario: Nested page
- **WHEN** an admin views a user's detail page
- **THEN** the Users entry is highlighted

#### Scenario: Content dashboard
- **WHEN** an admin views the content pages list
- **THEN** the Pages entry is highlighted and the Content Dashboard entry is not

### Requirement: Breadcrumbs
Each admin page SHALL supply breadcrumbs starting at Admin so the admin can navigate back up the hierarchy.

#### Scenario: Settings breadcrumb
- **WHEN** an admin opens Content Settings
- **THEN** the breadcrumbs read Admin, Content, Settings with the first two linked

### Requirement: General Content Settings
The Content Settings page SHALL let an admin edit the site title (required, up to 255 characters), the terms and privacy sources (each either a Content Page or a custom URL, with a value up to 500 characters), and the portal theme fields. Saving SHALL record a `settings.updated` audit entry for the `general` group.

#### Scenario: Save settings
- **WHEN** an admin submits valid general settings
- **THEN** they are persisted and a success message is shown

#### Scenario: Missing title
- **WHEN** the site title is empty
- **THEN** validation fails and nothing is saved

#### Scenario: Invalid source type
- **WHEN** terms type is neither `page` nor `url`
- **THEN** validation fails

### Requirement: Site Title Sharing
The site title, defaulting to the application name, SHALL be shared with every page as the application name so it appears in the portal header and browser tab.

#### Scenario: Title changed
- **WHEN** an admin changes the site title
- **THEN** subsequent pages show the new title in the header

### Requirement: Content Page Management
Admins SHALL create, edit and delete Content Pages, each with a title (up to 255 characters), a unique slug of letters, numbers, dashes and underscores, and optional markdown content. Deletion SHALL return to the pages list with a confirmation message.

#### Scenario: Duplicate slug
- **WHEN** an admin saves a page whose slug belongs to another page
- **THEN** validation fails

#### Scenario: Keep own slug
- **WHEN** an admin updates a page without changing its slug
- **THEN** the update succeeds

#### Scenario: Delete page
- **WHEN** an admin deletes a page
- **THEN** it is removed and the pages list is shown with a success message

### Requirement: Public Content Pages
A Content Page SHALL be readable without signing in at `/content/{slug}`, and an unknown slug SHALL return 404.

#### Scenario: Anonymous visitor
- **WHEN** a guest opens `/content/terms` and the page exists
- **THEN** its content is shown

#### Scenario: Missing page
- **WHEN** the slug does not exist
- **THEN** the response is 404
