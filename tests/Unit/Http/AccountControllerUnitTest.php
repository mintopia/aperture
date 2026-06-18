<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\AccountController;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Unit tests for the AccountController null-user guards (PHPStan level-8 safety).
 *
 * These branches are unreachable in normal HTTP flow (auth middleware ensures a
 * user exists) but exist to satisfy PHPStan level 8 null-safety requirements.
 * We cover them by calling the controller methods directly with a request that
 * returns null for user().
 */
class AccountControllerUnitTest extends TestCase
{
    private function makeNullUserRequest(string $uri = '/account/settings', string $method = 'GET', array $data = []): Request
    {
        $request = Request::create($uri, $method, $data);
        $request->setUserResolver(fn (): null => null);

        return $request;
    }

    public function test_show_aborts_403_when_user_is_null(): void
    {
        $this->expectException(HttpException::class);

        $controller = new AccountController;
        $controller->show($this->makeNullUserRequest());
    }

    public function test_verify_aborts_403_when_user_is_null(): void
    {
        $this->expectException(HttpException::class);

        $controller = new AccountController;
        $controller->verify($this->makeNullUserRequest('/account/settings/verify', 'POST', ['password' => 'test']));
    }

    public function test_update_password_aborts_403_when_user_is_null(): void
    {
        $this->expectException(HttpException::class);

        $controller = new AccountController;
        $controller->updatePassword($this->makeNullUserRequest('/account/settings/password', 'PUT', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]));
    }

    public function test_create_password_aborts_403_when_user_is_null(): void
    {
        $this->expectException(HttpException::class);

        $controller = new AccountController;
        $controller->createPassword($this->makeNullUserRequest('/account/password/create', 'POST', [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]));
    }

    public function test_clear_password_aborts_403_when_user_is_null(): void
    {
        $this->expectException(HttpException::class);

        $controller = new AccountController;
        $controller->clearPassword($this->makeNullUserRequest('/account/settings/password', 'DELETE'));
    }
}
