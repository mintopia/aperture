# ADR 008: Single routes/web.php File

Status: Accepted
Date: 2026-05-09

## Context
Code quality review finding L1 identified that `routes/web.php` is a ~195-line monolithic route file covering all application contexts: authentication, admin, portal, account, captive portal, and passkeys. The recommendation was to split into contextual files (`admin.php`, `portal.php`, `auth.php`, etc.) loaded from a route service provider.

## Decision
Accept the current single-file structure. At ~195 lines with clear comment-section grouping, `routes/web.php` is still readable and navigable. The overhead of splitting into multiple files (additional `require` or service provider configuration, more files to search when debugging routing issues) does not provide meaningful benefit at the current scale. The grouping by middleware context (guest, auth, admin) is more semantically meaningful than grouping by purpose, and the current structure makes the middleware hierarchy immediately visible.

If the file grows beyond ~350 lines or the number of route contexts increases significantly, this decision should be revisited.

## Consequences
- All web routes live in one file, which will become harder to navigate as the application grows.
- The current size (~195 lines) does not justify the split.
- This decision should be reviewed if the file exceeds ~350 lines or if a clear ownership boundary emerges that warrants separation.

## Supersedes
N/A
