<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\LoginController;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoginControllerNicknameTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected bool $seedSetupUser = false;

    public function test_authenticate_rejects_credentials_when_no_users_exist(): void
    {
        $controller = new LoginController;

        $request = LoginRequest::createFromBase(Request::create('/login', 'POST', ['email' => 'admin@example.com', 'password' => 'secret123']));
        $request->setLaravelSession($this->app->make('session.store'));
        $request->setContainer($this->app)->validateResolved();

        $this->expectException(ValidationException::class);

        $controller->authenticate($request);

        $this->assertSame(0, User::count());
    }
}
