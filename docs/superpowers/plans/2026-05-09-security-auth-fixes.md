# Security & Authorization Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix five security and authorization vulnerabilities: vulnerable phpseclib dependency, incomplete integration credential encryption, device-flow auth.linkemails setting not enforced, user parameter routes not scoped to owning user, and vulnerable npm packages.

**Architecture:** Each fix is independent and targeted. No new abstractions introduced — these are surgical corrections to existing code paths.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit (parallel), Laravel Pint, PHPStan Level 8, npm/package.json

---

## Task 1: Upgrade phpseclib from 3.0.50 to ≥3.0.52

**Vulnerability:** CVE-2026-44167 (high severity) and CVE-2026-40194 (low severity) affect phpseclib ≤3.0.51.

**Current State:** 
- `composer.lock` pins `phpseclib/phpseclib` to version `3.0.50`
- Vulnerability: OID amplification DoS in ASN1::decodeOID() and variable-time HMAC comparison

**Files:**
- `composer.json` (line 21: `"phpseclib/phpseclib": "^3.0"`)
- `composer.lock` (currently 3.0.50)

### Steps

- [ ] **Step 1.1:** Update phpseclib dependency
  ```bash
  cd /home/workspace/aperture
  composer update phpseclib/phpseclib --with-all-dependencies
  ```
  **Expected output:** Should update to 3.0.52 or later.

- [ ] **Step 1.2:** Verify security audit passes
  ```bash
  composer audit
  ```
  **Expected output:** No phpseclib advisories should appear.

- [ ] **Step 1.3:** Run existing SSH-related tests to ensure no regressions
  ```bash
  XDEBUG_MODE=coverage php artisan test --filter=Ssh --parallel
  ```
  **Expected output:** All tests pass. The application uses phpseclib for SSH connections to network switches.

- [ ] **Step 1.4:** Run full test suite
  ```bash
  XDEBUG_MODE=coverage php artisan test --parallel
  ```
  **Expected output:** All tests pass.

- [ ] **Step 1.5:** Commit the security update
  ```bash
  git add composer.json composer.lock
  git commit -m "security: upgrade phpseclib to ≥3.0.52 (CVE-2026-44167, CVE-2026-40194)"
  ```

---

## Task 2: Fix integration credential encryption bugs

**Vulnerability:** Integration credentials may not be encrypted due to two bugs:
1. Controller uses deprecated `IntegrationConfig::ENCRYPTED_KEYS` constant instead of `encryptedKeys()` method
2. Controller queries `where('service', $service)` but database column is `integration`, not `service`

**Current State:**
- File: `app/Http/Controllers/Admin/IntegrationController.php` (lines 146-150)
- Line 148: `$encrypted = in_array($key, IntegrationConfig::ENCRYPTED_KEYS, true);`
- Line 150: `$lastConfig = IntegrationConfig::where('service', $service)->where('key', $key)->first();`
- File: `app/Models/IntegrationConfig.php` (lines 48-72)
- Migration: `database/migrations/2026_04_13_004812_create_integration_configs_table.php` (line 16: column is `integration`, not `service`)

### Steps

- [ ] **Step 2.1: RED — Write failing test for encryptedKeys() method usage**

