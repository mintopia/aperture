<?php

namespace Tests\Unit\Services\Borealis;

use App\Models\AuthProvider;
use App\Models\User;
use App\Services\Auth\DiscordAuth;
use App\Services\Borealis\DeviceCode;
use App\Services\Borealis\DeviceCodeStatus;
use App\Services\Borealis\RequestException;
use App\Services\BorealisService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use stdClass;
use Tests\TestCase;

class DeviceCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function createDeviceCode(?BorealisService $service = null): DeviceCode
    {
        $service ??= Mockery::mock(BorealisService::class);

        return new DeviceCode($service, 'test-provider');
    }

    public function test_parse_populates_properties(): void
    {
        $code = $this->createDeviceCode();

        $response = new stdClass;
        $response->device_code = 'abc123';
        $response->user_code = 'USER-CODE';
        $response->interval = 5;
        $response->verification_uri = 'https://example.com/verify';
        $response->verification_uri_complete = 'https://example.com/verify?code=USER-CODE';
        $response->expires_in = 600;

        $result = $code->parse($response);

        $this->assertSame($code, $result);
        $this->assertEquals('USER-CODE', $code->userCode);
        $this->assertEquals(5, $code->interval);
        $this->assertEquals('https://example.com/verify', $code->uri);
        $this->assertEquals('https://example.com/verify?code=USER-CODE', $code->fullUri);
        $this->assertInstanceOf(CarbonImmutable::class, $code->expiresAt);
    }

    public function test_has_failed_returns_false_when_external_id_set(): void
    {
        $code = $this->createDeviceCode();
        $code->externalId = '12345';
        $code->expiresAt = CarbonImmutable::now()->subMinutes(10);

        $this->assertFalse($code->hasFailed());
    }

    public function test_has_failed_returns_true_when_expired(): void
    {
        $code = $this->createDeviceCode();
        $code->expiresAt = CarbonImmutable::now()->subMinutes(10);

        $this->assertTrue($code->hasFailed());
    }

    public function test_has_failed_returns_false_when_not_expired(): void
    {
        $code = $this->createDeviceCode();
        $code->expiresAt = CarbonImmutable::now()->addMinutes(10);

        $this->assertFalse($code->hasFailed());
    }

    public function test_check_returns_current_status_when_not_pending(): void
    {
        $code = $this->createDeviceCode();
        $code->status = DeviceCodeStatus::dcsSuccessful;
        $code->expiresAt = CarbonImmutable::now()->addMinutes(10);

        $this->assertEquals(DeviceCodeStatus::dcsSuccessful, $code->check());
    }

    public function test_check_sets_failed_on_non_pending_error(): void
    {
        $service = Mockery::mock(BorealisService::class);
        $service->shouldReceive('check')
            ->once()
            ->andThrow(new RequestException('some_other_error'));

        $code = new DeviceCode($service, 'test-provider');
        $code->status = DeviceCodeStatus::dcsPending;
        $code->expiresAt = CarbonImmutable::now()->addMinutes(10);

        $reflection = new ReflectionClass($code);
        $prop = $reflection->getProperty('deviceCode');
        $prop->setValue($code, 'device-code-123');

        $result = $code->check();
        $this->assertEquals(DeviceCodeStatus::dcsFailed, $result);
    }

    public function test_check_remains_pending_on_authorization_pending(): void
    {
        $service = Mockery::mock(BorealisService::class);
        $service->shouldReceive('check')
            ->once()
            ->andThrow(new RequestException('authorization_pending'));

        $code = new DeviceCode($service, 'test-provider');
        $code->status = DeviceCodeStatus::dcsPending;
        $code->expiresAt = CarbonImmutable::now()->addMinutes(10);

        $reflection = new ReflectionClass($code);
        $prop = $reflection->getProperty('deviceCode');
        $prop->setValue($code, 'device-code-123');

        $result = $code->check();
        $this->assertEquals(DeviceCodeStatus::dcsPending, $result);
    }

    public function test_check_sets_successful_on_valid_response(): void
    {
        $response = new stdClass;
        $response->user = new stdClass;
        $response->user->id = '12345';
        $response->user->nickname = 'testuser';
        $response->user->email = 'test@example.com';
        $response->user->avatar_url = 'https://example.com/avatar.png';
        $response->access_token = 'access-token';
        $response->refresh_token = 'refresh-token';
        $response->expires_in = 3600;

        $service = Mockery::mock(BorealisService::class);
        $service->shouldReceive('check')
            ->once()
            ->andReturn($response);

        $code = new DeviceCode($service, 'test-provider');
        $code->status = DeviceCodeStatus::dcsPending;
        $code->expiresAt = CarbonImmutable::now()->addMinutes(10);

        $reflection = new ReflectionClass($code);
        $prop = $reflection->getProperty('deviceCode');
        $prop->setValue($code, 'device-code-123');

        $result = $code->check();
        $this->assertEquals(DeviceCodeStatus::dcsSuccessful, $result);
        $this->assertEquals('12345', $code->externalId);
        $this->assertEquals('testuser', $code->nickname);
        $this->assertEquals('test@example.com', $code->email);
        $this->assertEquals('access-token', $code->accessToken);
    }

    public function test_sleep_excludes_service(): void
    {
        $code = $this->createDeviceCode();
        $vars = $code->__sleep();
        $this->assertNotContains('service', $vars);
    }

    public function test_wakeup_resolves_service(): void
    {
        $mockService = Mockery::mock(BorealisService::class);
        $this->app->instance(BorealisService::class, $mockService);

        $code = $this->createDeviceCode();
        $code->__wakeup();

        $reflection = new ReflectionClass($code);
        $prop = $reflection->getProperty('service');
        $this->assertSame($mockService, $prop->getValue($code));
    }

    public function test_get_user_returns_null_when_not_successful(): void
    {
        $code = $this->createDeviceCode();
        $code->status = DeviceCodeStatus::dcsPending;

        $this->assertNull($code->getUser());
    }

    public function test_get_user_returns_null_when_provider_not_found(): void
    {
        $code = $this->createDeviceCode();
        $code->status = DeviceCodeStatus::dcsSuccessful;
        $code->externalId = '12345';

        // No provider exists with code 'test-provider'
        $this->assertNull($code->getUser());
    }

    public function test_get_user_creates_new_user_and_auth(): void
    {
        $provider = new AuthProvider;
        $provider->name = 'Test';
        $provider->code = 'test-provider';
        $provider->class = DiscordAuth::class;
        $provider->enabled = true;
        $provider->save();

        $code = $this->createDeviceCode();
        $code->status = DeviceCodeStatus::dcsSuccessful;
        $code->externalId = '12345';
        $code->nickname = 'testuser';
        $code->email = 'test@example.com';
        $code->accessToken = 'token';
        $code->refreshToken = 'refresh';
        $code->accessTokenExpiresAt = CarbonImmutable::now()->addHour();

        $user = $code->getUser();
        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertEquals('testuser', $user->nickname);
        $this->assertDatabaseHas('user_authentications', [
            'auth_provider_id' => $provider->id,
            'external_id' => '12345',
        ]);
    }

    public function test_get_user_reuses_existing_user_by_email(): void
    {
        $provider = new AuthProvider;
        $provider->name = 'Test';
        $provider->code = 'test-provider';
        $provider->class = DiscordAuth::class;
        $provider->enabled = true;
        $provider->save();

        $existingUser = User::factory()->create(['email' => 'existing@example.com']);

        $code = $this->createDeviceCode();
        $code->status = DeviceCodeStatus::dcsSuccessful;
        $code->externalId = '99999';
        $code->nickname = 'updated';
        $code->email = 'existing@example.com';
        $code->accessToken = 'token';
        $code->refreshToken = 'refresh';
        $code->accessTokenExpiresAt = CarbonImmutable::now()->addHour();

        $user = $code->getUser();
        $this->assertEquals($existingUser->id, $user->id);
        $this->assertEquals('updated', $user->nickname);
    }
}
