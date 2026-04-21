# IPv6 Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable automatic IPv6 address detection and registration for portal users via two methods: DHCP MAC matching (same MAC = same user) and JWT-verified IPv6 detection from a browser-fetched token.

**Architecture:** The DHCP MAC matching enhances the existing `ScanNetworkDevices` job to associate auto-allowed IPs with the MAC owner's user account via `UserIpAddress`. The JWT flow replaces the current raw-IP submission: the user's browser fetches an IPv6-only endpoint that returns an RS256 JWT, submits it to our API, which verifies the signature via a JWKS endpoint and extracts the IPv6 from the `sub` claim. A new admin settings page configures both features.

**Tech Stack:** Laravel 12, PHP 8.5, `firebase/php-jwt` (JWT verification), Vue 3 + Inertia.js (admin settings page), Vitest (JS tests), PHPUnit (PHP tests)

---

## File Structure

| Action | File | Responsibility |
|--------|------|----------------|
| Create | `app/Services/Ipv6JwtService.php` | Verify RS256 JWT via JWKS, extract IPv6 from `sub` claim |
| Create | `app/Http/Controllers/Admin/Ipv6DetectionSettingsController.php` | Admin settings page for IPv6 detection config |
| Create | `resources/js/Pages/Admin/Settings/Ipv6Detection.vue` | Admin settings form UI |
| Create | `tests/Feature/Services/Ipv6JwtServiceTest.php` | PHP tests for JWT service |
| Create | `tests/Feature/Admin/Ipv6DetectionSettingsTest.php` | PHP tests for admin settings |
| Create | `tests/js/Pages/Admin/Settings/Ipv6Detection.spec.js` | JS tests for admin settings page |
| Modify | `app/Jobs/ScanNetworkDevices.php` | Add user association to `autoAllowIp` |
| Modify | `app/Http/Controllers/PortalController.php` | Accept JWT token, verify via service |
| Modify | `resources/views/portal.blade.php` | Submit JWT token instead of raw IP |
| Modify | `resources/js/utils/ipv6-detection.js` | Return raw JWT token string |
| Modify | `resources/js/Components/Admin/SettingsNav.vue` | Enable IPv6 Detection nav item |
| Modify | `routes/web.php` | Add IPv6 detection settings routes |
| Modify | `tests/Feature/Jobs/ScanNetworkDevicesTest.php` | Add user association test |
| Modify | `tests/Feature/PortalControllerTest.php` | Update IPv6 tests for JWT flow |
| Modify | `tests/js/utils/ipv6-detection.spec.js` | Update for JWT token return |
| Modify | `tests/js/Components/Admin/SettingsNav.spec.js` | Update for enabled nav item |

---

### Task 1: Install firebase/php-jwt

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Install the package**

```bash
composer require firebase/php-jwt
```

- [ ] **Step 2: Verify installation**

```bash
php artisan tinker --execute "echo class_exists('Firebase\JWT\JWT') ? 'OK' : 'FAIL';"
```

Expected: `OK`

- [ ] **Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat: add firebase/php-jwt for IPv6 JWT verification"
```

---

### Task 2: DHCP MAC Matching — User Association

When `ScanNetworkDevices` auto-allows an IP for a known MAC, it must also create a `UserIpAddress` record linking the new IP to the MAC owner's user account. Currently `autoAllowIp` creates the IP and allows it but doesn't associate the user.

**Files:**
- Modify: `app/Jobs/ScanNetworkDevices.php:107-123`
- Test: `tests/Feature/Jobs/ScanNetworkDevicesTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Jobs/ScanNetworkDevicesTest.php`:

```php
public function test_auto_allow_associates_ip_with_mac_owner_user(): void
{
    $user = \App\Models\User::factory()->create();
    $mac = MacAddress::factory()->allowed()->create([
        'mac_address' => 'AA:BB:CC:DD:EE:FF',
        'user_id' => $user->id,
    ]);

    $resolver = Mockery::mock(MacAddressResolverInterface::class);
    $resolver->shouldReceive('resolveIpToMac')->andReturnNull();
    $this->app->instance(MacAddressResolverInterface::class, $resolver);

    $dhcp = Mockery::mock(DhcpInterface::class);
    $dhcp->shouldReceive('getLeases')->andReturn(collect([
        new DhcpLease(ip: '10.0.0.55', mac: 'aa:bb:cc:dd:ee:ff', hostname: 'phone', expires: ''),
    ]));
    $this->app->instance(DhcpInterface::class, $dhcp);

    $inventory = Mockery::mock(NetworkInventoryInterface::class);
    $inventory->shouldReceive('getArpTable')->andReturn(collect([]));
    $this->app->instance(NetworkInventoryInterface::class, $inventory);

    (new ScanNetworkDevices)->handle();

    $this->assertDatabaseHas('ip_addresses', [
        'address' => '10.0.0.55',
        'allowed' => true,
        'mac_address_id' => $mac->id,
    ]);
    $this->assertDatabaseHas('user_ip_addresses', [
        'user_id' => $user->id,
    ]);
    $ip = IpAddress::where('address', '10.0.0.55')->first();
    $this->assertDatabaseHas('user_ip_addresses', [
        'user_id' => $user->id,
        'ip_address_id' => $ip->id,
    ]);
}
```

Add the `User` import at the top if not already present.

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=test_auto_allow_associates_ip_with_mac_owner_user
```

