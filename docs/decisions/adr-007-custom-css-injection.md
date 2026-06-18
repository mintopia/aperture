# ADR 007: Custom CSS Injected Without Full Sanitisation

Status: Accepted
Date: 2026-05-09

## Context
Security audit finding SEC-007 identified that admin-supplied custom CSS is rendered via `{!! $customCss !!}` (unescaped) directly inside a `<style>` block in `app.blade.php`. The `SafeCss` validation rule blocks specific patterns but does not comprehensively prevent `</style>` tag injection, which would allow breakout to arbitrary HTML/script visible to all users.

## Decision
Accept the risk. The custom CSS field is configurable only by authenticated administrators. An admin who wishes to inject malicious JavaScript into the application already has many other avenues to do so: they control content blocks (rich text editor), custom pages, and can modify integration settings. CSS injection via `</style>` breakout does not represent a meaningfully new attack vector for an admin-level compromise — it is additive to an already privileged position.

The `SafeCss` rule will be enhanced to block `</style>` tokens as a defence-in-depth measure (minor improvement), but a full CSS parser or strict CSP is not warranted given the admin-only access requirement.

## Consequences
- A compromised or malicious admin account can inject arbitrary HTML/script into all pages via the custom CSS field.
- This risk is equivalent to other admin-level capabilities (content blocks, pages) that also allow HTML injection.
- If non-admin users ever gain access to the custom CSS setting, this decision must be revisited and a strict CSS parser or Content Security Policy implemented.
- The `SafeCss` rule should be enhanced to at minimum reject `</style>` substrings as a simple defence-in-depth improvement.

## Supersedes
N/A
