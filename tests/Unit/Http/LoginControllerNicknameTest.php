<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\LoginController;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Unit test covering the LoginController first-login nickname fallback (line 45).
 *
 * The `$nickname = 'admin'` branch fires when Str::before($email, '@') === ''.
 * This can't be reached via HTTP because the `email` validation rule prevents
 * emails starting with '@'. We use a Request subclass that returns a blank local-
 * part email from validate() to cover this defensive branch.
 */
class LoginControllerNicknameTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_first_login_uses_admin_nickname_when_email_local_part_is_empty(): void
    {
        $controller = new LoginController;

        // Build a Request subclass whose validate() skips real validation and returns
        // credentials with a blank local-part email (e.g. '@example.com')
        $request = new class extends Request
        {
            /** @return array<string, string> */
            public function validate(array $rules, ...$params): array
            {
                return ['email' => '@example.com', 'password' => 'secret123'];
            }
        };

        $request = $request::create('/login', 'POST', ['email' => '@example.com', 'password' => 'secret123']);
        $request->setLaravelSession($this->app->make('session.store'));

        // Use a fresh instance of the overriding class bound to the app container
        $overridingRequest = new class extends Request
        {
            /** @return array<string, string> */
            public function validate(array $rules, ...$params): array
            {
                return ['email' => '@example.com', 'password' => 'secret123'];
            }
        };
        $overridingRequest->initialize([], ['email' => '@example.com', 'password' => 'secret123']);
        $overridingRequest->setLaravelSession($this->app->make('session.store'));

        $controller->authenticate($overridingRequest);

        $createdUser = User::query()->where('email', '@example.com')->first();
        $this->assertNotNull($createdUser);
        $this->assertSame('admin', $createdUser->nickname);
    }
}
