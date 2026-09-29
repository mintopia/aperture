<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\PasskeyController;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Mockery;
use Tests\TestCase;

class PasskeyControllerUnitTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_saves_attested_request_and_returns_success(): void
    {
        $controller = new PasskeyController;

        $attestedRequest = Mockery::mock(AttestedRequest::class);
        $attestedRequest->shouldReceive('save')->once()->andReturn('credential-id-123');
        $attestedRequest->shouldReceive('user')->andReturn(null);
        $attestedRequest->shouldReceive('getClientIp')->andReturn('127.0.0.1');

        $response = $controller->register($attestedRequest);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function test_login_returns_success_when_webauthn_authenticatable_user_returned(): void
    {
        $controller = new PasskeyController;

        $user = Mockery::mock(WebAuthnAuthenticatable::class);

        $assertedRequest = Mockery::mock(AssertedRequest::class);
        $assertedRequest->shouldReceive('login')->once()->andReturn($user);
        $assertedRequest->shouldReceive('session')->andReturnSelf();
        $assertedRequest->shouldReceive('regenerate')->andReturn(true);

        $response = $controller->login($assertedRequest);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('/', $data['redirect']);
    }

    public function test_login_returns_422_when_authentication_fails(): void
    {
        $controller = new PasskeyController;

        $assertedRequest = Mockery::mock(AssertedRequest::class);
        $assertedRequest->shouldReceive('login')->once()->andReturn(null);

        $response = $controller->login($assertedRequest);

        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Authentication failed.', $data['message']);
    }
}