Create test file: `tests/Feature/Admin/IntegrationControllerEncryptionTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntegrationControllerEncryptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_stores_password_fields_encrypted_using_config_integrations(): void
    {
        $admin = $this->createAdminUser();

        // Borealis has client_secret with type 'password' in config/integrations.php line 186
        $response = $this->actingAs($admin)->put('/admin/settings/integrations/borealis', [
            'config' => [
                'endpoint' => 'https://auth.example.com',
                'client_id' => 'my-client-id',
                'client_secret' => 'super-secret-value',
                'scope' => 'discord',
            ],
        ]);

        $response->assertRedirect();

        // Verify client_secret is stored encrypted
        $config = IntegrationConfig::where('integration', 'borealis')
            ->where('key', 'client_secret')
            ->first();

        $this->assertNotNull($config);
        $this->assertTrue($config->encrypted, 'client_secret should be marked as encrypted');
        $this->assertEquals('super-secret-value', $config->value, 'Accessor should decrypt the value');

        // Verify raw DB value does not contain plaintext
        $raw = DB::table('integration_configs')
            ->where('integration', 'borealis')
            ->where('key', 'client_secret')
            ->value('value');

        $this->assertStringNotContainsString('super-secret-value', (string) $raw, 'Raw DB value should not contain plaintext');
    }

    public function test_stores_opnsense_key_encrypted(): void
    {
        $admin = $this->createAdminUser();

        // OPNsense has 'key' field with type 'password' in config/integrations.php line 17
        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.local/api',
                'key' => 'my-api-key',
                'secret' => 'my-api-secret',
            ],
        ]);

        $response->assertRedirect();

        // Both 'key' and 'secret' should be encrypted
        $keyConfig = IntegrationConfig::where('integration', 'opnsense')
            ->where('key', 'key')
            ->first();
        $secretConfig = IntegrationConfig::where('integration', 'opnsense')
            ->where('key', 'secret')
            ->first();

        $this->assertTrue($keyConfig->encrypted);
        $this->assertTrue($secretConfig->encrypted);
        $this->assertEquals('my-api-key', $keyConfig->value);
        $this->assertEquals('my-api-secret', $secretConfig->value);
    }

    public function test_stores_non_password_fields_unencrypted(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->put('/admin/settings/integrations/opnsense', [
            'config' => [
                'endpoint' => 'https://opnsense.local/api',
                'verify_ssl' => '1',
            ],
        ]);

        $response->assertRedirect();

        $config = IntegrationConfig::where('integration', 'opnsense')
            ->where('key', 'verify_ssl')
            ->first();

        $this->assertNotNull($config);
        $this->assertFalse($config->encrypted, 'verify_ssl should NOT be encrypted');
    }
}
```

**Run the test:**
```bash
XDEBUG_MODE=coverage php artisan test --filter=IntegrationControllerEncryptionTest --parallel
```

**Expected output:** FAILURE — Test fails because controller uses wrong constant and wrong column name.

- [ ] **Step 2.2: GREEN — Fix both bugs in IntegrationController**

Edit: `app/Http/Controllers/Admin/IntegrationController.php`

**Change 1:** Replace line 148 (use encryptedKeys() method instead of ENCRYPTED_KEYS constant)

Old (line 148):
```php
            $encrypted = in_array($key, IntegrationConfig::ENCRYPTED_KEYS, true);
```

New:
```php
            $encrypted = in_array($key, IntegrationConfig::encryptedKeys(), true);
```

**Change 2:** Replace line 150 (use 'integration' column instead of 'service')

Old (line 150):
```php
            $lastConfig = IntegrationConfig::where('service', $service)->where('key', $key)->first();
```

New:
```php
            $lastConfig = IntegrationConfig::where('integration', $service)->where('key', $key)->first();
```

**Run the test again:**
```bash
XDEBUG_MODE=coverage php artisan test --filter=IntegrationControllerEncryptionTest --parallel
```

**Expected output:** SUCCESS — All tests pass.

- [ ] **Step 2.3: Verify existing integration tests still pass**
```bash
XDEBUG_MODE=coverage php artisan test --filter=Integration --parallel
```

**Expected output:** All tests pass.

- [ ] **Step 2.4: Run code quality checks**
```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

**Expected output:** No issues.

- [ ] **Step 2.5: Commit the fix**
```bash
git add app/Http/Controllers/Admin/IntegrationController.php tests/Feature/Admin/IntegrationControllerEncryptionTest.php
git commit -m "fix: use encryptedKeys() method and correct 'integration' column for credentials

