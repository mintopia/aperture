# Auth System Cleanup — Implementation Plan

**Spec:** `docs/superpowers/specs/2026-04-13-auth-cleanup-design.md`  
**Date:** 2026-04-13

---

## Phases

Work is ordered so tests pass at the end of each phase. Each phase is self-contained and can be committed independently.

---

### Phase 1: Database Migration + User Model Update

**Goal:** Add auth fields to `users`, drop old tables.

**Steps:**
1. Create migration: add `external_id`, `access_token`, `refresh_token`, `token_expires_at`, `avatar_url` to `users`
2. Create migration: drop `user_authentications` table  
3. Create migration: drop `auth_providers` table
4. Update `User` model: add new columns, encrypted casts, hidden array, remove `authentications()` relationship
5. Update `UserFactory` to support new fields
6. Write unit tests for User model changes (encrypted casts work, external_id uniqueness)

**Files created:**
- `database/migrations/YYYY_MM_DD_HHMMSS_add_auth_fields_to_users_table.php`
- `database/migrations/YYYY_MM_DD_HHMMSS_drop_user_authentications_table.php`
- `database/migrations/YYYY_MM_DD_HHMMSS_drop_auth_providers_table.php`

**Files modified:**
- `app/Models/User.php`
- `database/factories/UserFactory.php`

**Tests:**
- Run existing test suite to verify no regressions from model changes (tests that reference AuthProvider/UserAuthentication will break — expected, fixed in Phase 3)

---

### Phase 2: Create DeviceFlowUserService

**Goal:** Extract user find-or-create logic into a testable service.

