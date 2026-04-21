<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\EnsureAccountSecurityVerified;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class EnsureAccountSecurityVerifiedTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_401_json_when_user_is_null(): void
    {
        $middleware = new EnsureAccountSecurityVerified;

        $request = Request::create('/passkeys/register/options', 'POST');
        $request->setUserResolver(fn (): null => null);
        $request->headers->set('Accept', 'application/json');

        $response = $middleware->handle($request, fn (): Response => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Authentication required.', $data['message']);
    }

    public function test_redirects_non_json_request_when_user_has_password_but_not_verified(): void
    {
        $this->withSession([]);

        $middleware = new EnsureAccountSecurityVerified;

        $user = User::factory()->withPassword('secret123')->create();

        $request = Request::create('/account/settings/password', 'PUT');
        $request->setUserResolver(fn () => $user);
        $request->setLaravelSession($this->app->make('session.store'));

        $response = $middleware->handle($request, fn (): Response => new Response('ok'));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('account', $response->getTargetUrl());
    }
}