Expected: FAIL — `user_ip_addresses` table has no matching record.

- [ ] **Step 3: Implement user association in autoAllowIp**

Replace the `autoAllowIp` method in `app/Jobs/ScanNetworkDevices.php` (lines 107-123):

```php
private function autoAllowIp(string $ipAddress, MacAddress $macAddress): void
{
    if ($macAddress->user_id !== null && $macAddress->user) {
        $ip = $macAddress->user->addIp($ipAddress);
    } else {
        $ip = IpAddress::where('address', $ipAddress)->first();
        if ($ip === null) {
            $ip = new IpAddress;
            $ip->address = $ipAddress;
            $ip->last_seen_at = now()->toDateTimeString();
            $ip->save();
        }
    }

    $ip->mac_address_id = (int) $macAddress->id; // @phpstan-ignore assign.propertyType
    $ip->save();

    if (! $ip->allowed) {
        $ip->allow();
    }
}
```

The key change: when the MAC has a `user_id`, use `$user->addIp()` which creates both the `IpAddress` record and the `UserIpAddress` junction record. For MACs without a user (e.g. Xbox consoles), fall back to the original create-only behavior.

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=ScanNetworkDevices
```

Expected: ALL tests pass (8 existing + 1 new).

- [ ] **Step 5: Run formatting**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ScanNetworkDevices.php tests/Feature/Jobs/ScanNetworkDevicesTest.php
git commit -m "feat: associate auto-allowed IPs with MAC owner's user account"
```

---

### Task 3: Ipv6JwtService — JWT Verification

Create a service that verifies an RS256-signed JWT using a JWKS endpoint and extracts the IPv6 address from the `sub` claim. Uses `firebase/php-jwt` and caches the JWKS for 1 hour.

**Files:**
- Create: `app/Services/Ipv6JwtService.php`
- Create: `tests/Feature/Services/Ipv6JwtServiceTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/Ipv6JwtServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Services\Ipv6JwtService;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Ipv6JwtServiceTest extends TestCase
{
    private string $jwksUrl = 'https://ipv6.example.com/.well-known/jwks.json';

    private \OpenSSLAsymmetricKey $privateKey;

    private string $publicKeyPem;

    private string $kid = 'test-key-1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyPair, $privatePem);
        $this->privateKey = openssl_pkey_get_private($privatePem);
        $details = openssl_pkey_get_details($keyPair);
        $this->publicKeyPem = $details['key'];
    }

    private function makeJwks(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        return [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => $this->kid,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $n,
                'e' => $e,
            ]],
        ];
    }

    private function makeJwt(string $sub, ?string $kid = null): string
    {
        return JWT::encode(
            ['sub' => $sub, 'iat' => time(), 'exp' => time() + 300],
            $this->privateKey,
            'RS256',
            $kid ?? $this->kid,
        );
    }

    public function test_verifies_valid_jwt_and_extracts_ipv6(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $result = $service->verifyAndExtract($jwt, $this->jwksUrl);

        $this->assertSame('2001:db8::1', $result);
    }

    public function test_rejects_jwt_with_ipv4_in_sub(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('192.168.1.1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a valid IPv6');

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }

    public function test_rejects_jwt_with_invalid_signature(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);

        // Create a JWT signed with a different key
        $otherKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($otherKey, $otherPem);
        $badJwt = JWT::encode(
            ['sub' => '2001:db8::1', 'iat' => time(), 'exp' => time() + 300],
            openssl_pkey_get_private($otherPem),
            'RS256',
            $this->kid,
        );

        $this->expectException(\Firebase\JWT\SignatureInvalidException::class);

        $service->verifyAndExtract($badJwt, $this->jwksUrl);
    }

    public function test_caches_jwks_response(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);

        $service->verifyAndExtract($this->makeJwt('2001:db8::1'), $this->jwksUrl);
        $service->verifyAndExtract($this->makeJwt('2001:db8::2'), $this->jwksUrl);

        Http::assertSentCount(1);
    }

    public function test_rejects_expired_jwt(): void
    {
        Http::fake([$this->jwksUrl => Http::response($this->makeJwks())]);

        $service = app(Ipv6JwtService::class);

        $expiredJwt = JWT::encode(
            ['sub' => '2001:db8::1', 'iat' => time() - 600, 'exp' => time() - 300],
            $this->privateKey,
            'RS256',
            $this->kid,
        );

        $this->expectException(\Firebase\JWT\ExpiredException::class);

        $service->verifyAndExtract($expiredJwt, $this->jwksUrl);
    }

    public function test_throws_on_jwks_fetch_failure(): void
    {
        Http::fake([$this->jwksUrl => Http::response('Server Error', 500)]);

        $service = app(Ipv6JwtService::class);
        $jwt = $this->makeJwt('2001:db8::1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to fetch JWKS');

        $service->verifyAndExtract($jwt, $this->jwksUrl);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=Ipv6JwtServiceTest
```

