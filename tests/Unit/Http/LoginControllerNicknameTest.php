<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\LoginController;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit test verifying that LoginController no longer creates users on first login.
 *
 * The first-user bootstrap was removed in SEC-002 (setup wizard). This test
 * confirms that authenticate() throws a validation error when no matching user
 * exists, even when no users are in the database at all.
 */
class LoginControllerNicknameTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    public function test_authenticate_rejects_credentials_when_no_users_exist(): void
    {
        $controller = new LoginController;

        $request = Request::create('/login', 'POST', ['email' => 'admin@example.com', 'password' => 'secret123']);
        $request->setLaravelSession($this->app->make('session.store'));

        $this->expectException(ValidationException::class);

        $controller->authenticate($request);

        $this->assertSame(0, User::count());
    }
}
