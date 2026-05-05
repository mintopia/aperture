<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seatpicker;

use App\Models\IntegrationConfig;
use App\Services\Seatpicker\SeatpickerSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeatpickerSyncServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private SeatpickerSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeatpickerSyncService;
    }

    public function test_reads_api_key_from_integration_config(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');

        Http::fake(['*' => Http::response([
            'data' => [],
            'meta' => ['last_page' => 1],
        ], 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');

            return ! empty($auth) && $auth[0] === 'Bearer test-api-key';
        });
    }

    public function test_returns_not_configured_when_api_key_missing(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');
        // api_key is not set — service should treat integration as not configured

        $result = $this->service->sync();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not configured', $result->message);
    }

    public function test_config_validation_uses_api_key_not_api_token(): void
    {
        $validation = config('integrations.seatpicker.validation');

        $this->assertIsArray($validation);
        $this->assertArrayHasKey('api_key', $validation);
        $this->assertArrayNotHasKey('api_token', $validation);
    }

    // -------------------------------------------------------
    // verify_ssl support
    // -------------------------------------------------------

    public function test_syncs_with_verify_ssl_disabled(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');
        IntegrationConfig::setValue('seatpicker', 'verify_ssl', '0');

        Http::fake(['*' => Http::response([
            'data' => [],
            'meta' => ['last_page' => 1],
        ], 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
    }

    // -------------------------------------------------------
    // Error message detail
    // -------------------------------------------------------

    public function test_error_message_includes_url_and_response_body(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');

        Http::fake(['*' => Http::response('{"message":"Not Found"}', 404)]);

        $result = $this->service->sync();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('404', $result->message);
        $this->assertStringContainsString('/api/v1/tickets', $result->message);
        $this->assertStringContainsString('Not Found', $result->message);
    }

    public function test_error_message_includes_url_on_server_error(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'my-lan');

        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $result = $this->service->sync();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('500', $result->message);
        $this->assertStringContainsString('/api/v1/tickets', $result->message);
    }

    public function test_sends_event_code_as_query_parameter(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'my-lan');

        Http::fake(['*' => Http::response([
            'data' => [],
            'meta' => ['last_page' => 1],
        ], 200)]);

        $this->service->sync();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/tickets')
            && str_contains($request->url(), 'event=my-lan'));
    }

    public function test_syncs_with_verify_ssl_defaulting_to_true(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');

        Http::fake(['*' => Http::response([
            'data' => [],
            'meta' => ['last_page' => 1],
        ], 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
    }
}