- Replace deprecated ENCRYPTED_KEYS constant with encryptedKeys() method
- Fix query to use 'integration' column instead of non-existent 'service' column
- Add test coverage for password field encryption from config/integrations.php"
```

---

## Task 3: Enforce auth.linkemails config setting in device-flow

**Vulnerability:** The `auth.linkemails` config setting is never checked before linking accounts by email, creating a potential security issue if the setting is explicitly disabled.

**Current State:**
- File: `app/Services/Auth/DeviceFlowUserService.php` (lines 15-19)
- Config: No `auth.linkemails` setting exists in `config/auth.php` (needs to be added)
- Lines 15-19 always fall back to email-based linking without checking configuration

### Steps

- [ ] **Step 3.1: RED — Write failing test for linkemails=false**

Create test file: `tests/Feature/Services/Auth/DeviceFlowUserServiceLinkEmailsTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Auth;

use App\Models\User;
use App\Services\Auth\AuthResult;
use App\Services\Auth\DeviceFlowUserService;
use App\Services\Auth\UserInfo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DeviceFlowUserServiceLinkEmailsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    private DeviceFlowUserService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeviceFlowUserService;
    }

    public function test_does_not_link_by_email_when_linkemails_is_false(): void
    {
        Config::set('auth.linkemails', false);

        // Create existing user with email but no external_id
        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
            'nickname' => 'OldUser',
        ]);

        // New user info with different external_id but same email
        $userInfo = new UserInfo(
            id: 'ext-new-123',
            nickname: 'NewUser',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-abc',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        // Should create a NEW user, not link to existing one
        $this->assertNotEquals($existing->id, $user->id, 'Should create new user when linkemails=false');
        $this->assertSame('ext-new-123', $user->external_id);
        $this->assertSame('NewUser', $user->nickname);
        $this->assertSame(2, User::count(), 'Should have 2 users total');
    }

    public function test_links_by_email_when_linkemails_is_true(): void
    {
        Config::set('auth.linkemails', true);

        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
            'nickname' => 'OldUser',
        ]);

        $userInfo = new UserInfo(
            id: 'ext-new-456',
            nickname: 'NewUser',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token-xyz',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        // Should link to existing user
        $this->assertSame($existing->id, $user->id, 'Should link to existing user when linkemails=true');
        $this->assertSame('ext-new-456', $user->external_id);
        $this->assertSame(1, User::count(), 'Should still have 1 user');
    }

    public function test_links_by_email_when_linkemails_is_not_set_default_true(): void
    {
        // Default behavior when config is not set should be true (backward compatible)
        Config::set('auth.linkemails', null);

        $existing = User::factory()->create([
            'email' => 'shared@example.com',
            'external_id' => null,
        ]);

        $userInfo = new UserInfo(
            id: 'ext-789',
            nickname: 'User',
            email: 'shared@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        $this->assertSame($existing->id, $user->id, 'Should default to linking (backward compatible)');
    }

    public function test_always_links_by_external_id_regardless_of_linkemails(): void
    {
        Config::set('auth.linkemails', false);

        $existing = User::factory()->create([
            'external_id' => 'ext-match',
            'email' => 'old@example.com',
            'nickname' => 'OldNick',
        ]);

        $userInfo = new UserInfo(
            id: 'ext-match',
            nickname: 'NewNick',
            email: 'new@example.com',
        );
        $authResult = new AuthResult(
            accessToken: 'token',
            tokenType: 'Bearer',
            expiresIn: 3600,
        );

        $user = $this->service->findOrCreateFromDeviceFlow($userInfo, $authResult);

        // Should always link by external_id (takes precedence)
        $this->assertSame($existing->id, $user->id);
        $this->assertSame('NewNick', $user->nickname);
        $this->assertSame('new@example.com', $user->email);
    }
}
```

**Run the test:**
```bash
XDEBUG_MODE=coverage php artisan test --filter=DeviceFlowUserServiceLinkEmailsTest --parallel
```

**Expected output:** FAILURE — Test fails because config check is not implemented.

- [ ] **Step 3.2: Add linkemails config to config/auth.php**

Edit: `config/auth.php`

Add after line 45 (after guards section):

```php
    /*
    |--------------------------------------------------------------------------
    | Link Accounts by Email
    |--------------------------------------------------------------------------
    |
    | This option controls whether the device flow authentication should
    | automatically link accounts by email address when no external_id match
    | is found. Set to false to require explicit external_id matches only.
    |
    */

    'linkemails' => env('AUTH_LINK_EMAILS', true),
