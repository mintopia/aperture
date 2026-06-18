# Auth System Cleanup Design

**Date:** 2026-04-13  
**Status:** Approved  
**Scope:** Remove Socialite auth, unify login through captive portal device flow, simplify data model

---

## Problem Statement

The authentication system has two parallel device flow implementations (old Borealis session-based and new AuthProviderInterface cache-based), plus Socialite OAuth for Discord/Steam. This causes:

1. **QR code doesn't display** — `login/provider.blade.php` extends `layout.public` which doesn't exist
2. **Device code 410 timeout immediately** — `CaptivePortalController::index()` calls `initiateDeviceFlow()` but never stores the device code in Cache; when JS polls `DeviceAuthController::poll()`, `Cache::get()` returns null → 410
3. **Login page shows Discord/Steam buttons** — unauthenticated users should go directly to the QR code device flow
4. **Socialite code is dead weight** — only device flow auth should remain

## Approach

**Merge everything into CaptivePortalController.** Remove DeviceAuthController, all Socialite code, AuthProvider model, and UserAuthentication model. Unify all login through `/captive`. Extract user find-or-create logic into a dedicated service.

## Root Cause: Immediate 410

`CaptivePortalController::index()` calls `$authProvider->initiateDeviceFlow()` directly and passes the device code to the Blade view. But it never calls `Cache::put('device_flow:'.$deviceCode, ...)`. The JS then polls `GET /auth/device/poll/{deviceCode}`, where `DeviceAuthController::poll()` does `Cache::get('device_flow:'.$deviceCode)` — the key was never set, so it returns null immediately, resulting in a 410 response.

**Fix:** The merged `CaptivePortalController::index()` will store the device flow state in Cache after calling `initiateDeviceFlow()`.

---

## Routing

### After Cleanup

| Route | Method | Handler | Purpose |
|-------|--------|---------|---------|
| `GET /captive` | `CaptivePortalController::index` | Render QR code page + store device flow in Cache |
| `GET /captive/poll/{deviceCode}` | `CaptivePortalController::poll` | Poll device flow, create/find user on success, log in |
| `GET /captive/interstitial` | `CaptivePortalController::interstitial` | Post-auth interstitial (unchanged) |
| `GET /login` | Redirect to `/captive` | Backward compatibility |
| `GET /logout` | `AuthController::logout` | Session destroy + redirect |

### Removed Routes

- `POST /auth/device/initiate`
- `GET /auth/device/poll/{deviceCode}`
- `GET /login/check`
- `GET /login/{provider:code}`
- `GET /login/{provider:code}/redirect`
- `GET /login/{provider:code}/return`

### Auth Middleware

`app/Http/Middleware/Authenticate.php` `redirectTo()` changes from `route('login')` to `route('captive.index')`.

---

## Data Model Changes

### Migration

1. **Drop** `auth_providers` table
2. **Drop** `user_authentications` table
3. **Add columns to `users` table:**
   - `external_id` — string, nullable, unique index
   - `access_token` — text, nullable (encrypted via model cast)
   - `refresh_token` — text, nullable (encrypted via model cast)
   - `token_expires_at` — timestamp, nullable
   - `avatar_url` — string, nullable

### User Model Changes

- Add `external_id`, `access_token`, `refresh_token`, `token_expires_at`, `avatar_url` to model
- Add encrypted casts for `access_token` and `refresh_token`
- Add `hidden` for `access_token` and `refresh_token`
- Remove `authentications()` relationship

---

## New Service: DeviceFlowUserService

Extracted from `DeviceAuthController::findOrCreateUser()`. Injected into `CaptivePortalController::poll()`.

```php
class DeviceFlowUserService
{
    public function findOrCreateFromDeviceFlow(UserInfo $userInfo, AuthResult $result): User
    {
        return DB::transaction(function () use ($userInfo, $result) {
            // 1. Find by external_id
            $user = User::whereExternalId($userInfo->id)->first();

            // 2. Or find by email
            if (!$user && $userInfo->email) {
                $user = User::whereEmail($userInfo->email)->first();
            }

            // 3. Or create new
            if (!$user) {
                $user = new User;
                $user->email = (string) $userInfo->email;
            }

            // 4. Update fields
            $user->nickname = $userInfo->nickname;
            $user->avatar_url = $userInfo->avatarUrl;
            $user->external_id = $userInfo->id;
            $user->access_token = $result->accessToken;
            $user->refresh_token = (string) $result->refreshToken;
            $user->token_expires_at = now()->addSeconds($result->expiresIn);
            $user->save();

            return $user;
        });
    }
}
```

---

## CaptivePortalController After Merge

```php
class CaptivePortalController extends Controller
{
    // Render QR code page + store device flow state in Cache
    public function index(Request $request, AuthProviderInterface $authProvider): View
    {
        $deviceFlow = $authProvider->initiateDeviceFlow(config('aperture.borealis.scope'));

        // THE FIX: store in cache so poll() can find it
        Cache::put(
            'device_flow:' . $deviceFlow->deviceCode,
            ['status' => 'pending', 'ip' => $request->getClientIp()],
            now()->addSeconds($deviceFlow->expiresIn)
        );

        // ... render view with QR code (existing logic) ...
    }

    // Poll device flow status (merged from DeviceAuthController::poll)
    public function poll(
        Request $request,
        string $deviceCode,
        AuthProviderInterface $authProvider,
        DeviceFlowUserService $userService
    ): JsonResponse {
        $flowData = Cache::get('device_flow:' . $deviceCode);
        if (!$flowData) {
            return response()->json(['status' => 'expired'], 410);
        }

        if ($flowData['status'] === 'complete') {
            return response()->json(['status' => 'complete', 'redirect' => route('home')]);
        }

        $result = $authProvider->pollDeviceFlow($deviceCode);
        if (!$result instanceof AuthResult) {
            return response()->json(['status' => 'pending']);
        }

        $userInfo = $authProvider->getUserInfo($result->accessToken);
        $user = $userService->findOrCreateFromDeviceFlow($userInfo, $result);

        $ip = $user->addIp($flowData['ip']);
        if (!$user->blocked) {
            $ip->allow(true);
        }

        Auth::login($user);

        Cache::put('device_flow:' . $deviceCode, array_merge($flowData, [
            'status' => 'complete',
            'user_id' => $user->id,
        ]), now()->addMinutes(5));

        return response()->json(['status' => 'complete', 'redirect' => route('home')]);
    }

    // Unchanged
    public function interstitial(): View { ... }
}
```

