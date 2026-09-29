# custom-branding Specification

## Purpose
Admins can upload a custom square logo that replaces the default Aperture branding on user-facing pages and supplies the site favicons. The admin interface itself keeps the Aperture branding.

## Requirements

### Requirement: Logo Upload Validation
The system SHALL accept a logo upload only from an admin, and only as a PNG, JPG or WebP image of at most 2 MB, square (1:1) and at least 64x64 pixels. Invalid uploads SHALL be rejected with a validation error and leave the existing logo unchanged.

#### Scenario: Valid logo
- **WHEN** an admin uploads a 256x256 PNG
- **THEN** the logo is stored and a success message is shown

#### Scenario: Not square
- **WHEN** an admin uploads a 200x100 image
- **THEN** the upload is rejected with a "must be square" error

#### Scenario: Too small
- **WHEN** an admin uploads a 32x32 image
- **THEN** the upload is rejected with a minimum-size error

#### Scenario: Unsupported type or size
- **WHEN** an admin uploads a GIF or a file over 2 MB
- **THEN** the upload is rejected

### Requirement: Logo Processing
The system SHALL convert an accepted upload to PNG, downscale it to at most 512x512, and generate 16x16, 32x32 and 180x180 (Apple touch icon) variants. A site-logo setting SHALL mark a custom logo as present.

#### Scenario: Large upload
- **WHEN** a 1024x1024 image is uploaded
- **THEN** the stored logo is 512x512 and the three favicon variants exist

### Requirement: Logo Removal
The system SHALL let an admin remove the custom logo, deleting all stored logo and favicon files and the site-logo setting.

#### Scenario: Remove logo
- **WHEN** an admin removes the logo
- **THEN** no custom logo is reported present and default branding is used

### Requirement: Logo on User-Facing Pages
When a custom logo exists, the captive portal login page SHALL show it in place of the default mark, and the user portal header SHALL show it beside the site title. Without a custom logo the default branding SHALL be used.

#### Scenario: Captive portal with logo
- **WHEN** a visitor opens the captive portal and a logo is set
- **THEN** the logo image is displayed with the site title as alt text

#### Scenario: No logo
- **WHEN** no logo is set
- **THEN** the default branding is shown

### Requirement: Favicon Selection
When a custom logo exists, the app and captive portal pages SHALL declare the generated 16, 32 and 180 pixel icons as favicons with a cache-busting version derived from the file modification time. Otherwise they SHALL use the dynamic favicon, an SVG aperture mark drawn in the current accent colour.

#### Scenario: Logo replaced
- **WHEN** an admin uploads a new logo
- **THEN** the favicon URLs carry a new version value so browsers reload them

#### Scenario: No logo
- **WHEN** no logo is set
- **THEN** `/favicon.svg` returns an SVG using the configured accent colour

### Requirement: Logo Change Auditing
Uploading a logo SHALL record a `settings.updated` audit entry for the `logo` setting group.

#### Scenario: Upload audited
- **WHEN** a logo is uploaded
- **THEN** an audit entry with process `admin` and setting group `logo` is recorded