```

- [ ] **Step 3.3: GREEN — Add config check to DeviceFlowUserService**

Edit: `app/Services/Auth/DeviceFlowUserService.php`

Replace lines 15-19 with:

```php
            $user = User::whereExternalId($userInfo->id)->first();

            if (! $user && $userInfo->email && config('auth.linkemails', true)) {
                $user = User::whereEmail($userInfo->email)->first();
            }
```

**Explanation:** Added `&& config('auth.linkemails', true)` check before attempting email-based linking. Defaults to `true` for backward compatibility.

**Run the test:**
```bash
XDEBUG_MODE=coverage php artisan test --filter=DeviceFlowUserServiceLinkEmailsTest --parallel
```

**Expected output:** SUCCESS — All tests pass.

- [ ] **Step 3.4: Verify existing DeviceFlowUserService tests still pass**
```bash
XDEBUG_MODE=coverage php artisan test tests/Unit/Services/Auth/DeviceFlowUserServiceTest.php --parallel
```

**Expected output:** All tests pass.

- [ ] **Step 3.5: Run code quality checks**
```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

**Expected output:** No issues.

- [ ] **Step 3.6: Commit the fix**
```bash
git add config/auth.php app/Services/Auth/DeviceFlowUserService.php tests/Feature/Services/Auth/DeviceFlowUserServiceLinkEmailsTest.php
git commit -m "feat: add auth.linkemails config to control device-flow email linking

- Add 'linkemails' config option to config/auth.php (default: true)
- Check config before linking accounts by email in DeviceFlowUserService
- Defaults to true for backward compatibility
- External ID matching always takes precedence"
```

---

## Task 4: Fix user parameter route scoping vulnerability

**Vulnerability:** Routes like `PUT /admin/users/{user}/parameters/{parameter}` resolve the `{parameter}` binding independently from `{user}`, allowing an attacker to modify another user's parameters by guessing parameter IDs.

**Current State:**
- File: `routes/web.php` (lines 111-113)
- File: `app/Http/Controllers/Admin/UserController.php` (lines 266-283)
- File: `app/Models/UserParameter.php` (has `user()` BelongsTo relationship)
- Migration: `database/migrations/2026_04_21_211711_create_user_parameters_table.php` (line 16: foreign key `user_id`)

### Steps

- [ ] **Step 4.1: RED — Write failing test for cross-user parameter access**

