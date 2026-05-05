<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Integration;

use App\Services\Integration\IntegrationTesterRegistry;
use App\Services\Integration\SeatpickerTester;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeatpickerTesterTest extends TestCase
{
    private SeatpickerTester $tester;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tester = new SeatpickerTester;
    }

    public function test_returns_success_on_200_response(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-api-key',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('Connected successfully', $result->message);
        $this->assertSame('GET', $result->requestMethod);
        $this->assertStringContainsString('/api/v1', $result->requestUrl);
        $this->assertSame(200, $result->responseStatus);
    }

    public function test_returns_failure_on_401_response(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'bad-key',
        ]);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connection failed:', $result->message);
    }

    public function test_sends_bearer_token(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'my-seatpicker-token',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth) && $auth[0] === 'Bearer my-seatpicker-token';
        });
    }

    public function test_trims_trailing_slash_from_endpoint(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://control.example.com/',
            'api_key' => 'test-key',
        ]);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'https://control.example.com/api/v1'));
    }

    public function test_uses_endpoint_from_config(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://my-control.example.com',
            'api_key' => 'test-key',
        ]);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), 'my-control.example.com'));
    }

    // -------------------------------------------------------
    // Config assertions
    // -------------------------------------------------------

    public function test_config_capability_is_seat_picker(): void
    {
        $capabilities = config('integrations.seatpicker.capabilities');

        $this->assertIsArray($capabilities);
        $this->assertContains('seat-picker', $capabilities);
        $this->assertNotContains('seat-sync', $capabilities);
    }

    public function test_config_has_api_key_field_not_api_token(): void
    {
        $fields = config('integrations.seatpicker.fields');

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('api_key', $fields);
        $this->assertArrayNotHasKey('api_token', $fields);
    }

    public function test_config_api_key_help_text_does_not_reference_sanctum(): void
    {
        $fields = config('integrations.seatpicker.fields');

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('api_key', $fields);
        $this->assertSame('API key for authentication.', $fields['api_key']['help']);
    }

    // -------------------------------------------------------
    // Accept: application/json header
    // -------------------------------------------------------

    public function test_sends_accept_json_header(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
        ]);

        Http::assertSent(function ($request): bool {
            $accept = $request->header('Accept');

            return ! empty($accept) && str_contains($accept[0], 'application/json');
        });
    }

    // -------------------------------------------------------
    // verify_ssl support
    // -------------------------------------------------------

    public function test_connects_with_verify_ssl_enabled(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
            'verify_ssl' => '1',
        ]);

        $this->assertTrue($result->success);
    }

    public function test_connects_with_verify_ssl_disabled(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
            'verify_ssl' => '0',
        ]);

        $this->assertTrue($result->success);
    }

    public function test_defaults_verify_ssl_to_true_when_missing(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);

        $result = $this->tester->connect([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
        ]);

        $this->assertTrue($result->success);
    }

    // -------------------------------------------------------
    // Config assertions — verify_ssl field
    // -------------------------------------------------------

    public function test_config_has_verify_ssl_field(): void
    {
        $fields = config('integrations.seatpicker.fields');

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('verify_ssl', $fields);
        $this->assertSame('toggle', $fields['verify_ssl']['type']);
        $this->assertSame('Verify SSL', $fields['verify_ssl']['label']);
    }

    public function test_config_has_verify_ssl_validation(): void
    {
        $validation = config('integrations.seatpicker.validation');

        $this->assertIsArray($validation);
        $this->assertArrayHasKey('verify_ssl', $validation);
        $this->assertSame('nullable|string|in:0,1', $validation['verify_ssl']);
    }

    // -------------------------------------------------------
    // Config assertions — event_code select-remote
    // -------------------------------------------------------

    public function test_config_event_code_is_select_remote(): void
    {
        $fields = config('integrations.seatpicker.fields');

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('event_code', $fields);
        $this->assertSame('select-remote', $fields['event_code']['type']);
        $this->assertArrayHasKey('remote_url', $fields['event_code']);
        $this->assertArrayHasKey('remote_label', $fields['event_code']);
        $this->assertArrayHasKey('remote_value', $fields['event_code']);
    }

    public function test_config_event_code_has_correct_remote_url(): void
    {
        $fields = config('integrations.seatpicker.fields');

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('event_code', $fields);
        $this->assertSame('/admin/settings/integrations/seatpicker/events', $fields['event_code']['remote_url']);
    }

    // -------------------------------------------------------
    // Registry assertion
    // -------------------------------------------------------

    public function test_seatpicker_tester_is_registered_in_registry(): void
    {
        $registry = $this->app->make(IntegrationTesterRegistry::class);

        $this->assertTrue($registry->has('seatpicker'));
        $this->assertInstanceOf(SeatpickerTester::class, $registry->get('seatpicker'));
    }
}
