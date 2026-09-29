<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seatpicker;

use App\Services\Seatpicker\SeatpickerApiService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SeatpickerApiServiceTest extends TestCase
{
    public function test_returns_events_on_success(): void
    {
        Http::fake([
            'control.example.com/api/v1/events' => Http::response([
                'data' => [
                    ['code' => 'event-1', 'name' => 'LAN Party 2026'],
                    ['code' => 'event-2', 'name' => 'Summer Bash'],
                ],
            ], 200),
        ]);

        $result = SeatpickerApiService::getEvents([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-api-key',
            'verify_ssl' => '1',
        ]);

        $this->assertCount(2, $result['events']);
        $this->assertSame('event-1', $result['events'][0]['code']);
        $this->assertSame('LAN Party 2026', $result['events'][0]['name']);
        $this->assertSame('event-2', $result['events'][1]['code']);
        $this->assertSame('Summer Bash', $result['events'][1]['name']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_returns_error_on_failed_request(): void
    {
        Http::fake([
            'control.example.com/api/v1/events' => Http::response('Unauthorized', 401),
        ]);

        $result = SeatpickerApiService::getEvents([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'bad-key',
            'verify_ssl' => '1',
        ]);

        $this->assertSame([], $result['events']);
        $this->assertArrayHasKey('error', $result);
        $this->assertNotEmpty($result['error']);
    }

    public function test_returns_error_when_endpoint_not_configured(): void
    {
        $result = SeatpickerApiService::getEvents([
            'endpoint' => '',
            'api_key' => 'test-key',
        ]);

        $this->assertSame([], $result['events']);
        $this->assertStringContainsString('endpoint is not configured', $result['error']);
    }

    public function test_sends_accept_json_and_bearer_token(): void
    {
        Http::fake([
            'control.example.com/api/v1/events' => Http::response(['data' => []], 200),
        ]);

        SeatpickerApiService::getEvents([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'my-token',
            'verify_ssl' => '1',
        ]);

        Http::assertSent(function ($request): bool {
            $auth = $request->header('Authorization');
            $accept = $request->header('Accept');

            return ! empty($auth)
                && $auth[0] === 'Bearer my-token'
                && ! empty($accept)
                && str_contains($accept[0], 'application/json');
        });
    }

    public function test_fetches_events_with_verify_ssl_disabled(): void
    {
        Http::fake([
            'control.example.com/api/v1/events' => Http::response(['data' => [
                ['code' => 'evt', 'name' => 'Event'],
            ]], 200),
        ]);

        $result = SeatpickerApiService::getEvents([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
            'verify_ssl' => '0',
        ]);

        $this->assertCount(1, $result['events']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_returns_error_on_exception(): void
    {
        Http::fake(fn () => throw new RuntimeException('Connection refused'));

        $result = SeatpickerApiService::getEvents([
            'endpoint' => 'https://control.example.com',
            'api_key' => 'test-key',
            'verify_ssl' => '1',
        ]);

        $this->assertSame([], $result['events']);
        $this->assertStringContainsString('Connection refused', $result['error']);
    }
}