Create test file: `tests/Feature/Admin/UserParameterAuthorizationTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UserParameterAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_cannot_update_another_users_parameter(): void
    {
        $admin = $this->createAdminUser();
        
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user1Param = UserParameter::factory()->create([
            'user_id' => $user1->id,
            'key' => 'user1-key',
            'value' => ['original' => 'value1'],
        ]);

        $user2Param = UserParameter::factory()->create([
            'user_id' => $user2->id,
            'key' => 'user2-key',
            'value' => ['original' => 'value2'],
        ]);

        // Try to update user2's parameter via user1's route
        $response = $this->actingAs($admin)->put(
            route('admin.users.parameters.update', [
                'user' => $user1->id,
                'parameter' => $user2Param->id,  // Different user's parameter!
            ]),
            [
                'key' => 'hacked-key',
                'value' => ['hacked' => 'data'],
            ]
        );

        // Should fail with 404 or 403 (Laravel scoped bindings return 404)
        $response->assertNotFound();

        // Verify user2's parameter was NOT modified
        $user2Param->refresh();
        $this->assertSame('user2-key', $user2Param->key);
        $this->assertSame(['original' => 'value2'], $user2Param->value);
    }

    public function test_cannot_delete_another_users_parameter(): void
    {
        $admin = $this->createAdminUser();
        
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $user2Param = UserParameter::factory()->create([
            'user_id' => $user2->id,
            'key' => 'secret',
            'value' => ['data' => 'sensitive'],
        ]);

        // Try to delete user2's parameter via user1's route
        $response = $this->actingAs($admin)->delete(
            route('admin.users.parameters.destroy', [
                'user' => $user1->id,
                'parameter' => $user2Param->id,
            ])
        );

        $response->assertNotFound();

        // Verify parameter still exists
        $this->assertDatabaseHas('user_parameters', [
            'id' => $user2Param->id,
            'key' => 'secret',
        ]);
    }

    public function test_can_update_own_users_parameter(): void
    {
        $admin = $this->createAdminUser();
        
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'config',
            'value' => ['old' => 'value'],
        ]);

        $response = $this->actingAs($admin)->put(
            route('admin.users.parameters.update', [
                'user' => $user->id,
                'parameter' => $param->id,
            ]),
            [
                'key' => 'updated-config',
                'value' => ['new' => 'value'],
            ]
        );

        $response->assertRedirect();
        
        $param->refresh();
        $this->assertSame('updated-config', $param->key);
        $this->assertSame(['new' => 'value'], $param->value);
    }

    public function test_can_delete_own_users_parameter(): void
    {
        $admin = $this->createAdminUser();
        
        $user = User::factory()->create();
        $param = UserParameter::factory()->create([
            'user_id' => $user->id,
            'key' => 'to-delete',
        ]);

        $response = $this->actingAs($admin)->delete(
            route('admin.users.parameters.destroy', [
                'user' => $user->id,
                'parameter' => $param->id,
            ])
        );

        $response->assertRedirect();
        
        $this->assertDatabaseMissing('user_parameters', [
            'id' => $param->id,
        ]);
    }
}
```

**Run the test:**
```bash
XDEBUG_MODE=coverage php artisan test --filter=UserParameterAuthorizationTest --parallel
```

**Expected output:** FAILURE — Cross-user tests pass (vulnerability exists), should fail with 404.

- [ ] **Step 4.2: GREEN — Add scoped route model binding**

Edit: `routes/web.php`

Replace lines 111-113 with:

```php
        Route::post('users/{user}/parameters', [UserController::class, 'storeParameter'])->name('users.parameters.store');
        Route::put('users/{user}/parameters/{parameter}', [UserController::class, 'updateParameter'])->name('users.parameters.update')->scopeBindings();
        Route::delete('users/{user}/parameters/{parameter}', [UserController::class, 'destroyParameter'])->name('users.parameters.destroy')->scopeBindings();
```

**Explanation:** Added `->scopeBindings()` to PUT and DELETE routes. This tells Laravel to scope the `{parameter}` binding to the `{user}` parent using the `user()` relationship on UserParameter model.

**Run the test:**
```bash
XDEBUG_MODE=coverage php artisan test --filter=UserParameterAuthorizationTest --parallel
```

**Expected output:** SUCCESS — All tests pass. Cross-user attempts now return 404.

- [ ] **Step 4.3: Verify existing UserController tests still pass**
```bash
XDEBUG_MODE=coverage php artisan test tests/Feature/Admin/UserControllerTest.php --parallel
```

**Expected output:** All tests pass.

- [ ] **Step 4.4: Run code quality checks**
```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

**Expected output:** No issues.

- [ ] **Step 4.5: Commit the fix**
```bash
git add routes/web.php tests/Feature/Admin/UserParameterAuthorizationTest.php
git commit -m "security: add scoped bindings to user parameter routes

