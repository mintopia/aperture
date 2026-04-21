<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\PasskeyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for PasskeyController branches that are unreachable via HTTP
 * due to auth middleware running before the controller method.
 */
class PasskeyControllerUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_returns_403_when_user_is_null(): void
    {
        // Covers PasskeyController line 53: the null-user guard in destroy()
        // Via HTTP this is blocked by auth middleware before the controller runs,
        // so we call the controller method directly.
        $controller = new PasskeyController;

        $request = Request::create('/passkeys/some-credential-id', 'DELETE');
        $request->setUserResolver(fn (): null => null);

        $response = $controller->destroy($request, 'some-credential-id');

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    public function test_register_saves_attested_request_and_returns_success(): void
    {
        // Covers PasskeyController lines 25-27: register() calls $request->save() and returns JSON
        $controller = new PasskeyController;

        $attestedRequest = Mockery::mock(AttestedRequest::class);
        $attestedRequest->shouldReceive('save')->once()->andReturn('credential-id-123');

        $response = $controller->register($attestedRequest);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function test_login_returns_success_when_webauthn_authenticatable_user_returned(): void
    {
        // Covers PasskeyController lines 38-42: login() when $user instanceof WebAuthnAuthenticatable
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
        // Covers PasskeyController lines 44-46: login() when $user is NOT a WebAuthnAuthenticatable
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