### Captive Login View Update

`captive/login.blade.php` JS polling URL changes from `/auth/device/poll/{deviceCode}` to `/captive/poll/{deviceCode}`.

---

## Files to Delete

| File | Reason |
|------|--------|
| `app/Http/Controllers/DeviceAuthController.php` | Merged into CaptivePortalController |
| `app/Services/Auth/DiscordAuth.php` | Socialite implementation |
| `app/Services/Auth/SteamAuth.php` | Socialite implementation |
| `app/Services/Interfaces/AuthBackendInterface.php` | Socialite interface |
| `app/Services/Borealis/DeviceCode.php` | Old session-based device flow |
| `app/Services/Borealis/DeviceCodeStatus.php` | Old flow enum |
| `app/Http/Resources/DeviceCodeResource.php` | Old flow API resource |
| `app/Models/AuthProvider.php` | Removed model |
| `app/Models/UserAuthentication.php` | Merged into User |
| `database/seeders/AuthProviderSeeder.php` | Discord/Steam seeder |
| `database/factories/AuthProviderFactory.php` | Factory for removed model |
| `database/factories/UserAuthenticationFactory.php` | Factory for removed model |
| `resources/views/login.blade.php` | Old login page with Socialite buttons |
| `resources/views/login/provider.blade.php` | Old broken QR view |

## Files to Create

| File | Purpose |
|------|---------|
| `app/Services/Auth/DeviceFlowUserService.php` | User find-or-create from device flow result |
| Migration: consolidate auth schema | Drop auth_providers + user_authentications, add columns to users |

## Files to Modify

| File | Change |
|------|--------|
| `app/Http/Controllers/CaptivePortalController.php` | Add Cache::put in index, add poll method |
| `app/Http/Controllers/AuthController.php` | Strip to logout() only |
| `app/Http/Middleware/Authenticate.php` | redirectTo → `route('captive.index')` |
| `routes/web.php` | New route structure, remove old auth routes |
| `app/Providers/EventServiceProvider.php` | Remove SocialiteWasCalled listeners |
| `app/Providers/AppServiceProvider.php` | Keep AuthProviderInterface → BorealisDeviceFlowService binding |
| `config/services.php` | Remove discord/steam entries |
| `config/aperture.php` | Add `borealis.scope` config key |
| `database/seeders/DatabaseSeeder.php` | Remove AuthProviderSeeder reference |
| `app/Models/User.php` | Add auth columns, encrypted casts, remove authentications() |
| `composer.json` | Remove laravel/socialite, socialiteproviders/* |
| `resources/views/captive/login.blade.php` | Update poll URL to `/captive/poll/` |

## Kept As-Is

| Component | Reason |
|-----------|--------|
| `BorealisService` | Core API client for Borealis — used by BorealisDeviceFlowService |
| `BorealisDeviceFlowService` | Implements AuthProviderInterface — single auth adapter |
| `AuthProviderInterface` | Clean interface for device flow operations |
| `AuthResult`, `DeviceFlowResponse`, `UserInfo` DTOs | Used by the interface |
| `config/aperture.php` borealis section | Runtime config for Borealis API |
| `resources/views/captive/login.blade.php` | Working QR code UI (minor URL change) |
| `resources/views/layouts/captive.blade.php` | Layout for captive pages |

## Composer Changes

Remove from `require`:
- `laravel/socialite`
- `socialiteproviders/discord`
- `socialiteproviders/manager`
- `socialiteproviders/steam`

Run `composer remove` for these packages.

---

## Scope Note

The `AuthProvider` model's `class` field previously pointed at Socialite-based classes (`DiscordAuth`, `SteamAuth`). With the model removed, the provider concept is implicit — there's one provider (Borealis), configured via `config/aperture.php`. The `initiateDeviceFlow()` scope parameter can use a fixed string (e.g., `'borealis'` or the event name) rather than a DB-driven provider code.

The `CaptivePortalController::index()` currently does `AuthProvider::whereEnabled(true)->first()` to get the provider code for `initiateDeviceFlow()`. With AuthProvider removed, the scope parameter will come from config: `config('aperture.borealis.scope')`. This config key must be added to `config/aperture.php` with a corresponding `BOREALIS_SCOPE` env var (defaulting to `'discord'` for backward compatibility with existing Borealis configurations).

---

## Testing Strategy

- Unit tests for `DeviceFlowUserService` (find by external_id, find by email, create new, update tokens)
- Feature tests for `CaptivePortalController::index` (renders QR, stores cache)
- Feature tests for `CaptivePortalController::poll` (pending, complete, expired, user creation)
- Feature test for `/login` redirect to `/captive`
- Feature test for auth middleware redirect to captive
- Verify removed routes return 404
- Migration test (up and down)
