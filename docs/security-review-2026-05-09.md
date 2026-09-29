# Aperture Security Audit Report

**Application:** Aperture -- LAN Event Captive Portal  
**Audit Date:** 2026-05-09  
**Auditor:** Independent Security Assessor  
**Classification:** CONFIDENTIAL -- Development Team & Event Organizers  
**Methodology:** Red Team / Blue Team cross-referenced assessment  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Risk Assessment Matrix](#2-risk-assessment-matrix)
3. [Consolidated Findings](#3-consolidated-findings)
4. [Attack Chain Analysis](#4-attack-chain-analysis)
5. [Positive Findings / Strengths](#5-positive-findings--strengths)
6. [Remediation Roadmap](#6-remediation-roadmap)
7. [Compliance Notes](#7-compliance-notes)

---

## 1. Executive Summary

### Overall Security Posture: C+

Aperture demonstrates a solid foundation in several areas -- full ORM usage eliminating SQL injection risk, proper credential encryption, CSRF protection globally enabled, and well-implemented WebAuthn support. However, several architectural decisions undermine the application's security model in ways that are particularly concerning given the deployment context: a shared LAN with technically sophisticated attendees.

The most critical issue is the **Trust All Proxies** configuration (`$proxies = '*'`), which fundamentally undermines the IP-based identity model that the entire captive portal relies upon. Combined with the **first-user admin bootstrap** race condition and the **portal reset without confirmation**, a realistic attack chain exists that could grant full administrative control to an attendee.

### Key Statistics

| Severity | Count |
|---|---|
| Critical | 1 |
| High | 3 |
| Medium | 6 |
| Low | 7 |
| Informational | 2 |
| **Total** | **19** |

### Top 3 Immediate Action Items

1. **Restrict TrustProxies to actual reverse proxy IPs** -- The `$proxies = '*'` setting allows any attendee on the LAN to spoof their IP address via `X-Forwarded-For` headers, completely bypassing the captive portal's network access controls. This is the single most impactful fix.

2. **Add confirmation and separate authorization to portal reset** -- The reset endpoint dispatches a destructive job with a single POST request. When chained with the first-user bootstrap, this creates a full admin takeover path. Add a confirmation dialog, re-authentication, and audit logging.

3. **Add DOMPurify to Content/Show.vue** -- The public content page renders admin-authored Markdown via `v-html` without sanitization. While this requires a compromised admin account, the fix is trivial (one import, one function call) and eliminates stored XSS risk on public-facing pages.

---

## 2. Risk Assessment Matrix

The following matrix evaluates each finding in the context of Aperture's specific deployment environment: a LAN event captive portal where attendees are technically sophisticated, on a shared network, and semi-trusted.

| ID | Title | Adjusted Severity | Likelihood | Impact | Risk Rating |
|---|---|---|---|---|---|
| SEC-001 | Trust All Proxies (IP Spoofing) | **Critical** | Almost Certain | Catastrophic | **Critical** |
| SEC-002 | First User Admin Bootstrap Race | High | Possible | Catastrophic | **High** |
| SEC-003 | Portal Reset Without Confirmation | High | Possible | Major | **High** |
| SEC-004 | Stored XSS via Content Pages | Medium | Unlikely | Major | **Medium** |
| SEC-005 | Missing Security Headers | Medium | Likely | Moderate | **Medium** |
| SEC-006 | CORS Allows All Origins | Medium | Possible | Moderate | **Medium** |
| SEC-007 | TrustHosts Middleware Disabled | Medium | Possible | Moderate | **Medium** |
| SEC-008 | CSRF Exemption on IPv6 Endpoint | Low | Unlikely | Moderate | **Low** |
| SEC-009 | SSRF via JWKS URL | Low | Rare | Moderate | **Low** |
| SEC-010 | Audit Logging Gaps | Medium | N/A (control gap) | Moderate | **Medium** |
| SEC-011 | User Model OAuth Tokens in Fillable | Low | Unlikely | Moderate | **Low** |
| SEC-012 | Auto-Creating Records via Route Binding | Low | Possible | Minor | **Low** |
| SEC-013 | Device Code Polling IP Mismatch | Low | Unlikely | Minor | **Low** |
| SEC-014 | SSH Proxy Timing Attack | Informational | Rare | Minor | **Informational** |
| SEC-015 | Custom CSS Injection | Low | Rare | Minor | **Low** |
| SEC-016 | SQL Wildcard Injection in Admin Search | Low | Unlikely | Negligible | **Low** |
| SEC-017 | Account Security Bypass (Passwordless) | Low | Unlikely | Minor | **Low** |
| SEC-018 | No Rate Limiting on Passkey Endpoints | Low | Possible | Negligible | **Low** |
| SEC-019 | Debug Mode in .env | Informational | N/A | N/A | **Informational** |

---

## 3. Consolidated Findings

### SEC-001: Trust All Proxies Enables IP Spoofing

**Cross-references:** RT-004, BT-029  
**Adjusted Severity:** CRITICAL (upgraded from Red Team's High)  
**Justification:** In a LAN event context, this is the most dangerous finding. The entire captive portal model depends on accurate IP identification. Attendees share a network and can trivially set HTTP headers. This finding is foundational -- it amplifies the impact of multiple other findings.

**Description:**  
`app/Http/Middleware/TrustProxies.php` sets `$proxies = '*'`, which tells Laravel to trust `X-Forwarded-For` headers from any source. The application uses `$request->getClientIp()` throughout for identity and access control decisions, including:
- Determining whether a user has internet access (CaptivePortalApiController)
- Associating IP addresses with user accounts
- Rate limiting decisions

On a shared LAN, any attendee can set `X-Forwarded-For` to any IP address, effectively impersonating other attendees or bypassing internet access controls entirely.

**Attack Scenario:**  
An attendee whose internet access is blocked adds `X-Forwarded-For: 10.0.0.50` to their HTTP requests, where `10.0.0.50` belongs to an admin or an attendee with internet enabled. The captive portal API returns `captive: false`, and upstream network equipment (if also relying on the portal's API) grants access. Alternatively, the attacker enumerates IPs to find one with internet access enabled.

**Current Mitigations:** None effective.

**Recommended Fix:**  
Configure `$proxies` to the specific IP address(es) of the actual reverse proxy (e.g., nginx, Traefik) in front of the application. Use environment variable configuration:

```php
// app/Http/Middleware/TrustProxies.php
protected $proxies; // null by default -- trust no proxies

public function __construct()
{
    $this->proxies = env('TRUSTED_PROXY_IPS')
        ? explode(',', env('TRUSTED_PROXY_IPS'))
        : null;
}
```

If deployed behind Docker's internal networking, use the Docker gateway IP. If no reverse proxy is used, leave `$proxies` as `null`.

**Affected Files:** `app/Http/Middleware/TrustProxies.php`

---

### SEC-002: First User Admin Bootstrap Race Condition

**Cross-references:** RT-002, BT-039  
**Adjusted Severity:** HIGH  
**Justification:** Maintained at High rather than Critical because the window of exploitation is narrow (only during initial deployment or after a reset). However, when chained with SEC-003 (portal reset), it becomes a realistic attack path.

**Description:**  
`LoginController::authenticate()` checks `User::query()->doesntExist()` and, when true, creates the first user as an admin with whatever email/password is submitted. The password field has no minimum length requirement during this bootstrap flow (unlike the password change flow which enforces 8 characters).

The check-then-act pattern has a race condition: multiple requests arriving simultaneously when no users exist could potentially create multiple admin accounts.

**Attack Scenario:**  
1. An admin triggers a portal reset (SEC-003), which deletes all non-admin users and clears IP records
2. If the reset also clears admin users (e.g., via a database migration/reseed), the bootstrap condition becomes true
3. An attacker monitoring the network detects the portal reset and quickly submits credentials to become the new admin

Note: The current `ResetAperture` job preserves admin users, so this chain requires an additional database wipe. The risk is primarily during initial deployment at an event.

**Current Mitigations:**
- The `ResetAperture` job preserves admin users (reduces likelihood of reset chain)
- Short exploitation window

**Recommended Fixes:**
1. Add a setup token/secret that must be provided during first-user bootstrap:
```php
if (User::query()->doesntExist()) {
    $request->validate([
        'setup_token' => ['required', Rule::in([config('aperture.setup_token')])],
        'email' => ['required', 'email'],
        'password' => ['required', 'min:12'],
    ]);
    // ... create admin
}
```
2. Enforce password complexity during bootstrap (minimum 12 characters, at least one uppercase, one number)
3. Wrap the check-and-create in a database transaction with a lock to prevent race conditions

**Affected Files:** `app/Http/Controllers/LoginController.php`

---

### SEC-003: Portal Reset Without Confirmation or Audit Trail

**Cross-references:** RT-014, BT-035  
**Adjusted Severity:** HIGH  
**Justification:** Upgraded from Medium. At a LAN event, an accidental or malicious portal reset disconnects all attendees from the internet simultaneously. This is a high-impact availability issue. The lack of confirmation makes CSRF or accidental activation more likely.

**Description:**  
`HomeController::reset()` dispatches `ResetAperture` with a single POST request. There is no:
- Confirmation dialog or two-step process
- Re-authentication requirement
- Audit log entry
- Separate authorization check beyond the admin gate

The `ResetAperture` job deletes all IP address records, disables internet for all IPs, and deletes all non-admin users.

**Attack Scenario:**  
1. A CSRF attack targeting an admin who is logged in -- a malicious page on the LAN sends a POST to `/admin/reset`
2. An accidental click during a busy event
3. A disgruntled admin team member

**Current Mitigations:**
- Admin gate middleware (only admins can access)
- CSRF protection is active on this route

**Recommended Fixes:**
1. Add a confirmation step requiring the admin to type a confirmation phrase (e.g., "RESET") and re-enter their password
2. Log the reset action with the user who initiated it, timestamp, and IP
3. Consider requiring a second admin to approve the reset
4. Add a cooldown period (e.g., 5-minute delay with cancel option)

**Affected Files:** `app/Http/Controllers/Admin/HomeController.php`, `app/Jobs/ResetAperture.php`

---

### SEC-004: Stored XSS via Public Content Pages (Missing DOMPurify)

**Cross-references:** RT-001, BT-013  
**Adjusted Severity:** MEDIUM (downgraded from Red Team's High)  
**Justification:** Downgraded because exploitation requires a compromised admin account. The content is admin-authored, not user-submitted. In the LAN event context, admins are trusted event organizers. The risk is from a compromised admin account or a rogue admin, not from attendee input.

**Description:**  
`resources/js/Pages/Content/Show.vue` renders Markdown content using `marked.parse()` and outputs it via `v-html` without passing through DOMPurify. The `CustomMarkdownBlock.vue` component correctly uses DOMPurify, showing the team is aware of the need but missed this page.

The content is authored by admins through the admin panel and displayed on public routes at `/content/{slug}`, accessible to all attendees.

**Attack Scenario:**  
A compromised admin account creates a content page containing `<img src=x onerror="fetch('/admin/reset',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}})">`. Any attendee visiting the page triggers the payload. However, CSRF tokens would not match across users, limiting cross-user exploitation to cookie/session theft.

**Current Mitigations:**
- Content is admin-authored only (not user-submitted)
- Admin accounts are protected by authentication
- `CustomMarkdownBlock.vue` demonstrates the correct pattern exists in the codebase

**Recommended Fix:**  
Add DOMPurify to `Content/Show.vue`:

```javascript
import DOMPurify from 'dompurify';

const renderedContent = computed(() => {
    const html = marked.parse(props.page.content || '', { breaks: true });
    return DOMPurify.sanitize(html);
});
```

**Affected Files:** `resources/js/Pages/Content/Show.vue`

---

### SEC-005: Missing Security Headers

**Cross-references:** BT-031  
**Adjusted Severity:** MEDIUM  
**Justification:** The absence of security headers is a defense-in-depth gap. On a shared LAN, clickjacking and content-type sniffing attacks are more feasible because attackers can serve malicious content from the same network.

**Description:**  
The application does not set any of the standard security headers:
- Content-Security-Policy (CSP)
- X-Frame-Options
- Strict-Transport-Security (HSTS)
- X-Content-Type-Options
- Referrer-Policy
- Permissions-Policy

**Current Mitigations:** None.

**Recommended Fix:**  
Add a middleware or configure at the web server level:

```php
// app/Http/Middleware/SecurityHeaders.php
public function handle($request, Closure $next)
{
    $response = $next($request);
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    // Add CSP once asset pipeline is configured for nonces
    return $response;
}
```

Register in `app/Http/Kernel.php` web middleware group. HSTS should be configured at the reverse proxy level if TLS termination happens there.

**Affected Files:** `app/Http/Kernel.php` (new middleware needed)

---

### SEC-006: CORS Allows All Origins

**Cross-references:** BT-028  
**Adjusted Severity:** MEDIUM  
**Justification:** On a shared LAN, any attendee can run a web server. With `allowed_origins: ['*']`, any page served on the LAN can make cross-origin requests to the Aperture API. While `supports_credentials: false` prevents cookie-based attacks, the captive portal API is unauthenticated and IP-based, so CORS is not the primary barrier.

**Description:**  
`config/cors.php` sets `allowed_origins` to `['*']`. While `supports_credentials` is `false` (preventing cookies from being sent cross-origin), the captive portal API at `/api/captive-portal` is unauthenticated and relies on the source IP, making CORS largely irrelevant for that endpoint.

**Current Mitigations:**
- `supports_credentials: false` prevents cookie-bearing cross-origin requests
- Admin routes require session authentication which won't work cross-origin

**Recommended Fix:**  
Restrict to the application's own origin:

```php
// config/cors.php
'allowed_origins' => [env('APP_URL', 'https://aperture.local.js42.io')],
```

**Affected Files:** `config/cors.php`

---

### SEC-007: TrustHosts Middleware Disabled

**Cross-references:** BT-030  
**Adjusted Severity:** MEDIUM  
**Justification:** Host header injection can lead to cache poisoning and password reset link manipulation. On a LAN, an attacker can more easily control DNS responses, making this more exploitable than on the public internet.

**Description:**  
The `TrustHosts` middleware is commented out in `app/Http/Kernel.php`. The middleware class exists and has tests, but is not active. This allows host header injection attacks.

**Current Mitigations:** None active. The middleware exists but is disabled.

**Recommended Fix:**  
Uncomment the middleware in `Kernel.php`:

```php
protected $middleware = [
    \App\Http\Middleware\TrustHosts::class, // Uncomment this
    TrustProxies::class,
    // ...
];
```

**Affected Files:** `app/Http/Kernel.php`, `app/Http/Middleware/TrustHosts.php`

---

### SEC-008: CSRF Exemption on IPv6 Detection Endpoint

**Cross-references:** RT-003, BT-008  
**Adjusted Severity:** LOW (downgraded from Red Team's Medium)  
**Justification:** The endpoint uses JWT verification (RS256 + JWKS) as an alternative authentication mechanism. The JWT must contain a valid IPv6 address in its `sub` claim and must be signed by the trusted JWKS provider. This is a stronger protection than CSRF tokens for this specific use case. The IPv6 address is also validated via `filter_var` with `FILTER_FLAG_IPV6`.

**Description:**  
`POST /ipv6` disables CSRF via `withoutMiddleware(VerifyCsrfToken::class)`. This is intentional -- the endpoint receives JWTs from client-side JavaScript that has detected the user's IPv6 address, and CSRF tokens would interfere with the detection flow.

**Current Mitigations:**
- JWT verification with RS256 algorithm
- JWKS-based key management
- IPv6 address validation via `filter_var`

**Recommended Fixes (defense-in-depth):**
1. Add audience and issuer claims to JWT validation in `Ipv6JwtService`
2. Add rate limiting to the endpoint (e.g., `throttle:10,1`)
3. Bind the JWT to the user's session (include session ID or CSRF token as a JWT claim)

**Affected Files:** `routes/web.php`, `app/Services/Ipv6JwtService.php`, `app/Http/Controllers/PortalController.php`

---

### SEC-009: SSRF via Admin-Configurable JWKS URL

**Cross-references:** RT-011, BT-021  
**Adjusted Severity:** LOW (downgraded from Red Team's Medium)  
**Justification:** The JWKS URL is only configurable by admins. In the LAN event context, admin compromise is the prerequisite, and an admin already has extensive control over the system. The SSRF vector adds marginal additional capability. However, internal network scanning from the server's perspective could reveal services not visible to attendees.

**Description:**  
`Ipv6JwtService::fetchJwks()` fetches the JWKS URL with `Http::timeout(10)->get($jwksUrl)` without restricting internal IP ranges. An admin could configure a JWKS URL pointing to internal services (e.g., `http://169.254.169.254/` for cloud metadata, or internal services on the event network).

**Current Mitigations:**
- Admin-only configuration
- 10-second timeout
- Response is cached for 1 hour (limits repeated requests)

**Recommended Fix:**  
Add URL validation to reject private/internal IP ranges:

```php
private function validateJwksUrl(string $url): void
{
    $host = parse_url($url, PHP_URL_HOST);
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw new InvalidArgumentException('JWKS URL must not point to internal addresses');
    }
}
```

**Affected Files:** `app/Services/Ipv6JwtService.php`

---

### SEC-010: Audit Logging Gaps

**Cross-references:** BT-026, BT-035, BT-036  
**Adjusted Severity:** MEDIUM  
**Justification:** At a LAN event, incidents need to be investigated quickly. Without audit logs for critical actions, forensic analysis is severely hampered. This is a control gap, not a vulnerability, but its absence amplifies the impact of other findings.

**Description:**  
The application has partial audit logging but is missing coverage for:
- Login/logout events (success and failure)
- Password changes
- Passkey registration/deletion
- Admin configuration changes
- Role assignment changes
- Portal reset operations
- DNS filter toggles
- User internet enable/disable

**Current Mitigations:** Basic Laravel logging exists. The `ResetAperture` job logs completion.

**Recommended Fix:**  
Implement an audit trail system using Laravel events and listeners. Consider a dedicated `audit_logs` table:

```php
// Create AuditLog model with: user_id, action, target_type, target_id, details (json), ip_address, created_at
// Fire events for each auditable action
// Use a listener to persist to the audit_logs table
```

Priority audit events: login, reset, role changes, configuration changes, internet enable/disable.

**Affected Files:** Multiple controllers and models (system-wide enhancement)

---

### SEC-011: User Model OAuth Tokens in Fillable

**Cross-references:** RT-013, BT-018  
**Adjusted Severity:** LOW  
**Justification:** The tokens are encrypted at rest via cast and excluded from serialization via `$hidden`. The risk is theoretical mass assignment if a controller directly uses `$request->all()` for model creation/update. Current code uses specific field assignments and FormRequests.

**Description:**  
The `User` model has `access_token`, `refresh_token`, and `token_expires_at` in the `$fillable` array. While these are encrypted and hidden, including them in `$fillable` creates a risk if any future code path passes unvalidated user input to `User::create()` or `$user->fill()`.

**Current Mitigations:**
- Tokens are encrypted at rest (`encrypted` cast)
- Tokens are excluded from JSON serialization (`$hidden`)
- Current code uses specific field assignments, not mass assignment from request data

**Recommended Fix:**  
Remove OAuth token fields from `$fillable` and set them explicitly in the OAuth flow:

```php
// Remove from $fillable: 'access_token', 'refresh_token', 'token_expires_at'
// In OAuth callback, set directly:
$user->access_token = $oauthToken->accessToken;
$user->refresh_token = $oauthToken->refreshToken;
$user->token_expires_at = $oauthToken->expiresAt;
$user->save();
```

**Affected Files:** `app/Models/User.php`

---

### SEC-012: Auto-Creating Records via Route Model Binding

**Cross-references:** RT-006, BT-034  
**Adjusted Severity:** LOW  
**Justification:** `IpAddress` auto-creation is limited to managed ranges, which is a reasonable guard. `MacAddress` auto-creation is more permissive but MAC addresses are not sensitive identifiers and the records are lightweight. Resource exhaustion is theoretically possible but requires authenticated access to routes using these bindings.

**Description:**  
`IpAddress::resolveRouteBinding()` auto-creates records for IPs within managed ranges. `MacAddress::resolveRouteBinding()` auto-creates records unconditionally for any valid MAC address format.

**Current Mitigations:**
- `IpAddress` checks if the IP is in a managed range before creating
- Routes using these bindings require authentication (admin or user)
- MAC addresses are normalized before storage

**Recommended Fix:**  
For `MacAddress`, add a validation check or rate limit on auto-creation. Consider whether auto-creation is necessary or if a 404 response would be more appropriate for unknown MAC addresses.

**Affected Files:** `app/Models/IpAddress.php`, `app/Models/MacAddress.php`

---

### SEC-013: Device Code Polling Lacks IP Binding

**Cross-references:** RT-007  
**Adjusted Severity:** LOW (downgraded from Red Team's Medium)  
**Justification:** Exploiting this requires shoulder-surfing a 6-8 character device code displayed on a screen, then racing to poll from a different device. The device code flow is time-limited and the code is single-use. On a LAN, physical proximity makes shoulder-surfing plausible, but the impact is limited to the attacker gaining the auth session for a single attendee (not admin access).

**Description:**  
The captive portal device flow poll endpoint does not verify that the polling client's IP matches the IP that initiated the device code request.

**Current Mitigations:**
- Device codes are time-limited
- Codes are single-use
- The flow uses encrypted tokens and database transactions

**Recommended Fix:**  
Store the initiating IP with the device code and validate it on poll:

```php
// On device code creation: store $request->getClientIp() with the code
// On poll: verify $request->getClientIp() matches stored IP
```

Note: This fix is only effective after SEC-001 (TrustProxies) is resolved.

**Affected Files:** `app/Http/Controllers/CaptivePortalController.php`

---

### SEC-014: SSH Proxy API Key Timing Attack

**Cross-references:** RT-010, BT-024  
**Adjusted Severity:** INFORMATIONAL (downgraded from Red Team's Low)  
**Justification:** The SSH proxy uses `token != apiKey` for comparison. While this is technically vulnerable to timing attacks, exploiting it requires: (a) network-level timing precision from the LAN, (b) many thousands of requests to extract statistical signal, and (c) the API key is a high-entropy random string. The practical risk is negligible.

**Description:**  
`ssh-proxy/server/server.go` line 41 uses `token != apiKey` instead of `crypto/subtle.ConstantTimeCompare()`.

**Current Mitigations:**
- The comparison is fast enough that network jitter on a LAN dwarfs any timing signal
- The API key is high-entropy
- The SSH proxy has logging and timeout protections

**Recommended Fix (best practice):**

```go
import "crypto/subtle"

if subtle.ConstantTimeCompare([]byte(token), []byte(apiKey)) != 1 {
    // unauthorized
}
```

**Affected Files:** `ssh-proxy/server/server.go`

---

### SEC-015: Custom CSS Injection

**Cross-references:** RT-008, BT-014  
**Adjusted Severity:** LOW  
**Justification:** The Red Team noted that custom CSS is stored but not currently rendered in any blade template. This means the finding is theoretical. Even if rendered, CSS injection (without JavaScript execution) has limited impact -- primarily UI redressing or data exfiltration via CSS selectors.

**Description:**  
Admin-configurable custom CSS is validated only by blocking `<script>` tags. CSS-based attacks like `url()` exfiltration or `@import` of external stylesheets are not blocked. However, the CSS is stored via `View::share` but not rendered in templates.

**Current Mitigations:**
- CSS is not currently rendered (dead code path)
- Admin-only configuration
- `<script>` tags are blocked

**Recommended Fix:**  
When the CSS rendering is enabled, sanitize CSS using a CSS parser/sanitizer library. Block `url()`, `@import`, and `expression()`. Alternatively, use a CSP that restricts inline styles to a nonce.

**Affected Files:** `app/Http/Controllers/Admin/GeneralSettingsController.php`

---

### SEC-016: SQL Wildcard Injection in Admin Search

**Cross-references:** RT-009, BT-010  
**Adjusted Severity:** LOW  
**Justification:** The `SearchController` correctly escapes wildcards. The `MacAddressController` and `EventController` do not, but these are admin-only endpoints. The impact is performance degradation (slow queries) from crafted wildcard patterns, not data breach.

**Description:**  
While the main `SearchController` escapes SQL wildcards (`%` and `_`), the `MacAddressController` and `EventController` pass user input directly into `LIKE` clauses without escaping wildcards.

**Current Mitigations:**
- Admin-only endpoints
- Eloquent parameterized queries prevent SQL injection
- Impact limited to performance (slow queries from `%` patterns)

**Recommended Fix:**  
Apply the same wildcard escaping used in `SearchController`:

```php
$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
```

**Affected Files:** `app/Http/Controllers/Admin/MacAddressController.php`, `app/Http/Controllers/Admin/EventController.php`

---

### SEC-017: Account Security Bypass for Passwordless Users

**Cross-references:** RT-015, BT-005  
**Adjusted Severity:** LOW  
**Justification:** OAuth users without passwords cannot re-verify via password, so auto-verification for passkey registration is a reasonable UX decision. The risk is that a compromised OAuth session could be used to register passkeys without additional verification. However, the OAuth session itself is the authentication factor.

**Description:**  
`EnsureAccountSecurityVerified` middleware auto-verifies users who have no password set (OAuth-only users) for sensitive operations like passkey registration.

**Current Mitigations:**
- OAuth authentication provides the identity verification
- Passkey registration requires a valid authenticated session

**Recommended Fix:**  
For OAuth users, require a re-authentication via the OAuth provider before sensitive operations, or require email confirmation with a time-limited code.

**Affected Files:** `app/Http/Middleware/EnsureAccountSecurityVerified.php`

---

### SEC-018: No Rate Limiting on Passkey Endpoints

**Cross-references:** RT-016, BT-002  
**Adjusted Severity:** LOW  
**Justification:** While the login endpoints have rate limiting (5/min per email+IP), the passkey-specific endpoints do not. The risk is user enumeration (determining which users have passkeys registered). Impact is limited because the user list is visible to admins anyway, and attendee privacy expectations at a LAN event are generally lower.

**Description:**  
Passkey login and registration endpoints in `routes/web.php` lack `throttle` middleware.

**Current Mitigations:**
- Standard login routes have rate limiting
- WebAuthn implementation is otherwise solid (Laragear)

**Recommended Fix:**  
Add throttle middleware to passkey endpoints:

```php
Route::middleware('throttle:10,1')->group(function () {
    // passkey routes
});
```

**Affected Files:** `routes/web.php`

---

### SEC-019: Debug Mode in Development .env

**Cross-references:** RT-017  
**Adjusted Severity:** INFORMATIONAL  
**Justification:** This is the development `.env` file. The finding is valid as a reminder to ensure `APP_DEBUG=false` in production, but is not a current vulnerability.

**Description:**  
`APP_DEBUG=true` in the development `.env` file.

**Current Mitigations:** This is development configuration. Production deployment should use its own `.env`.

**Recommended Fix:**  
Add a deployment checklist item to verify `APP_DEBUG=false`. Consider adding a startup check that warns if debug mode is enabled in production.

**Affected Files:** `.env`

---

## 4. Attack Chain Analysis

### Chain 1: Full Admin Takeover via Reset + Bootstrap

**Findings involved:** SEC-003 (Portal Reset) -> SEC-002 (First User Bootstrap)  
**Risk:** HIGH  
**Preconditions:** Attacker must be able to trigger a full database wipe (not just the portal reset, which preserves admins)

**Sequence:**
1. If a full database reset occurs (e.g., `php artisan migrate:fresh` run during event troubleshooting), all user records are deleted
2. The first person to submit the login form at `/login` becomes admin
3. Attacker monitors the network for signs of a reset (HTTP 500 errors, portal going down)
4. Attacker submits login credentials immediately after the reset
5. Attacker is now admin with full control over the captive portal

**Mitigation:** The current `ResetAperture` job preserves admin users, which breaks this chain under normal reset conditions. The risk is primarily during `migrate:fresh` or database restoration. Adding a setup token (SEC-002 fix) would fully mitigate this chain.

**Residual Risk after SEC-003 fix:** LOW (reset requires confirmation + re-auth, and admins are preserved)

---

### Chain 2: IP Spoofing -> Internet Access Bypass

**Findings involved:** SEC-001 (Trust All Proxies) -> SEC-005 (Captive Portal API)  
**Risk:** CRITICAL  
**Preconditions:** Attacker is on the LAN (guaranteed at an event)

**Sequence:**
1. Attacker discovers the captive portal API endpoint at `/api/captive-portal`
2. Attacker adds `X-Forwarded-For: <known-good-IP>` to requests
3. The portal API returns `captive: false` for the spoofed IP
4. If the network enforces access based on the portal API responses, the attacker gains internet access
5. Alternatively, attacker enumerates IPs to find any with internet enabled

**Mitigation:** Fix SEC-001 (TrustProxies) to only trust the actual reverse proxy IP. This completely breaks the chain.

**Residual Risk after SEC-001 fix:** NEGLIGIBLE

---

### Chain 3: Admin Compromise -> Stored XSS -> Session Hijacking

**Findings involved:** SEC-002 or credential theft -> SEC-004 (Stored XSS) -> SEC-005 (Missing CSP)  
**Risk:** MEDIUM  
**Preconditions:** Attacker must first compromise an admin account

**Sequence:**
1. Attacker gains admin access (via SEC-002, credential theft, or social engineering)
2. Attacker creates a content page with malicious JavaScript via the admin panel
3. The page is served at a public URL `/content/{slug}` with unsanitized Markdown
4. Attendees visiting the page execute the attacker's JavaScript
5. Without CSP headers (SEC-005), there are no browser-side restrictions on the payload
6. The payload can steal session cookies, redirect to phishing pages, or deface the portal

**Mitigation:** Fix SEC-004 (add DOMPurify) and SEC-005 (add CSP). Either fix independently reduces the impact significantly.

**Residual Risk after both fixes:** NEGLIGIBLE

---

### Chain 4: IP Spoofing -> User Impersonation -> Account Takeover

**Findings involved:** SEC-001 (Trust All Proxies) -> SEC-012 (Auto-Create Records) -> SEC-013 (Device Code IP Mismatch)  
**Risk:** HIGH  
**Preconditions:** Attacker is on the LAN

**Sequence:**
1. Attacker spoofs the IP of a target attendee via `X-Forwarded-For`
2. The portal associates the attacker's actions with the target's IP record (auto-created if needed)
3. The attacker can potentially hijack in-progress device code flows since IP binding is not validated
4. The attacker gains the target's authenticated session

**Mitigation:** Fix SEC-001 (TrustProxies). This breaks the entire chain at step 1.

**Residual Risk after SEC-001 fix:** LOW (shoulder-surfing device codes is still possible but requires physical proximity)

---

## 5. Positive Findings / Strengths

The following security controls are well-implemented and deserve recognition:

### Excellent Implementation

| Control | Details |
|---|---|
| **SQL Injection Prevention** | Zero raw SQL queries across the entire codebase. Full Eloquent ORM usage with parameterized queries. This is exemplary. |
| **Credential Encryption** | OAuth tokens, switch passwords, and integration secrets are all encrypted at rest using Laravel's `encrypt()` or `encrypted` casts. Credentials are excluded from JSON serialization via `$hidden`. |
| **WebAuthn/Passkey Implementation** | Proper implementation via Laragear with session regeneration on authentication state changes and correct credential scoping. |
| **CSRF Protection** | Globally enabled with no blanket exclusions. The single exemption (IPv6 endpoint) uses JWT as an alternative. |
| **Device Flow OAuth** | Uses encrypted tokens, database transactions, and proper state management. Well-designed for the captive portal use case. |
| **File Upload Security (Logo)** | Image uploads are validated by type and re-processed through GD, which strips embedded payloads. This is best-practice. |
| **Mass Assignment Protection** | All models use explicit `$fillable` arrays. No use of `$guarded = []` anywhere. |
| **Login Rate Limiting** | 5 attempts per minute per email+IP combination. Captive portal at 30/min per IP. API at 60/min. Well-calibrated for the use case. |

### Good Implementation

| Control | Details |
|---|---|
| **Session Security** | `http_only=true`, `same_site=lax`. Session regeneration on authentication changes. |
| **Error Handling** | Custom error pages that don't leak stack traces. Sensitive inputs excluded from flash data. |
| **SSH Proxy Security** | Bearer token authentication, structured logging, `ReadHeaderTimeout` set to prevent slowloris attacks. |
| **Route Constraints** | Regex constraints on switch port route parameters provide input validation at the routing layer. |
| **SearchController Wildcard Escaping** | The main search controller properly escapes SQL wildcards, demonstrating awareness of the issue. |
| **Integration Config Merger** | Restricts allowed configuration keys from a whitelist, preventing arbitrary config injection. |

---

## 6. Remediation Roadmap

### P0 -- Fix Before Next Event

These must be resolved before deploying at a LAN event. They represent realistic, exploitable vulnerabilities in the deployment context.

| Priority | Finding | Effort | Description |
|---|---|---|---|
| P0-1 | SEC-001 | Low (15 min) | Configure `TrustProxies` with specific proxy IPs instead of `'*'` |
| P0-2 | SEC-003 | Medium (2-4 hrs) | Add confirmation dialog, re-authentication, and audit logging to portal reset |
| P0-3 | SEC-002 | Medium (2-4 hrs) | Add setup token, password policy, and transaction locking to first-user bootstrap |
| P0-4 | SEC-004 | Low (15 min) | Add `DOMPurify.sanitize()` to `Content/Show.vue` |

### P1 -- Fix Within 2 Weeks

These improve the security posture significantly but are not immediately exploitable without chaining.

| Priority | Finding | Effort | Description |
|---|---|---|---|
| P1-1 | SEC-005 | Medium (2-4 hrs) | Add security headers middleware (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, CSP) |
| P1-2 | SEC-007 | Low (15 min) | Uncomment `TrustHosts` middleware in Kernel.php |
| P1-3 | SEC-006 | Low (15 min) | Restrict CORS `allowed_origins` to application URL |
| P1-4 | SEC-010 | High (1-2 days) | Implement audit logging for critical actions |
| P1-5 | SEC-011 | Low (30 min) | Remove OAuth tokens from User `$fillable` |
| P1-6 | SEC-008 | Low (1 hr) | Add audience/issuer claims to JWT validation |

### P2 -- Fix Within 1 Month

Lower risk items that improve defense-in-depth.

| Priority | Finding | Effort | Description |
|---|---|---|---|
| P2-1 | SEC-009 | Medium (1-2 hrs) | Add internal IP validation to JWKS URL fetcher |
| P2-2 | SEC-012 | Low (1 hr) | Add rate limiting or validation to MAC address auto-creation |
| P2-3 | SEC-013 | Low (1 hr) | Add IP binding to device code polling |
| P2-4 | SEC-016 | Low (30 min) | Add wildcard escaping to MacAddressController and EventController |
| P2-5 | SEC-017 | Low (1 hr) | Add re-authentication for OAuth users on sensitive operations |
| P2-6 | SEC-018 | Low (30 min) | Add throttle middleware to passkey endpoints |
| P2-7 | SEC-015 | Low (1 hr) | Sanitize custom CSS if/when rendering is enabled |

### P3 -- Backlog (Defense-in-Depth)

Best-practice improvements with negligible current risk.

| Priority | Finding | Effort | Description |
|---|---|---|---|
| P3-1 | SEC-014 | Low (15 min) | Use `crypto/subtle.ConstantTimeCompare` in SSH proxy |
| P3-2 | SEC-019 | Low (15 min) | Add production debug mode check |
| P3-3 | -- | Medium (2-4 hrs) | Add session encryption (BT-001) |
| P3-4 | -- | Low (1 hr) | Add GD re-processing for cover image uploads (BT-023) |
| P3-5 | -- | Medium (2-4 hrs) | Add SSH proxy hostname allowlist (BT-025) |
| P3-6 | -- | Low (30 min) | Add timeout to account security verification session flag (BT-005) |

---

## 7. Compliance Notes

### OWASP Top 10 (2021) Assessment

| # | Category | Status | Notes |
|---|---|---|---|
| A01 | Broken Access Control | **Partial** | Admin gate implemented correctly. TrustProxies issue (SEC-001) undermines IP-based access control. First-user bootstrap (SEC-002) is a privilege escalation risk. |
| A02 | Cryptographic Failures | **Pass** | Credentials encrypted at rest. bcrypt for passwords. RS256 for JWTs. No plaintext secrets in code. |
| A03 | Injection | **Pass** | Full ORM usage. No raw SQL. Input validation via FormRequests. Minor wildcard escaping gap (SEC-016). |
| A04 | Insecure Design | **Partial** | The IP-based trust model with `$proxies = '*'` is an insecure design pattern. Device code flow missing IP binding. |
| A05 | Security Misconfiguration | **Fail** | TrustProxies `'*'` (SEC-001), CORS `'*'` (SEC-006), TrustHosts disabled (SEC-007), missing security headers (SEC-005). Multiple configuration issues. |
| A06 | Vulnerable & Outdated Components | **Not Assessed** | Dependency audit not in scope. Recommend running `composer audit` and `npm audit`. |
| A07 | Identification & Authentication Failures | **Partial** | Login rate limiting implemented. WebAuthn well-done. First-user bootstrap lacks password policy. No rate limiting on passkeys. |
| A08 | Software & Data Integrity Failures | **Pass** | CSRF protection globally enabled. JWT verification uses RS256 with JWKS. |
| A09 | Security Logging & Monitoring Failures | **Fail** | Significant audit logging gaps (SEC-010). No logging of login events, configuration changes, or privilege changes. |
| A10 | Server-Side Request Forgery | **Partial** | SSRF possible via admin-configured JWKS URL (SEC-009). Admin-only access mitigates likelihood. |

### OWASP ASVS Level 1 Summary

| Area | Compliance | Key Gaps |
|---|---|---|
| V1: Architecture | Partial | IP-based trust model is fragile; no threat model documented |
| V2: Authentication | Partial | Strong WebAuthn; weak first-user bootstrap; missing rate limits on some auth endpoints |
| V3: Session Management | Pass | http_only, same_site, session regeneration |
| V4: Access Control | Partial | Admin gate works; IP-based control undermined by proxy trust |
| V5: Validation | Pass | FormRequests, ORM, input filtering |
| V7: Error Handling & Logging | Fail | Custom error pages good; audit logging insufficient |
| V8: Data Protection | Pass | Encryption at rest, `$hidden` on sensitive fields |
| V9: Communications | Partial | TLS configurable but verify_ssl can be disabled; no HSTS |
| V12: Files & Resources | Partial | Logo upload excellent; cover image missing GD re-processing |
| V13: API | Partial | Rate limiting on main endpoints; CORS too permissive |
| V14: Configuration | Fail | TrustProxies, TrustHosts, security headers all misconfigured or missing |

---

## Appendix A: Finding Cross-Reference Table

| Consolidated ID | Red Team ID(s) | Blue Team ID(s) |
|---|---|---|
| SEC-001 | RT-004 | BT-029 |
| SEC-002 | RT-002 | BT-039 |
| SEC-003 | RT-014 | BT-035 |
| SEC-004 | RT-001 | BT-013 |
| SEC-005 | -- | BT-031 |
| SEC-006 | -- | BT-028 |
| SEC-007 | -- | BT-030 |
| SEC-008 | RT-003 | BT-008, BT-019 |
| SEC-009 | RT-011 | BT-021 |
| SEC-010 | -- | BT-026, BT-035, BT-036 |
| SEC-011 | RT-013 | BT-018 |
| SEC-012 | RT-006 | BT-034 |
| SEC-013 | RT-007 | -- |
| SEC-014 | RT-010 | BT-024 |
| SEC-015 | RT-008 | BT-014 |
| SEC-016 | RT-009 | BT-010 |
| SEC-017 | RT-015 | BT-005 |
| SEC-018 | RT-016 | BT-002 |
| SEC-019 | RT-017 | -- |

## Appendix B: Blue Team Controls Not Mapped to Findings

The following Blue Team controls were assessed as properly implemented and do not correspond to any finding:

- BT-004: Passkey/WebAuthn Authentication -- Properly implemented
- BT-006: Device Flow OAuth Security -- Properly implemented
- BT-007: Authorization Admin Access Control -- Properly implemented
- BT-011: Mass Assignment Protection -- Properly implemented
- BT-012: SQL Injection Prevention -- Properly implemented
- BT-015: XSS Prevention QR Code -- Properly implemented
- BT-016: Credential Storage Integration Secrets -- Properly implemented
- BT-017: Credential Storage Switch Passwords -- Properly implemented
- BT-022: File Upload Security Logo -- Properly implemented
- BT-027: Error Handling -- Properly implemented
- BT-038: Integration Config Merge -- Properly implemented

## Appendix C: Red Team Findings Not Elevated to Consolidated Report

The following Red Team findings were merged into other consolidated findings or assessed as adequately mitigated:

- RT-005 (Captive Portal API IP Status): Merged into SEC-001 attack chain analysis. The information disclosure is only exploitable when combined with TrustProxies misconfiguration.
- RT-012 (SwitchConfig Credentials in Fillable): Assessed as adequately mitigated by FormRequest validation and encrypted storage. Included in the general mass assignment discussion.
- RT-018 (UserParameter user_id in Fillable): Assessed as adequately mitigated by relationship-based creation patterns.

---

*Report generated 2026-05-09. Valid for 90 days or until significant application changes, whichever comes first.*