Expected: FAIL — class `Ipv6JwtService` does not exist.

- [ ] **Step 3: Implement Ipv6JwtService**

Create `app/Services/Ipv6JwtService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;

class Ipv6JwtService
{
    public function __construct(
        private readonly Repository $cache,
    ) {}

    /**
     * Verify an RS256 JWT via JWKS and extract the IPv6 address from the sub claim.
     *
     * @throws \InvalidArgumentException  When sub is not a valid IPv6 address
     * @throws \RuntimeException          When JWKS fetch fails
     * @throws \Firebase\JWT\SignatureInvalidException  When signature verification fails
     * @throws \Firebase\JWT\ExpiredException  When JWT has expired
     */
    public function verifyAndExtract(string $jwt, string $jwksUrl): string
    {
        $jwksData = $this->fetchJwks($jwksUrl);
        $keys = JWK::parseKeySet($jwksData);
        $decoded = JWT::decode($jwt, $keys);

        $ipv6 = $decoded->sub ?? '';
        if (! filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new \InvalidArgumentException("JWT sub claim is not a valid IPv6 address: {$ipv6}");
        }

        return $ipv6;
    }

    private function fetchJwks(string $jwksUrl): array
    {
        $cacheKey = 'ipv6_jwks:' . md5($jwksUrl);

        return $this->cache->remember($cacheKey, 3600, function () use ($jwksUrl): array {
            $response = Http::timeout(10)->get($jwksUrl);

            if (! $response->successful()) {
                throw new \RuntimeException("Failed to fetch JWKS from {$jwksUrl}: HTTP {$response->status()}");
            }

            return $response->json();
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=Ipv6JwtServiceTest
```

Expected: ALL 6 tests pass.

