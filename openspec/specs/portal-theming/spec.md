# portal-theming Specification

## Purpose
Admins tune the look of Aperture's user-facing pages with a light or dark mode, an OKLCH accent colour and optional custom CSS. The settings apply to the portal, captive portal and admin pages through shared theme values.

## Requirements

### Requirement: Theme Mode
The theme SHALL have a mode of `light` or `dark`, defaulting to the configured application default.

#### Scenario: Valid mode
- **WHEN** an admin saves mode `dark`
- **THEN** pages render in dark mode

#### Scenario: Invalid mode
- **WHEN** any other mode is submitted
- **THEN** validation fails

### Requirement: Accent Colour
The accent colour SHALL be defined by OKLCH hue (integer 0 to 360), chroma (0.01 to 0.37) and lightness (integer 40 to 95). Hue is required; omitted chroma or lightness SHALL fall back to configured defaults. Out-of-range values SHALL be rejected.

#### Scenario: Custom accent
- **WHEN** an admin saves hue 200, chroma 0.15 and lightness 70
- **THEN** all three values are stored and applied as the accent

#### Scenario: Out of range
- **WHEN** lightness 20 is submitted
- **THEN** validation fails

### Requirement: Theme Distribution
The system SHALL expose the theme (mode, accent hue, chroma, lightness, custom CSS, site title, logo and favicon data) to Blade pages and to the front end for every request, resolving it once per request.

#### Scenario: Blade pages
- **WHEN** the captive portal is rendered
- **THEN** it receives the mode, accent values and site title

#### Scenario: Front end
- **WHEN** an Inertia page is rendered
- **THEN** the mode and accent values are available in the shared theme props

### Requirement: Custom CSS Safety
Custom CSS SHALL be limited to 10,000 characters and rejected on save if it contains `expression()`, `@import`, `javascript:` or `data:` URLs, `-moz-binding`, `behavior:`, `binding()` or `<script`. CSS that contains such patterns at render time SHALL be omitted with a logged warning.

#### Scenario: Dangerous CSS on save
- **WHEN** an admin submits CSS containing `@import`
- **THEN** validation fails with a "dangerous CSS patterns" error

#### Scenario: Legacy dangerous CSS
- **WHEN** stored CSS contains a blocked pattern
- **THEN** it is not rendered and a warning is logged

#### Scenario: Safe CSS
- **WHEN** an admin saves ordinary rules
- **THEN** they are injected into the page head

### Requirement: Accent-Coloured Favicon
The default favicon at `/favicon.svg` SHALL be rendered in the current accent colour and cacheable for an hour.

#### Scenario: Accent changed
- **WHEN** the accent is changed
- **THEN** `/favicon.svg` reflects the new colour

### Requirement: Admin IP Bandwidth
Admins SHALL be able to fetch bandwidth history for an IP address for range `1h`, `24h`, `4d` or `7d` (default `24h`), returned in the same JSON shape as other bandwidth resources; other ranges SHALL be rejected.

#### Scenario: Valid range
- **WHEN** an admin requests `/admin/ips/{ip}/bandwidth?range=4d`
- **THEN** bandwidth data for that IP is returned

#### Scenario: Invalid range
- **WHEN** the range is `30d`
- **THEN** validation fails