**Steps:**
1. Create `app/Services/Auth/DeviceFlowUserService.php`
2. Write unit tests for all paths:
   - Find by `external_id`
   - Find by `email` (when `external_id` doesn't match)
   - Create new user
   - Update tokens on existing user
   - Update avatar_url and nickname
   - Transaction rollback on failure

**Files created:**
- `app/Services/Auth/DeviceFlowUserService.php`
- `tests/Unit/Services/Auth/DeviceFlowUserServiceTest.php`

**Tests:**
- `php artisan test --compact --filter=DeviceFlowUserServiceTest`

---

### Phase 3: Merge Auth Into CaptivePortalController + Route Cleanup

**Goal:** CaptivePortalController gets poll method, routes updated, old auth code removed.

**Steps:**
1. Add `borealis.scope` to `config/aperture.php` (with `BOREALIS_SCOPE` env var, default `'discord'`)
2. Update `CaptivePortalController::index()`:
   - Replace `AuthProvider::whereEnabled(true)->first()` with `config('aperture.borealis.scope')`
   - Add `Cache::put()` after `initiateDeviceFlow()`
3. Add `CaptivePortalController::poll()` method (from DeviceAuthController)
4. Update `routes/web.php`:
   - Add `GET /captive/poll/{deviceCode}` route
   - Add `GET /login` redirect to `/captive`
   - Remove old auth routes (`/login/{provider}`, `/login/check`, `/auth/device/*`, etc.)
5. Update `app/Http/Middleware/Authenticate.php`: redirect to `route('captive.index')`
6. Strip `AuthController` to just `logout()`
7. Update `captive/login.blade.php`: JS poll URL → `/captive/poll/`
8. Update feature tests:
   - Update `CaptivePortalViewTest` for new cache behavior
   - Write new tests for `CaptivePortalController::poll()`
   - Write test for `/login` redirect
   - Write test for auth middleware redirect
   - Update `AuthenticateMiddlewareTest`
   - Update `RedirectIfAuthenticatedTest`

**Files modified:**
- `config/aperture.php`
- `app/Http/Controllers/CaptivePortalController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Middleware/Authenticate.php`
- `routes/web.php`
- `resources/views/captive/login.blade.php`
- `tests/Feature/CaptivePortalViewTest.php`
- `tests/Feature/AuthenticateMiddlewareTest.php`
- `tests/Feature/RedirectIfAuthenticatedTest.php`

**Files created:**
- `tests/Feature/CaptivePortalPollTest.php`

**Tests:**
- `php artisan test --compact --filter=CaptivePortal`
- `php artisan test --compact --filter=AuthenticateMiddleware`
- `php artisan test --compact --filter=AuthController`

---

### Phase 4: Remove Socialite + Old Auth Code

**Goal:** Delete all Socialite code, old device flow code, old models, old tests.

**Steps:**
1. Delete files (see spec "Files to Delete" section):
   - `app/Http/Controllers/DeviceAuthController.php`
   - `app/Services/Auth/DiscordAuth.php`
   - `app/Services/Auth/SteamAuth.php`
   - `app/Services/Interfaces/AuthBackendInterface.php`
   - `app/Services/Borealis/DeviceCode.php`
   - `app/Services/Borealis/DeviceCodeStatus.php`
   - `app/Http/Resources/DeviceCodeResource.php`
   - `app/Models/AuthProvider.php`
   - `app/Models/UserAuthentication.php`
   - `database/seeders/AuthProviderSeeder.php`
   - `database/factories/AuthProviderFactory.php`
   - `database/factories/UserAuthenticationFactory.php`
   - `resources/views/login.blade.php`
   - `resources/views/login/provider.blade.php`
2. Delete test files for removed code:
   - `tests/Feature/DeviceAuthControllerTest.php`
   - `tests/Feature/AuthControllerTest.php` (rewrite minimal version for logout only)
   - `tests/Unit/Models/AuthProviderTest.php`
   - `tests/Unit/Models/UserAuthenticationTest.php`
   - `tests/Unit/Services/Auth/SteamAuthTest.php`
   - `tests/Unit/Services/Auth/DiscordAuthTest.php`
   - `tests/Unit/Providers/AuthServiceProviderTest.php`
3. Update `app/Providers/EventServiceProvider.php`: remove SocialiteWasCalled listeners + imports
4. Update `config/services.php`: remove discord/steam entries
5. Update `database/seeders/DatabaseSeeder.php`: remove AuthProviderSeeder reference
6. Write minimal `AuthController` test (logout only)
7. Write tests verifying removed routes return 404

**Files deleted:** ~20 files (code + tests)

**Files modified:**
- `app/Providers/EventServiceProvider.php`
- `config/services.php`
- `database/seeders/DatabaseSeeder.php`

**Files created:**
- `tests/Feature/AuthLogoutTest.php` (minimal test for logout)

**Tests:**
- `php artisan test --compact` (full suite — verify nothing references deleted code)

---

### Phase 5: Remove Socialite Composer Packages

**Goal:** Remove packages from composer.json and lock.

**Steps:**
1. `composer remove laravel/socialite socialiteproviders/discord socialiteproviders/manager socialiteproviders/steam`
2. Run full test suite

**Tests:**
- `php artisan test --compact`

---

### Phase 6: Quality Gates

**Goal:** Pass all quality checks.

**Steps:**
1. `vendor/bin/pint --dirty --format agent`
2. `vendor/bin/phpstan analyse`
3. `vendor/bin/rector process --dry-run`
4. `php artisan test --compact` with coverage
5. Fix any issues found

---

## Execution Order

```
Phase 1 (migration + User model)
    ↓
Phase 2 (DeviceFlowUserService)
    ↓
Phase 3 (controller merge + routes)
    ↓
Phase 4 (delete old code)
    ↓
Phase 5 (composer remove)
    ↓
Phase 6 (quality gates)
```

Each phase is independently committable. Phases 1-3 build the new system while old code still exists. Phase 4 cleans up. Phase 5 removes packages. Phase 6 ensures quality.

## Risk Notes

- **Existing tests referencing AuthProvider/UserAuthentication** will fail after Phase 1 migration. These tests are deleted in Phase 4. During Phases 2-3, focus on new/updated tests; old tests can be temporarily skipped if needed.
- **E2E test** (`tests/e2e/captive.spec.js`) may need updating for new poll URL — verify in Phase 3.
- **Borealis scope default** (`'discord'`) maintains backward compat with existing Borealis API configurations.