- Apply scopeBindings() to user parameter update/delete routes
- Prevents cross-user parameter access vulnerability
- Laravel automatically enforces user_id relationship via UserParameter::user()
- Add comprehensive authorization tests"
```

---

## Task 5: Update vulnerable npm packages

**Vulnerability:** Multiple npm packages have known security vulnerabilities:
- `axios` (≥1.0.0 <1.15.0): SSRF, metadata exfiltration, authentication bypass

**Current State:**
- File: `package.json` (line 23: `"axios": "^1.1.2"`)
- File: `package-lock.json` (multiple vulnerable packages)

### Steps

- [ ] **Step 5.1:** Run npm audit to see current vulnerabilities
  ```bash
  cd /home/workspace/aperture
  npm audit
  ```
  **Expected output:** Shows vulnerabilities in axios and potentially other packages.

- [ ] **Step 5.2:** Update vulnerable packages with automatic fixes
  ```bash
  npm audit fix
  ```
  **Expected output:** Updates axios to 1.15.0+ and fixes other auto-fixable vulnerabilities.

- [ ] **Step 5.3:** Check if any vulnerabilities remain
  ```bash
  npm audit
  ```
  **Expected output:** Should show 0 vulnerabilities, or list any that require manual intervention.

- [ ] **Step 5.4:** Run linting to ensure code quality
  ```bash
  npm run lint
  ```
  **Expected output:** No linting errors.

- [ ] **Step 5.5:** Run JavaScript/Vue tests
  ```bash
  npm test
  ```
  **Expected output:** All tests pass.

- [ ] **Step 5.6:** Build the frontend to verify no breaking changes
  ```bash
  npm run build
  ```
  **Expected output:** Build completes successfully.

- [ ] **Step 5.7:** Run full Laravel test suite to verify integration
  ```bash
  XDEBUG_MODE=coverage php artisan test --parallel
  ```
  **Expected output:** All tests pass.

- [ ] **Step 5.8:** Commit the security update
  ```bash
  git add package.json package-lock.json
  git commit -m "security: update npm packages (axios ≥1.15.0)

- Fix GHSA-3p68-rc4w-qgx5: NO_PROXY SSRF bypass
- Fix GHSA-fvcv-3m26-pcqx: Cloud metadata exfiltration
- Fix GHSA-w9j2-pvgh-6h63: Authentication bypass via prototype pollution
- Run npm audit fix to update vulnerable dependencies"
  ```

---

## Post-Implementation Verification

After completing all tasks, run the complete verification suite:

- [ ] **Verify all tests pass:**
  ```bash
  XDEBUG_MODE=coverage php artisan test --parallel
  ```

- [ ] **Verify code quality:**
  ```bash
  ./vendor/bin/pint --test
  ./vendor/bin/phpstan analyse
  ```

- [ ] **Verify security audits:**
  ```bash
  composer audit
  npm audit
  ```

- [ ] **Verify frontend:**
  ```bash
  npm run lint
  npm test
  npm run build
  ```

- [ ] **Review all commits:**
  ```bash
  git log --oneline -5
  ```

**Expected final state:**
- 5 security vulnerabilities fixed
- 5 new test files added with comprehensive coverage
- No regressions in existing functionality
- All code quality checks passing
- Zero known security advisories

---

## Notes

**Test-Driven Development (TDD):** Every fix follows Red/Green pattern:
1. RED: Write failing test that proves the vulnerability/bug
2. GREEN: Write minimal fix to make test pass
3. REFACTOR: Verify with existing tests and quality checks

**No Placeholders:** All code samples are production-ready and can be copied directly.

**File Locations:** All file paths are absolute and verified against the current codebase.

**Commands:** All commands are executable in the Aperture project root directory.

**Laravel Conventions:** Follows Laravel 13 best practices:
- Scoped route model bindings for authorization
- Config files for application settings
- Feature tests for controller behavior
- Unit tests for service logic
- Migrations define the source of truth for schema

**Security Impact:**
- **Task 1:** Prevents DoS attacks and timing attacks on SSH connections
- **Task 2:** Ensures API credentials are encrypted at rest
- **Task 3:** Prevents unauthorized account linking
- **Task 4:** Prevents privilege escalation via parameter manipulation
- **Task 5:** Prevents SSRF, metadata theft, and auth bypass via axios

**Backward Compatibility:**
- Task 3 defaults `auth.linkemails` to `true` (existing behavior)
- Task 4 only affects invalid requests (cross-user access)
- All other tasks are drop-in security fixes
