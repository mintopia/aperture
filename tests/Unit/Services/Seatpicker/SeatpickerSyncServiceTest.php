<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Seatpicker;

use App\Models\IntegrationConfig;
use App\Models\User;
use App\Models\UserParameter;
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

    /**
     * Build a fake API response matching the real Control API format.
     *
     * @param  list<array{email: string, seat_label: string}>  $tickets
     */
    private function fakeTicketsResponse(array $tickets = [], int $totalPages = 1, int $currentPage = 1): array
    {
        return [
            'data' => array_map(fn (array $t, int $i): array => [
                'id' => $i + 1,
                'reference' => 'REF-'.($i + 1),
                'external_id' => strtoupper(substr(md5((string) $i), 0, 8)),
                'name' => 'Participant',
                'created_at' => '2026-05-04T22:37:56+00:00',
                'event' => ['data' => ['code' => 'test-event', 'name' => 'Test']],
                'type' => ['data' => ['id' => 1, 'name' => 'Participant']],
                'provider' => ['data' => ['id' => 1, 'code' => 'internal', 'name' => 'Internal']],
                'user' => ['data' => [
                    'id' => $i + 1,
                    'nickname' => 'User'.($i + 1),
                    'name' => 'User '.($i + 1),
                    'email' => $t['email'],
                ]],
                'seat' => ['data' => [
                    'id' => $i + 1,
                    'label' => $t['seat_label'],
                    'row' => substr($t['seat_label'], 0, 1),
                    'number' => (int) substr($t['seat_label'], 1),
                ]],
            ], $tickets, array_keys($tickets)),
            'meta' => ['pagination' => [
                'total' => count($tickets),
                'count' => count($tickets),
                'per_page' => 20,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'links' => (object) [],
            ]],
        ];
    }

    private function emptyResponse(int $totalPages = 1): array
    {
        return $this->fakeTicketsResponse([], $totalPages);
    }

    private function configureIntegration(): void
    {
        IntegrationConfig::setValue('seatpicker', 'enabled', '1');
        IntegrationConfig::setValue('seatpicker', 'endpoint', 'https://control.example.com');
        IntegrationConfig::setValue('seatpicker', 'api_key', 'test-api-key', true);
        IntegrationConfig::setValue('seatpicker', 'event_code', 'test-event');
    }

    // -------------------------------------------------------
    // Config & auth
    // -------------------------------------------------------

    public function test_reads_api_key_from_integration_config(): void
    {
        $this->configureIntegration();

        Http::fake(['*' => Http::response($this->emptyResponse(), 200)]);

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
    // Ticket sync
    // -------------------------------------------------------

    public function test_syncs_seat_assignment_to_user_parameter(): void
    {
        $this->configureIntegration();
        $user = User::factory()->create(['email' => 'jess@example.com']);

        Http::fake(['*' => Http::response($this->fakeTicketsResponse([
            ['email' => 'jess@example.com', 'seat_label' => 'A1'],
        ]), 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Synced 1 seat', $result->message);
        $this->assertSame('A1', UserParameter::where('user_id', $user->id)->where('key', 'seat')->first()?->value);
    }

    public function test_skips_tickets_without_seat(): void
    {
        $this->configureIntegration();
        User::factory()->create(['email' => 'unseated@example.com']);

        $response = $this->emptyResponse();
        $response['data'] = [[
            'id' => 1,
            'user' => ['data' => ['id' => 1, 'email' => 'unseated@example.com']],
            'seat' => null,
        ]];

        Http::fake(['*' => Http::response($response, 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Synced 0 seat', $result->message);
    }

    public function test_skips_tickets_for_unknown_users(): void
    {
        $this->configureIntegration();

        Http::fake(['*' => Http::response($this->fakeTicketsResponse([
            ['email' => 'stranger@example.com', 'seat_label' => 'B2'],
        ]), 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Synced 0 seat', $result->message);
    }

    // -------------------------------------------------------
    // Pagination
    // -------------------------------------------------------

    public function test_paginates_through_multiple_pages(): void
    {
        $this->configureIntegration();
        $user1 = User::factory()->create(['email' => 'page1@example.com']);
        $user2 = User::factory()->create(['email' => 'page2@example.com']);

        Http::fakeSequence()
            ->push($this->fakeTicketsResponse(
                [['email' => 'page1@example.com', 'seat_label' => 'A1']],
                totalPages: 2,
                currentPage: 1,
            ), 200)
            ->push($this->fakeTicketsResponse(
                [['email' => 'page2@example.com', 'seat_label' => 'B1']],
                totalPages: 2,
                currentPage: 2,
            ), 200);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Synced 2 seat', $result->message);
        $this->assertSame('A1', UserParameter::where('user_id', $user1->id)->where('key', 'seat')->first()?->value);
        $this->assertSame('B1', UserParameter::where('user_id', $user2->id)->where('key', 'seat')->first()?->value);
    }

    // -------------------------------------------------------
    // verify_ssl support
    // -------------------------------------------------------

    public function test_syncs_with_verify_ssl_disabled(): void
    {
        $this->configureIntegration();
        IntegrationConfig::setValue('seatpicker', 'verify_ssl', '0');

        Http::fake(['*' => Http::response($this->emptyResponse(), 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
    }

    public function test_syncs_with_verify_ssl_defaulting_to_true(): void
    {
        $this->configureIntegration();

        Http::fake(['*' => Http::response($this->emptyResponse(), 200)]);

        $result = $this->service->sync();

        $this->assertTrue($result->success);
    }

    // -------------------------------------------------------
    // Error message detail
    // -------------------------------------------------------

    public function test_error_message_includes_url_and_response_body(): void
    {
        $this->configureIntegration();

        Http::fake(['*' => Http::response('{"message":"Not Found"}', 404)]);

        $result = $this->service->sync();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('404', $result->message);
        $this->assertStringContainsString('/api/v1/tickets', $result->message);
        $this->assertStringContainsString('Not Found', $result->message);
    }

    public function test_error_message_includes_url_on_server_error(): void
    {
        $this->configureIntegration();
        IntegrationConfig::setValue('seatpicker', 'event_code', 'my-lan');

        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $result = $this->service->sync();

        $this->assertFalse($result->success);
        $this->assertStringContainsString('500', $result->message);
        $this->assertStringContainsString('/api/v1/tickets', $result->message);
    }

    // -------------------------------------------------------
    // Query parameter
    // -------------------------------------------------------

    public function test_sends_event_code_as_query_parameter(): void
    {
        $this->configureIntegration();
        IntegrationConfig::setValue('seatpicker', 'event_code', 'my-lan');

        Http::fake(['*' => Http::response($this->emptyResponse(), 200)]);

        $this->service->sync();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v1/tickets')
            && str_contains($request->url(), 'event=my-lan'));
    }
}