- [ ] **Step 5: Run formatting and static analysis**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Services/Ipv6JwtService.php tests/Feature/Services/Ipv6JwtServiceTest.php
git commit -m "feat: add Ipv6JwtService for RS256 JWT verification via JWKS"
```

---

### Task 4: PortalController — Accept JWT Token

Modify the `/ipv6` endpoint to accept a JWT token, verify it via `Ipv6JwtService`, and register the extracted IPv6 address. Also pass the JWKS URL to the portal view so the frontend knows JWT mode is active.

**Files:**
- Modify: `app/Http/Controllers/PortalController.php:47-60` and `:13-32`
- Modify: `tests/Feature/PortalControllerTest.php`

- [ ] **Step 1: Write the failing tests**

Replace the existing IPv6 tests and add new ones in `tests/Feature/PortalControllerTest.php`. Add these imports at the top if not present:

```php
use App\Services\Ipv6JwtService;
use Mockery;
```

Replace `test_ipv6_adds_ipv6_address` and `test_ipv6_works_for_blocked_user`:

```php
public function test_ipv6_verifies_jwt_and_registers_address(): void
{
    Queue::fake();
    $user = User::factory()->create(['blocked' => false]);

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->with('valid.jwt.token', 'https://ipv6.example.com/.well-known/jwks.json')
        ->andReturn('2001:db8::1');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $response = $this->actingAs($user)->postJson('/ipv6', [
        'token' => 'valid.jwt.token',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['ip', 'allowed']);
    $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::1']);
}

public function test_ipv6_rejects_invalid_jwt(): void
{
    Queue::fake();
    $user = User::factory()->create(['blocked' => false]);

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->andThrow(new \Firebase\JWT\SignatureInvalidException('bad sig'));
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $response = $this->actingAs($user)->postJson('/ipv6', [
        'token' => 'invalid.jwt.token',
    ]);

    $response->assertStatus(422);
}

public function test_ipv6_returns_503_when_jwks_not_configured(): void
{
    Queue::fake();
    $user = User::factory()->create(['blocked' => false]);

    $response = $this->actingAs($user)->postJson('/ipv6', [
        'token' => 'any.jwt.token',
    ]);

    $response->assertStatus(503);
}

public function test_ipv6_does_not_allow_for_blocked_user(): void
{
    Queue::fake();
    $user = User::factory()->create(['blocked' => true]);

    $jwtService = Mockery::mock(Ipv6JwtService::class);
    $jwtService->shouldReceive('verifyAndExtract')
        ->andReturn('2001:db8::2');
    $this->app->instance(Ipv6JwtService::class, $jwtService);

    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

    $response = $this->actingAs($user)->postJson('/ipv6', [
        'token' => 'valid.jwt.token',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('ip_addresses', ['address' => '2001:db8::2', 'allowed' => false]);
}
```

Also update `test_index_passes_ipv6_config_when_enabled` to assert the JWKS URL is passed:

```php
public function test_index_passes_ipv6_config_when_enabled(): void
{
    Queue::fake();
    IntegrationConfig::setValue('ipv6', 'detection_enabled', '1');
    IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');
    IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');
    $user = User::factory()->create(['blocked' => false]);

    $response = $this->actingAs($user)->get('/');
    $response->assertStatus(200);
    $response->assertViewHas('ipv6DetectionEnabled', true);
    $response->assertViewHas('ipv6DetectionEndpoint', 'https://{random}.ipv6.example.com');
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=PortalControllerTest
```

Expected: New tests fail (old `test_ipv6_adds_ipv6_address` and `test_ipv6_works_for_blocked_user` were replaced, new tests expect JWT flow).

- [ ] **Step 3: Implement the JWT endpoint**

Replace `app/Http/Controllers/PortalController.php` entirely:

```php
<?php

namespace App\Http\Controllers;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Services\Ipv6JwtService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PortalController extends Controller
{
    public function index(Request $request): View
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($clientIp);
        if (! $user->blocked) {
            $ip->allow(true);
        }

        $dbConfig = IntegrationConfig::getAll('ipv6');
        $ipv6DetectionEnabled = (bool) ($dbConfig['detection_enabled'] ?? false);
        $ipv6DetectionEndpoint = $dbConfig['detection_endpoint'] ?? '';

        return view('portal', [
            'ip' => $ip,
            'ipv6DetectionEnabled' => $ipv6DetectionEnabled,
            'ipv6DetectionEndpoint' => $ipv6DetectionEndpoint,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $clientIp = (string) $request->getClientIp();
        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($clientIp);

        return response()->json((object) [
            'ip' => $ip->address,
            'allowed' => (bool) $ip->allowed,
        ]);
    }

    public function ipv6(Request $request, Ipv6JwtService $jwtService): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $dbConfig = IntegrationConfig::getAll('ipv6');
        $jwksUrl = $dbConfig['jwks_url'] ?? '';

        if ($jwksUrl === '') {
            return response()->json(['error' => 'IPv6 detection not configured'], 503);
        }

        try {
            $ipv6 = $jwtService->verifyAndExtract($request->input('token'), $jwksUrl);
        } catch (Throwable $e) {
            return response()->json(['error' => 'Invalid token'], 422);
        }

        /** @var User $user */
        $user = $request->user();
        $ip = $user->addIp($ipv6);
        if (! $user->blocked) {
            $ip->allow(true);
        }

        return response()->json((object) [
            'ip' => $ip->address,
            'allowed' => (bool) $ip->allowed,
        ]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=PortalControllerTest
```

Expected: ALL tests pass.

- [ ] **Step 5: Run formatting**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PortalController.php tests/Feature/PortalControllerTest.php
git commit -m "feat: accept JWT token for IPv6 detection, verify via JWKS"
```

---

### Task 5: Frontend — JWT Token Flow

Update the portal blade template and the `ipv6-detection.js` utility to submit a raw JWT token instead of a parsed IP address.

**Files:**
- Modify: `resources/views/portal.blade.php:100-118`
- Modify: `resources/js/utils/ipv6-detection.js`
- Modify: `tests/js/utils/ipv6-detection.spec.js`

- [ ] **Step 1: Update ipv6-detection.js to return JWT token**

Replace the content of `resources/js/utils/ipv6-detection.js`:

```javascript
export async function detectIpv6(endpoint, timeout = 5000) {
    if (!endpoint) return null;

    const url = endpoint.replace('{random}', crypto.randomUUID());
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeout);

    try {
        const response = await fetch(url, { signal: controller.signal });
        if (!response.ok) return null;

        const token = (await response.text()).trim();

        return token.length > 0 ? token : null;
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
    }
}
```

Key change: reads response as `text()` (JWT string) instead of `json()`, no longer validates for colons.

- [ ] **Step 2: Update tests for ipv6-detection.js**

Replace the content of `tests/js/utils/ipv6-detection.spec.js`:

```javascript
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { detectIpv6 } from '@/utils/ipv6-detection';

vi.stubGlobal('crypto', { randomUUID: () => 'test-uuid' });

describe('detectIpv6', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('returns JWT token when endpoint responds', async () => {
        fetch.mockResolvedValue({
            ok: true,
            text: () => Promise.resolve('eyJhbGciOiJSUzI1NiJ9.payload.signature'),
        });

        const result = await detectIpv6('https://{random}.test.example.com');

        expect(fetch).toHaveBeenCalledWith(
            'https://test-uuid.test.example.com',
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        expect(result).toBe('eyJhbGciOiJSUzI1NiJ9.payload.signature');
    });

    it('returns null when response is not ok', async () => {
        fetch.mockResolvedValue({ ok: false });

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBeNull();
    });

    it('returns null when response is empty', async () => {
        fetch.mockResolvedValue({
            ok: true,
            text: () => Promise.resolve(''),
        });

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBeNull();
    });

    it('returns null on fetch error', async () => {
        fetch.mockRejectedValue(new Error('Network error'));

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBeNull();
    });

    it('returns null when endpoint is empty', async () => {
        const result = await detectIpv6('');
        expect(result).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('returns null when endpoint is null', async () => {
        const result = await detectIpv6(null);
        expect(result).toBeNull();
    });

    it('trims whitespace from token', async () => {
        fetch.mockResolvedValue({
            ok: true,
            text: () => Promise.resolve('  eyJ.token.here  '),
        });

        const result = await detectIpv6('https://test.example.com');
        expect(result).toBe('eyJ.token.here');
    });
});
```

- [ ] **Step 3: Run JS tests to verify**

```bash
npx vitest run tests/js/utils/ipv6-detection.spec.js
```

Expected: ALL 7 tests pass.

- [ ] **Step 4: Update portal.blade.php IPv6 detection script**

Replace lines 100-118 in `resources/views/portal.blade.php` (the `@if($ipv6DetectionEnabled ...)` block):

```javascript
        @if($ipv6DetectionEnabled && $ipv6DetectionEndpoint)
        var ipv6Endpoint = '{{ $ipv6DetectionEndpoint }}'.replace('{random}', crypto.randomUUID());
        fetch(ipv6Endpoint)
            .then(response => response.ok ? response.text() : null)
            .then(token => {
                if (token && token.trim().length > 0) {
                    fetch("/ipv6", {
                        method: "POST",
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 'token': token.trim() }),
                    }).then(() => setTimeout(checkStatus, 2000));
                } else {
                    setTimeout(checkStatus, 2000);
                }
            })
            .catch(() => setTimeout(checkStatus, 2000));
        @else
        setTimeout(checkStatus, 2000);
        @endif
```

Key changes: `response.text()` instead of `response.json()`, sends `{token: ...}` instead of `{ipv6: ...}`.

- [ ] **Step 5: Run PHP portal tests to verify blade changes**

```bash
php artisan test --compact --filter=PortalControllerTest
```

Expected: ALL tests pass.

- [ ] **Step 6: Format and commit**

```bash
npx prettier --write resources/js/utils/ipv6-detection.js tests/js/utils/ipv6-detection.spec.js
git add resources/views/portal.blade.php resources/js/utils/ipv6-detection.js tests/js/utils/ipv6-detection.spec.js
git commit -m "feat: update frontend to submit JWT token for IPv6 detection"
```

---

### Task 6: IPv6 Detection Admin Settings Controller

Create the backend controller for the IPv6 Detection admin settings page. Follows the existing pattern from `PortalSettingsController` but uses `IntegrationConfig` model.

**Files:**
- Create: `app/Http/Controllers/Admin/Ipv6DetectionSettingsController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/Admin/Ipv6DetectionSettingsTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/Ipv6DetectionSettingsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\IntegrationConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Ipv6DetectionSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_show_returns_settings_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/Ipv6Detection')
            ->has('settings')
            ->where('settings.detection_enabled', false)
            ->where('settings.detection_endpoint', '')
            ->where('settings.jwks_url', '')
        );
    }

    public function test_show_returns_existing_config(): void
    {
        IntegrationConfig::setValue('ipv6', 'detection_enabled', '1');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', 'https://{random}.ipv6.example.com');
        IntegrationConfig::setValue('ipv6', 'jwks_url', 'https://ipv6.example.com/.well-known/jwks.json');

        $response = $this->actingAs($this->admin)->get('/admin/settings/ipv6-detection');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('settings.detection_enabled', true)
            ->where('settings.detection_endpoint', 'https://{random}.ipv6.example.com')
            ->where('settings.jwks_url', 'https://ipv6.example.com/.well-known/jwks.json')
        );
    }

    public function test_update_saves_settings(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_enabled' => true,
            'detection_endpoint' => 'https://{random}.ipv6.test.com',
            'jwks_url' => 'https://ipv6.test.com/.well-known/jwks.json',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $config = IntegrationConfig::getAll('ipv6');
        $this->assertSame('1', $config['detection_enabled']);
        $this->assertSame('https://{random}.ipv6.test.com', $config['detection_endpoint']);
        $this->assertSame('https://ipv6.test.com/.well-known/jwks.json', $config['jwks_url']);
    }

    public function test_update_validates_urls(): void
    {
        $response = $this->actingAs($this->admin)->put('/admin/settings/ipv6-detection', [
            'detection_enabled' => true,
            'detection_endpoint' => 'not-a-url',
            'jwks_url' => 'also-not-a-url',
        ]);

        $response->assertSessionHasErrors(['detection_endpoint', 'jwks_url']);
    }

    public function test_requires_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings/ipv6-detection')->assertForbidden();
        $this->actingAs($user)->put('/admin/settings/ipv6-detection', [])->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact --filter=Ipv6DetectionSettingsTest
```

Expected: FAIL — route not defined, controller doesn't exist.

- [ ] **Step 3: Create the controller**

```bash
php artisan make:class App/Http/Controllers/Admin/Ipv6DetectionSettingsController --no-interaction
```

Replace the content of `app/Http/Controllers/Admin/Ipv6DetectionSettingsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class Ipv6DetectionSettingsController extends Controller
{
    public function show(): Response
    {
        $config = IntegrationConfig::getAll('ipv6');

        return Inertia::render('Admin/Settings/Ipv6Detection', [
            'settings' => [
                'detection_enabled' => (bool) ($config['detection_enabled'] ?? false),
                'detection_endpoint' => $config['detection_endpoint'] ?? '',
                'jwks_url' => $config['jwks_url'] ?? '',
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'href' => route('admin.home')],
                ['label' => 'Settings'],
                ['label' => 'IPv6 Detection'],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'detection_enabled' => 'required|boolean',
            'detection_endpoint' => 'nullable|url:https|max:500',
            'jwks_url' => 'nullable|url:https|max:500',
        ]);

        IntegrationConfig::setValue('ipv6', 'detection_enabled', $validated['detection_enabled'] ? '1' : '0');
        IntegrationConfig::setValue('ipv6', 'detection_endpoint', $validated['detection_endpoint'] ?? '');
        IntegrationConfig::setValue('ipv6', 'jwks_url', $validated['jwks_url'] ?? '');

        return back()->with('success', 'IPv6 detection settings updated.');
    }
}
```

- [ ] **Step 4: Add routes**

Add to `routes/web.php` inside the admin middleware group, near the other settings routes:

```php
Route::get('/settings/ipv6-detection', [\App\Http\Controllers\Admin\Ipv6DetectionSettingsController::class, 'show'])
    ->name('settings.ipv6-detection');
Route::put('/settings/ipv6-detection', [\App\Http\Controllers\Admin\Ipv6DetectionSettingsController::class, 'update'])
    ->name('settings.ipv6-detection.update');
```

Place these after the `portal` settings routes and before the test/integration routes. Follow the import style used by adjacent routes.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=Ipv6DetectionSettingsTest
```

Expected: ALL 5 tests pass.

- [ ] **Step 6: Run formatting**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/Ipv6DetectionSettingsController.php routes/web.php tests/Feature/Admin/Ipv6DetectionSettingsTest.php
git commit -m "feat: add IPv6 Detection admin settings page backend"
```

---

### Task 7: IPv6 Detection Admin Settings Vue Page

Create the Vue page for the admin IPv6 Detection settings form. Follows the pattern established by `Portal.vue` and `Event.vue`.

**Files:**
- Create: `resources/js/Pages/Admin/Settings/Ipv6Detection.vue`
- Create: `tests/js/Pages/Admin/Settings/Ipv6Detection.spec.js`

- [ ] **Step 1: Create the Vue page**

Create `resources/js/Pages/Admin/Settings/Ipv6Detection.vue`:

```vue
<script setup>
import { useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SettingsNav from '@/Components/Admin/SettingsNav.vue';
import FormField from '@/Components/UI/FormField.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
});

const form = useForm({
    detection_enabled: props.settings?.detection_enabled ?? false,
    detection_endpoint: props.settings?.detection_endpoint ?? '',
    jwks_url: props.settings?.jwks_url ?? '',
});

function submit() {
    form.put(route('admin.settings.ipv6-detection.update'));
}
</script>

<template>
    <SettingsNav>
        <h1
            data-testid="page-title"
            class="font-heading mb-2 text-[32px] font-bold tracking-[-0.03em] leading-[1.1] text-[var(--color-text)]"
            :style="{ fontVariationSettings: '\'opsz\' 48' }"
        >
            IPv6 Detection
        </h1>

        <form class="mt-6 space-y-4" data-testid="ipv6-settings-form" @submit.prevent="submit">
            <FormField label="Enable Detection" name="detection_enabled" :error="form.errors.detection_enabled">
                <label class="relative inline-flex cursor-pointer items-center gap-3">
                    <input
                        v-model="form.detection_enabled"
                        data-testid="detection-enabled-toggle"
                        type="checkbox"
                        class="peer sr-only"
                        :true-value="true"
                        :false-value="false"
                    />
                    <span
                        class="h-5 w-9 rounded-full bg-[var(--color-border)] transition-colors after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform after:content-[''] peer-checked:bg-[var(--color-primary)] peer-checked:after:translate-x-4"
                    />
                    <span class="text-[13px] text-[var(--color-text-secondary)]">
                        {{ form.detection_enabled ? 'Enabled' : 'Disabled' }}
                    </span>
                </label>
            </FormField>

            <FormField
                label="Detection Endpoint"
                name="detection_endpoint"
                :error="form.errors.detection_endpoint"
            >
                <input
                    v-model="form.detection_endpoint"
                    data-testid="detection-endpoint-input"
                    type="url"
                    placeholder="https://{random}.ipv6.example.com"
                    class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-[13px] text-[var(--color-text)] outline-none transition-colors focus:border-[var(--color-primary)]"
                />
            </FormField>

            <FormField label="JWKS URL" name="jwks_url" :error="form.errors.jwks_url">
                <input
                    v-model="form.jwks_url"
                    data-testid="jwks-url-input"
                    type="url"
                    placeholder="https://ipv6.example.com/.well-known/jwks.json"
                    class="w-full rounded-md border border-[var(--color-border)] bg-[var(--color-input-bg)] px-3 py-2 text-[13px] text-[var(--color-text)] outline-none transition-colors focus:border-[var(--color-primary)]"
                />
            </FormField>

            <div class="pt-2">
                <button
                    data-testid="save-button"
                    type="submit"
                    :disabled="form.processing"
                    class="rounded-md bg-[var(--color-primary)] px-4 py-2 text-[13px] font-semibold text-white transition-colors hover:bg-[var(--color-primary-hover)] disabled:opacity-50"
                >
                    Save Settings
                </button>
            </div>
        </form>
    </SettingsNav>
</template>
```

- [ ] **Step 2: Write the tests**

Create `tests/js/Pages/Admin/Settings/Ipv6Detection.spec.js`:

```javascript
import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import Ipv6Detection from '@/Pages/Admin/Settings/Ipv6Detection.vue';

const mockPut = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        name: 'Link',
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
    usePage: () => ({ url: '/admin/settings/ipv6-detection' }),
    useForm: (data) => ({
        ...data,
        errors: {},
        processing: false,
        put: mockPut,
    }),
}));

globalThis.route = (name) => {
    const base = 'https://aperture.local.js42.io';
    if (name === 'admin.settings.integrations') return `${base}/admin/settings/integrations`;
    if (name === 'admin.settings.ipv6-detection.update') return `${base}/admin/settings/ipv6-detection`;
    return `${base}/admin/settings/${name.replace('admin.settings.', '')}`;
};

describe('Ipv6Detection.vue', () => {
    function mountComponent(settings = {}) {
        return mount(Ipv6Detection, {
            props: {
                settings: {
                    detection_enabled: false,
                    detection_endpoint: '',
                    jwks_url: '',
                    ...settings,
                },
            },
        });
    }

    beforeEach(() => {
        mockPut.mockReset();
    });

    it('renders page title', () => {
        const wrapper = mountComponent();
        expect(wrapper.get('[data-testid="page-title"]').text()).toBe('IPv6 Detection');
    });

    it('renders form with all fields', () => {
        const wrapper = mountComponent();

        expect(wrapper.find('[data-testid="detection-enabled-toggle"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="detection-endpoint-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="jwks-url-input"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="save-button"]').exists()).toBe(true);
    });

    it('populates form with existing settings', () => {
        const wrapper = mountComponent({
            detection_enabled: true,
            detection_endpoint: 'https://{random}.ipv6.test.com',
            jwks_url: 'https://ipv6.test.com/.well-known/jwks.json',
        });

        const endpointInput = wrapper.get('[data-testid="detection-endpoint-input"]');
        const jwksInput = wrapper.get('[data-testid="jwks-url-input"]');

        expect(endpointInput.element.value).toBe('https://{random}.ipv6.test.com');
        expect(jwksInput.element.value).toBe('https://ipv6.test.com/.well-known/jwks.json');
    });

    it('renders inside SettingsNav', () => {
        const wrapper = mountComponent();
        expect(wrapper.find('[data-testid="settings-nav"]').exists()).toBe(true);
    });

    it('submits form via PUT', async () => {
        const wrapper = mountComponent();

        await wrapper.get('[data-testid="ipv6-settings-form"]').trigger('submit');

        expect(mockPut).toHaveBeenCalledWith('https://aperture.local.js42.io/admin/settings/ipv6-detection');
    });
});
```

- [ ] **Step 3: Run tests**

```bash
npx vitest run tests/js/Pages/Admin/Settings/Ipv6Detection.spec.js
```

Expected: ALL 5 tests pass.

- [ ] **Step 4: Format**

```bash
npx prettier --write resources/js/Pages/Admin/Settings/Ipv6Detection.vue tests/js/Pages/Admin/Settings/Ipv6Detection.spec.js
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Admin/Settings/Ipv6Detection.vue tests/js/Pages/Admin/Settings/Ipv6Detection.spec.js
git commit -m "feat: add IPv6 Detection admin settings page frontend"
```

---

### Task 8: Enable IPv6 Detection in SettingsNav

Remove the `disabled: true` flag from the IPv6 Detection nav item and point it to the new settings route.

**Files:**
- Modify: `resources/js/Components/Admin/SettingsNav.vue:12`
- Modify: `tests/js/Components/Admin/SettingsNav.spec.js`

- [ ] **Step 1: Update the nav item**

In `resources/js/Components/Admin/SettingsNav.vue`, replace:

```javascript
            { label: 'IPv6 Detection', disabled: true },
```

with:

```javascript
            { label: 'IPv6 Detection', href: route('admin.settings.ipv6-detection') },
```

- [ ] **Step 2: Update tests**

In `tests/js/Components/Admin/SettingsNav.spec.js`, add the route to the route mock:

```javascript
if (name === 'admin.settings.ipv6-detection') {
    return `${base}/admin/settings/ipv6-detection`;
}
```

Add this inside the `globalThis.route` function, before the catch-all return.

Update the "renders three services items in the expected order" test — items should now show 3 items but **without** the "Soon" suffix on IPv6:

```javascript
    it('renders three services items in the expected order', () => {
        const wrapper = mountComponent();
        const groups = wrapper.findAll('nav > div > div');
        const serviceItems = groups[0].findAll('[data-testid^="settings-nav-"]');

        expect(serviceItems).toHaveLength(3);
        expect(serviceItems.map((item) => item.text().replace(/\s+/g, ' ').trim())).toEqual([
            'Integrations',
            'IPv6 Detection',
            'DNS Detection Soon',
        ]);
        expect(wrapper.get('[data-testid="settings-nav-integrations"]').attributes('href')).toBe(
            'https://aperture.local.js42.io/admin/settings/integrations',
        );
    });
```

Update the "renders disabled items as spans without href" test — only DNS Detection is now disabled:

```javascript
    it('renders disabled items as spans without href', () => {
        const wrapper = mountComponent();
        const dns = wrapper.get('[data-testid="settings-nav-dns-detection"]');

        expect(dns.element.tagName).toBe('SPAN');
        expect(dns.attributes('href')).toBeUndefined();
        expect(dns.classes()).toContain('cursor-not-allowed');
        expect(dns.classes()).toContain('opacity-40');
    });
```

Update the "renders enabled nav items as clickable links" test — now 5 enabled items (Integrations, IPv6 Detection, Theme, Event, Portal):

```javascript
    it('renders enabled nav items as clickable links', () => {
        const wrapper = mountComponent();
        const enabledItems = wrapper
            .findAll('[data-testid^="settings-nav-"]')
            .filter((item) => item.element.tagName === 'A');

        for (const item of enabledItems) {
            expect(item.attributes('href')).toBeTruthy();
            expect(item.classes()).not.toContain('opacity-40');
            expect(item.classes()).not.toContain('cursor-not-allowed');
        }

        expect(enabledItems).toHaveLength(5);
    });
```

- [ ] **Step 3: Run tests**

```bash
npx vitest run tests/js/Components/Admin/SettingsNav.spec.js
```

Expected: ALL 9 tests pass.

- [ ] **Step 4: Format**

```bash
npx prettier --write resources/js/Components/Admin/SettingsNav.vue tests/js/Components/Admin/SettingsNav.spec.js
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Admin/SettingsNav.vue tests/js/Components/Admin/SettingsNav.spec.js
git commit -m "feat: enable IPv6 Detection in settings navigation"
```

---

## Verification

After all tasks are complete, run the full quality suite:

```bash
bash bin/quality.sh
```

This runs PHP tests, JS tests, PHPStan, Pint, ESLint, and Prettier. All must pass.
